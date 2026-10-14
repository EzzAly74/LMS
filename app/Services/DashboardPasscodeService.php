<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\CourseSession;
use App\Services\Mobile\MobileSettings;
use App\Services\Mobile\SessionPasscodeService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Composes the instructor-dashboard "Passcode" widget view-model
 * (Figma node 515:34995 / 515:37969 / 515:35489).
 *
 * The dashboard never needs a session id up-front — it resolves the
 * "current" session itself:
 *
 *   • a session that already carries an ACTIVE passcode  → state "live"
 *   • a session whose passcode was issued today but has
 *     since expired                                       → state "ended"
 *   • a session that is live-now by schedule (no code yet) → state "idle"
 *   • nothing relevant today                               → state "idle"
 *
 * "Generate Passcode" then targets the live-now session (or the
 * earliest session scheduled for today as a fallback) and delegates
 * the actual code generation to the existing SessionPasscodeService so
 * the admin dashboard and the mobile S-06 flow stay in lock-step.
 *
 * This is an ADDITIVE service — it never touches MVC and reuses the
 * same attendance-window buffers as MyLearningService::liveSessionFor()
 * so "Live Now" on mobile and "Generate Passcode" here agree.
 */
final class DashboardPasscodeService
{
    public function __construct(
        private readonly MobileSettings $settings,
        private readonly SessionPasscodeService $passcodes,
    ) {}

    /**
     * Read-only widget state for the dashboard header, scoped to the
     * sessions the logged-in instructor actually teaches.
     *
     * @param  array<int, int>|null  $courseIds  Courses the instructor
     *         teaches. `null` = the viewer is not an instructor (or
     *         teaches nothing) → the widget is unavailable and hidden.
     * @return array<string, mixed>
     */
    public function currentState(?array $courseIds): array
    {
        $length = $this->settings->attendancePasscodeLength();

        // Not an instructor (or teaches nothing) → widget hidden.
        if (empty($courseIds)) {
            return [
                'available'       => false,
                'state'           => 'idle',
                'passcode_length' => $length,
                'rotates'         => ! $this->settings->passcodeStaticForSession(),
                'reset_seconds'   => $this->settings->passcodeResetSeconds(),
                'session'         => null,
                'passcode'        => null,
            ];
        }

        $now = now();

        // 1. A session the instructor already started today (it carries a
        //    passcode) whose attendance window is still open → "live".
        //
        //    There is NO "session ended" state: a started session stays
        //    live for its whole window. If the rotating code has lapsed we
        //    silently re-issue a fresh one here so a valid code always
        //    shows with zero user action (the dashboard then keeps it
        //    rotating). The only way to stop it is the "End Session" button.
        $startedLive = $this->startedLiveSession($now, $courseIds);

        if ($startedLive) {
            $expiresAt = $startedLive->passcode_expires_at !== null
                ? Carbon::parse((string) $startedLive->passcode_expires_at)
                : null;

            // Re-issue when the code has lapsed, or — in rotating mode — when
            // its remaining validity is far longer than the reset interval
            // (i.e. it was issued under a different policy, e.g. a static
            // session before the platform was switched to rotating). This
            // self-heals the live session so it actually rotates without the
            // instructor having to restart it.
            $needsReissue = $expiresAt === null || $expiresAt->lte($now);

            if (! $needsReissue && ! $this->settings->passcodeStaticForSession()) {
                $rotationCeiling = $now->copy()->addSeconds($this->settings->passcodeResetSeconds() + 5);
                $needsReissue    = $expiresAt->gt($rotationCeiling);
            }

            return $needsReissue
                // A self-heal, not a person's action: not audited (NEW2B-6098).
                ? $this->rotateLocked($startedLive, false, false)
                : $this->compose($startedLive, 'live', $length);
        }

        // 2. A session that is live-now by schedule but was never started
        //    (no passcode yet) → "idle" (the "Generate Passcode" affordance).
        $live = $this->liveNowSession($now, $courseIds);
        if ($live) {
            return $this->compose($live, 'idle', $length);
        }

        // 3. Instructor with no live session right now — widget is shown
        //    but the "Generate" button is disabled client-side.
        return [
            'available'       => true,
            'state'           => 'idle',
            'passcode_length' => $length,
            'rotates'         => ! $this->settings->passcodeStaticForSession(),
            'reset_seconds'   => $this->settings->passcodeResetSeconds(),
            'session'         => null,
            'passcode'        => null,
        ];
    }

    /**
     * Courses the instructor can start a session for, each with its
     * still-runnable cohorts. Feeds the dashboard course/cohort pickers.
     *
     * A course is excluded only when ALL of its cohorts have ended
     * (every `end_date < today`). Cohorts with no `end_date` are treated
     * as open-ended and always selectable.
     *
     * @param  array<int, int>|null  $courseIds  Courses the instructor teaches.
     * @return array<int, array<string, mixed>>
     */
    public function eligibleCourses(?array $courseIds): array
    {
        if (empty($courseIds)) {
            return [];
        }

        $today  = now()->toDateString();
        $locale = app()->getLocale();

        return Course::query()
            ->whereIn('id', $courseIds)
            ->with(['sections' => function ($q) use ($today) {
                // Only cohorts that have not ended yet are selectable.
                $q->where(function ($q2) use ($today) {
                    $q2->whereNull('end_date')
                       ->orWhereDate('end_date', '>=', $today);
                })
                ->orderBy('start_date')
                ->orderBy('id');
            }])
            ->orderBy('id')
            ->get()
            // "all_ended" exclusion: drop courses left with no runnable cohort.
            ->filter(fn (Course $c) => $c->sections->isNotEmpty())
            ->map(fn (Course $c) => [
                'id'      => (int) $c->id,
                'title'   => $c->getTranslation('title', $locale),
                'cohorts' => $c->sections->map(fn (CourseSection $s) => [
                    'id'         => (int) $s->id,
                    'name'       => $s->getTranslation('name', $locale),
                    'start_date' => $s->start_date ? $s->start_date->format('Y-m-d') : null,
                    'end_date'   => $s->end_date ? $s->end_date->format('Y-m-d') : null,
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Validate that a cohort belongs to the given course and is still
     * runnable (not ended). Returns the cohort, or null when it is not a
     * legitimate target for a new session.
     */
    public function findEligibleCohort(int $courseId, int $cohortId): ?CourseSection
    {
        $today = now()->toDateString();

        return CourseSection::query()
            ->where('id', $cohortId)
            ->where('course_id', $courseId)
            ->where(function ($q) use ($today) {
                $q->whereNull('end_date')
                  ->orWhereDate('end_date', '>=', $today);
            })
            ->first();
    }

    /** What "Generate Passcode" will do for a cohort (D-105). */
    public const PLAN_LIVE        = 'live';        // already started: show its code
    public const PLAN_ON_TIME     = 'on_time';     // a planned session is on now
    public const PLAN_EARLY       = 'early';       // today's next session, started before its time
    public const PLAN_LATE        = 'late';        // today's missed session, started after its time
    public const PLAN_MOVED       = 'moved';       // no session today: the next planned one runs now
    public const PLAN_UNSCHEDULED = 'unscheduled'; // the cohort has no schedule: a session is added
    public const PLAN_NONE        = 'none';        // every planned session is over

    /**
     * The session "Generate Passcode" would open for this cohort, and how
     * it relates to the schedule, so the instructor confirms before a
     * session starts at an unplanned time (D-105).
     *
     * @return array{case: string, session: array<string, mixed>|null}
     */
    public function plan(int $courseId, int $cohortId): array
    {
        [$case, $session] = $this->resolvePlan($courseId, $cohortId, now());

        return ['case' => $case, 'session' => $session ? $this->planSession($session) : null];
    }

    /**
     * Open attendance for the cohort's planned session (D-105). The schedule
     * is the source of truth: a session started early, late or on another
     * day is the planned one, moved to now, so the session count, hours and
     * numbering stay as planned. Only a cohort with no schedule gets a new
     * session. `$expectedSessionId` is the session the instructor confirmed;
     * if the plan changed meanwhile nothing starts and the new plan returns.
     *
     * @return array{state?: array<string, mixed>, error?: string, plan?: array<string, mixed>}
     */
    public function startForCohort(int $courseId, int $cohortId, ?int $expectedSessionId, ?int $length, ?Carbon $expiresAt): array
    {
        return DB::transaction(function () use ($courseId, $cohortId, $expectedSessionId, $length, $expiresAt) {
            // One start at a time per cohort (two tabs, two instructors).
            CourseSection::query()->whereKey($cohortId)->lockForUpdate()->first();

            $now = now();
            [$case, $session] = $this->resolvePlan($courseId, $cohortId, $now);
            $plan = ['case' => $case, 'session' => $session ? $this->planSession($session) : null];

            if ($case === self::PLAN_NONE) {
                return ['error' => 'none', 'plan' => $plan];
            }
            if ($expectedSessionId !== null && (int) ($session?->id ?? 0) !== $expectedSessionId) {
                return ['error' => 'changed', 'plan' => $plan];
            }

            if ($case === self::PLAN_LIVE) {
                $session->load(['section', 'course']);

                return ['state' => $this->compose($session, 'live', $this->settings->attendancePasscodeLength())];
            }

            if ($case === self::PLAN_UNSCHEDULED) {
                $session = $this->addSessionNow($courseId, $cohortId, $now);
            } elseif (in_array($case, [self::PLAN_EARLY, self::PLAN_LATE, self::PLAN_MOVED], true)) {
                $this->moveToNow($session, $cohortId, $now);
            }

            $session->forceFill(['started_at' => $session->started_at ?? $now])->save();
            $session->load(['section', 'course']);

            return ['state' => $this->issueFor($session, $length, $expiresAt)];
        });
    }

    /**
     * @return array{0: string, 1: CourseSession|null}
     */
    private function resolvePlan(int $courseId, int $cohortId, Carbon $now): array
    {
        /** @var Collection<int, CourseSession> $sessions */
        $sessions = CourseSession::query()
            ->where('course_id', $courseId)
            ->where('section_id', $cohortId)
            ->orderByRaw('session_date IS NULL')
            ->orderBy('session_date')
            ->orderBy('time_from')
            ->orderBy('id')
            ->get();

        if ($sessions->isEmpty()) {
            return [self::PLAN_UNSCHEDULED, null];
        }

        $today    = $now->toDateString();
        $openBuf  = $this->settings->attendanceSessionOpenBufferMinutes();
        $graceBuf = $this->settings->attendanceSessionGraceMinutes();
        $attended = DB::table('attendances')
            ->whereIn('session_id', $sessions->pluck('id')->all())
            ->distinct()
            ->pluck('session_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $notStarted = fn (CourseSession $s): bool => $s->started_at === null && ! in_array((int) $s->id, $attended, true);
        $isToday    = fn (CourseSession $s): bool => $s->session_date === null || substr((string) $s->session_date, 0, 10) === $today;
        $at         = fn (string $time): Carbon => Carbon::parse($today.' '.$time);

        $live = $sessions->first(fn (CourseSession $s) => $s->passcode !== null
            && $s->passcode_issued_at !== null
            && Carbon::parse((string) $s->passcode_issued_at)->isSameDay($now)
            && $isToday($s)
            && $this->windowContains($s, $now, $openBuf, $graceBuf));
        if ($live) {
            return [self::PLAN_LIVE, $live];
        }

        $todays = $sessions->filter($isToday);
        $onTime = $todays->first(fn (CourseSession $s) => $this->windowContains($s, $now, $openBuf, $graceBuf));
        if ($onTime) {
            return [self::PLAN_ON_TIME, $onTime];
        }

        $next = $todays->first(fn (CourseSession $s) => $s->time_from !== null
            && $at((string) $s->time_from)->gt($now) && $notStarted($s));
        $missed = $todays->filter(fn (CourseSession $s) => $s->time_to !== null
            && $at((string) $s->time_to)->lt($now) && $notStarted($s))->last();

        if ($next && $missed) {
            // Both a missed and an upcoming session today: the nearer one.
            $untilNext   = $now->diffInMinutes($at((string) $next->time_from));
            $sinceMissed = $at((string) $missed->time_to)->diffInMinutes($now);

            return $sinceMissed <= $untilNext ? [self::PLAN_LATE, $missed] : [self::PLAN_EARLY, $next];
        }
        if ($next) {
            return [self::PLAN_EARLY, $next];
        }
        if ($missed) {
            return [self::PLAN_LATE, $missed];
        }

        $upcoming = $sessions->first(fn (CourseSession $s) => $s->session_date !== null
            && substr((string) $s->session_date, 0, 10) > $today && $notStarted($s));

        return $upcoming ? [self::PLAN_MOVED, $upcoming] : [self::PLAN_NONE, null];
    }

    /** The session's attendance window (start - open buffer .. end + grace) holds now; untimed sessions hold all day. */
    private function windowContains(CourseSession $s, Carbon $now, int $openBuf, int $graceBuf): bool
    {
        if ($s->time_from === null && $s->time_to === null) {
            return true;
        }
        if ($s->time_from === null || $s->time_to === null) {
            return false;
        }
        $day = $now->toDateString();

        return Carbon::parse($day.' '.$s->time_from)->subMinutes($openBuf)->lte($now)
            && Carbon::parse($day.' '.$s->time_to)->addMinutes($graceBuf)->gte($now);
    }

    /**
     * Run a planned session now: today, from now, for its planned length.
     * The planned slot is kept so the schedule sheet still recognises it.
     */
    private function moveToNow(CourseSession $session, int $cohortId, Carbon $now): void
    {
        $minutes = null;
        if ($session->time_from !== null && $session->time_to !== null) {
            $minutes = Carbon::parse('2000-01-01 '.$session->time_from)->diffInMinutes(Carbon::parse('2000-01-01 '.$session->time_to));
        }
        $end = $now->copy()->addMinutes(max(1, (int) ($minutes ?: $this->defaultSessionMinutes($cohortId))));

        $session->forceFill([
            'planned_date'      => $session->planned_date ?? $session->session_date,
            'planned_time_from' => $session->planned_time_from ?? $session->time_from,
            'planned_time_to'   => $session->planned_time_to ?? $session->time_to,
            'session_date'      => $now->toDateString(),
            'time_from'         => $now->format('H:i:s'),
            // A session stays within its day (time-only columns).
            'time_to'           => $end->isSameDay($now) ? $end->format('H:i:s') : '23:59:59',
        ]);
    }

    /** A cohort without a schedule: a session for today, from now (the earlier behaviour). */
    private function addSessionNow(int $courseId, int $cohortId, Carbon $now): CourseSession
    {
        $end = $now->copy()->addMinutes($this->defaultSessionMinutes($cohortId));

        return CourseSession::create([
            'course_id'    => $courseId,
            'section_id'   => $cohortId,
            'title'        => __('messages.passcode.session_title', ['date' => $now->format('d M Y')]),
            'session_date' => $now->toDateString(),
            'time_from'    => $now->format('H:i:s'),
            'time_to'      => $end->isSameDay($now) ? $end->format('H:i:s') : '23:59:59',
        ]);
    }

    /** The cohort's "Avg. Session Time" (hours) when set, otherwise the platform attendance window. */
    private function defaultSessionMinutes(int $cohortId): int
    {
        $avgHours = CourseSection::query()->whereKey($cohortId)->value('avg_session_time');

        return max(1, $avgHours !== null && (float) $avgHours > 0
            ? (int) round((float) $avgHours * 60)
            : $this->settings->attendanceWindowMinutes());
    }

    /** @return array<string, mixed> */
    private function planSession(CourseSession $s): array
    {
        return [
            'id'        => (int) $s->id,
            'number'    => $this->sessionOrdinal($s),
            'title'     => \App\Support\SessionTitle::localize($s->title),
            'date'      => $s->session_date ? substr((string) $s->session_date, 0, 10) : null,
            'time_from' => $this->formatTime($s->time_from),
            'time_to'   => $this->formatTime($s->time_to),
        ];
    }

    /**
     * Rotate the passcode on the instructor's currently-live session and
     * return the refreshed "live" widget state. Powers the dashboard
     * "Regenerate" button and the rotating-passcode auto-refresh.
     *
     * Only a session whose attendance window is still open is a valid
     * target — re-issuing a code on a finished session would be useless
     * (the mobile S-06 lookup also enforces the time window). When nothing
     * is live we just return the read-only current state (ended / idle),
     * which lets the dashboard fall back to the "Start a Session" picker.
     *
     * @param  array<int, int>|null  $courseIds
     * @return array<string, mixed>
     */
    public function regenerateCurrent(?array $courseIds, bool $auto = false): array
    {
        if (empty($courseIds)) {
            return $this->currentState($courseIds);
        }

        // Only a session the instructor started is rotated; a scheduled
        // session gets its first code through "Generate" (D-105).
        $session = $this->startedLiveSession(now(), $courseIds);

        if ($session === null) {
            // Nothing live to rotate — surface the plain current state so
            // the widget reflects reality (and offers a fresh session).
            return $this->currentState($courseIds);
        }

        // The widget's automatic rotation is not audited; Regenerate is (NEW2B-6098).
        return $this->rotateLocked($session, $auto, ! $auto);
    }

    /**
     * Re-issue under a row lock (D-017): when another tab or instructor
     * rotated the code meanwhile, theirs is kept and returned, so learners
     * are not handed a code that is replaced a moment later. An automatic
     * rotation also keeps a code that is still valid on the server clock.
     *
     * @return array<string, mixed>
     */
    private function rotateLocked(CourseSession $seen, bool $auto, bool $audited): array
    {
        return DB::transaction(function () use ($seen, $auto, $audited) {
            $locked = CourseSession::query()->with(['section', 'course'])->lockForUpdate()->find($seen->id);
            if ($locked === null) {
                return $this->compose($seen, 'live', $this->settings->attendancePasscodeLength());
            }

            $rotatedMeanwhile = (string) $locked->passcode_issued_at !== (string) $seen->passcode_issued_at;
            $stillValid = $locked->passcode !== null && $locked->passcode_expires_at !== null
                && Carbon::parse((string) $locked->passcode_expires_at)->gt(now()->addSeconds(2));

            if ($locked->passcode !== null && ($rotatedMeanwhile || ($auto && $stillValid))) {
                return $this->compose($locked, 'live', $this->settings->attendancePasscodeLength());
            }

            return $this->issueFor($locked, null, null, $audited);
        });
    }

    /**
     * End the instructor's current live session: revoke its passcode and
     * close the attendance window now, so it stops being "live" and the
     * rotating-passcode auto-refresh halts. Returns the refreshed (idle)
     * widget state. Powers the dashboard "End Session" button.
     *
     * @param  array<int, int>|null  $courseIds
     * @return array<string, mixed>
     */
    public function endCurrent(?array $courseIds): array
    {
        if (empty($courseIds)) {
            return $this->currentState($courseIds);
        }

        $now     = now();
        $session = $this->startedLiveSession($now, $courseIds);

        // Fall back to a session that still carries a code today even if
        // its scheduled window has already drifted shut.
        if ($session === null) {
            $session = CourseSession::query()
                ->with(['section', 'course'])
                ->whereIn('course_id', $courseIds)
                ->whereNotNull('passcode')
                ->whereDate('passcode_issued_at', $now->toDateString())
                ->orderByDesc('passcode_issued_at')
                ->first();
        }

        if ($session !== null) {
            // Closing attendance drops the code; the session keeps its times,
            // so a planned session is never shortened or moved (D-105).
            // Learners need the code to mark, so attendance stops at once.
            $session->forceFill([
                'passcode'                  => null,
                'passcode_issued_at'        => null,
                'passcode_expires_at'       => null,
                'passcode_previous'         => null,
                'passcode_previous_until'   => null,
                'attendance_window_minutes' => null,
            ])->save();
        }

        return $this->currentState($courseIds);
    }

    /**
     * Issue (or rotate) a passcode for the resolved session and return
     * the freshly-composed "live" widget state for the modal.
     *
     * @return array<string, mixed>
     */
    public function issueFor(CourseSession $session, ?int $length, ?Carbon $expiresAt, bool $audited = true): array
    {
        $fresh = $this->passcodes->issue($session, $length, $expiresAt, $audited);

        // Re-attach the relations the freshly-pulled model lost so
        // compose() doesn't fire two extra lazy queries.
        $fresh->setRelation('section', $session->section);
        $fresh->setRelation('course', $session->course);

        return $this->compose($fresh, 'live', $this->settings->attendancePasscodeLength());
    }

    // ────────────────────────────────────────────────────────────
    // Internals
    // ────────────────────────────────────────────────────────────

    /**
     * Session whose attendance window is open right now. Mirrors the
     * predicate in MyLearningService::liveSessionFor() but is not
     * scoped to a single learner/course (the dashboard is global).
     */
    /**
     * The session the instructor already started today — it carries a
     * passcode and its attendance window is still open. This is what makes
     * a session "live" regardless of whether the current rotating code
     * instance has lapsed (the dashboard re-issues a fresh one). Returns
     * the most recently-started such session.
     */
    private function startedLiveSession(Carbon $now, array $courseIds): ?CourseSession
    {
        $openBuf  = $this->settings->attendanceSessionOpenBufferMinutes();
        $graceBuf = $this->settings->attendanceSessionGraceMinutes();

        return CourseSession::query()
            ->with(['section', 'course'])
            ->whereIn('course_id', $courseIds)
            ->whereNotNull('passcode')
            ->whereDate('passcode_issued_at', $now->toDateString())
            ->where(function ($q) use ($now) {
                $q->whereNull('session_date')
                    ->orWhereDate('session_date', $now->toDateString());
            })
            ->where(function ($q) use ($now, $openBuf, $graceBuf) {
                $q->where(function ($q2) {
                    $q2->whereNull('time_from')->whereNull('time_to');
                })->orWhere(function ($q2) use ($now, $openBuf, $graceBuf) {
                    $q2->whereRaw(
                        'time_from <= ADDTIME(?, SEC_TO_TIME(? * 60))',
                        [$now->format('H:i:s'), $openBuf],
                    )->whereRaw(
                        'time_to >= SUBTIME(?, SEC_TO_TIME(? * 60))',
                        [$now->format('H:i:s'), $graceBuf],
                    );
                });
            })
            ->orderByDesc('passcode_issued_at')
            ->first();
    }

    private function liveNowSession(Carbon $now, array $courseIds): ?CourseSession
    {
        $openBuf  = $this->settings->attendanceSessionOpenBufferMinutes();
        $graceBuf = $this->settings->attendanceSessionGraceMinutes();

        return CourseSession::query()
            ->with(['section', 'course'])
            ->whereIn('course_id', $courseIds)
            ->where(function ($q) use ($now) {
                $q->whereNull('session_date')
                    ->orWhereDate('session_date', $now->toDateString());
            })
            ->where(function ($q) use ($now, $openBuf, $graceBuf) {
                $q->where(function ($q2) {
                    $q2->whereNull('time_from')->whereNull('time_to');
                })->orWhere(function ($q2) use ($now, $openBuf, $graceBuf) {
                    // `time_from`/`time_to` are TIME columns; TIMESTAMPDIFF on
                    // time-only values yields NULL, so compare on the clock
                    // directly (mirrors MyLearningService::liveSessionFor()).
                    $q2->whereRaw(
                        'time_from <= ADDTIME(?, SEC_TO_TIME(? * 60))',
                        [$now->format('H:i:s'), $openBuf],
                    )->whereRaw(
                        'time_to >= SUBTIME(?, SEC_TO_TIME(? * 60))',
                        [$now->format('H:i:s'), $graceBuf],
                    );
                });
            })
            ->orderBy('time_from')
            ->orderBy('id')
            ->first();
    }

    /**
     * 1-based position of the session inside its cohort, ordered the
     * way a human reads a syllabus (date → start time → id). Drives the
     * "Session: 3" line in the Live Passcode modal.
     */
    private function sessionOrdinal(CourseSession $session): int
    {
        if ($session->section_id === null) {
            return 1;
        }

        $ids = CourseSession::query()
            ->where('section_id', $session->section_id)
            ->orderByRaw('session_date IS NULL')
            ->orderBy('session_date')
            ->orderBy('time_from')
            ->orderBy('id')
            ->pluck('id');

        $index = $ids->search($session->id);

        return $index === false ? 1 : ((int) $index + 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function compose(CourseSession $session, string $state, int $length): array
    {
        $locale  = app()->getLocale();
        $expires = $session->passcode_expires_at
            ? Carbon::parse((string) $session->passcode_expires_at)
            : null;

        $passcode = $session->passcode
            ? [
                'code'       => (string) $session->passcode,
                'issued_at'  => $session->passcode_issued_at
                    ? Carbon::parse((string) $session->passcode_issued_at)->toIso8601String()
                    : null,
                'expires_at' => $expires?->toIso8601String(),
                'expired'    => $expires ? $expires->lte(now()) : false,
            ]
            : null;

        return [
            'available'       => true,
            'state'           => $state,
            'passcode_length' => $length,
            // Tell the dashboard whether the code rotates so it can show a
            // live countdown + auto re-issue a fresh code, or keep it static
            // for the whole session.
            'rotates'         => ! $this->settings->passcodeStaticForSession(),
            'reset_seconds'   => $this->settings->passcodeResetSeconds(),
            'session'         => [
                'id'           => (int) $session->id,
                'number'       => $this->sessionOrdinal($session),
                'title'        => \App\Support\SessionTitle::localize($session->title),
                'date'         => $session->session_date
                    ? Carbon::parse((string) $session->session_date)->toDateString()
                    : null,
                'time_from'    => $this->formatTime($session->time_from),
                'time_to'      => $this->formatTime($session->time_to),
                'course_id'    => (int) $session->course_id,
                'course_title' => $session->course?->getTranslation('title', $locale),
                'cohort_id'    => $session->section_id !== null ? (int) $session->section_id : null,
                'cohort_name'  => $session->section?->getTranslation('name', $locale),
            ],
            'passcode'        => $passcode,
        ];
    }

    private function formatTime(?string $time): ?string
    {
        if ($time === null || $time === '') {
            return null;
        }

        return substr($time, 0, 5);
    }
}
