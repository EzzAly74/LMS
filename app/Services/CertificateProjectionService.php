<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Course;
use App\Models\CourseExam;
use App\Models\CourseSection;
use App\Models\CourseSession;
use App\Models\User;
use App\Models\UserCertificate;
use App\Models\UserExam;
use App\Models\UsersCourse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Measures a learner against the certificate rule, and projects whether they
 * are on track to earn a course's certificate BEFORE it is actually issued —
 * the persistent "Certificate: On track" header badge needs this mid-course,
 * not just at completion.
 *
 * This service owns MEASUREMENT only. The rule itself — which metrics matter
 * and at what threshold — belongs to {@see CertificatePolicy}, which reads it
 * from Platform Config. Previously the rule was duplicated here against the
 * per-course `certificate_mode` / `certificate_*_threshold` columns, which no
 * API could set and which disagreed with the values the admin screen was
 * saving; those columns are no longer consulted.
 *
 * Everything that decides or displays certificate eligibility routes through
 * this pair, so there is exactly one place to change the rule.
 */
class CertificateProjectionService
{
    public const STATUS_EARNED   = 'earned';
    public const STATUS_ON_TRACK = 'on_track';
    public const STATUS_AT_RISK  = 'at_risk';
    public const STATUS_BLOCKED  = 'blocked';

    public function __construct(private readonly CertificatePolicy $policy) {}

    public function projectForCourse(User $user, Course $course): array
    {
        $hasCertificate = $this->hasActiveCertificate($user, $course);
        $attendancePercent = $this->attendancePercent($user, $course);
        $scorePercent = $this->scorePercent($user, $course);
        $courseEnded = $this->isCourseEnded($user, $course);

        $cohortId = UsersCourse::where('user_id', $user->id)->where('course_id', $course->id)->value('group_id');
        $cohort = $cohortId ? CourseSection::find($cohortId) : null;
        $held = $cohortId ? CourseSession::where('section_id', $cohortId)->ended()->count() : 0;
        $attended = Attendance::where('user_id', $user->id)->where('course_id', $course->id)->whereNotNull('session_id')
            ->distinct('session_id')->count('session_id');
        $onPace = $this->attendanceOnPace($course, $cohort, $attended, $held);

        return $this->decide($course, $hasCertificate, $attendancePercent, $scorePercent, $courseEnded, $onPace);
    }

    /**
     * Batch variant of projectForCourse — resolves certificates, cohort
     * enrolment, final-exam attempts, and attendance for the WHOLE course
     * list in a handful of queries instead of per-course, so the `my/learnings`
     * dashboard composite (#4) doesn't N+1 across a learner's course list.
     *
     * @param  Collection<int, Course>  $courses
     * @return array<int, array<string, mixed>>  keyed by course_id
     */
    public function projectForCourses(User $user, Collection $courses): array
    {
        $courses = $courses->keyBy('id');
        $courseIds = $courses->keys()->all();

        if (empty($courseIds)) {
            return [];
        }

        $certifiedCourseIds = UserCertificate::query()
            ->where('user_id', $user->id)
            ->whereIn('course_id', $courseIds)
            ->active()
            ->pluck('course_id')
            ->all();

        $cohortIdsByCourse = UsersCourse::where('user_id', $user->id)
            ->whereIn('course_id', $courseIds)
            ->whereNotNull('group_id')
            ->pluck('group_id', 'course_id');

        $cohortIds = $cohortIdsByCourse->values()->unique()->all();
        $cohorts = CourseSection::whereIn('id', $cohortIds)->get()->keyBy('id');

        $heldSessionsByCohort = CourseSession::whereIn('section_id', $cohortIds)
            ->ended()
            ->selectRaw('section_id, COUNT(*) as held')
            ->groupBy('section_id')
            ->pluck('held', 'section_id');

        $finalExamsByCourse = CourseExam::whereIn('course_id', $courseIds)
            ->where('is_final', true)
            ->get()
            ->keyBy('course_id');

        $examIds = $finalExamsByCourse->pluck('id')->all();

        $attemptsByExam = UserExam::where('user_id', $user->id)
            ->whereIn('exam_id', $examIds)
            ->whereRaw(\App\Support\CourseCompletion::submittedExamSql('user_exams'))
            ->get()
            ->keyBy('exam_id');

        $distinctSessionAttendance = Attendance::where('user_id', $user->id)
            ->whereIn('course_id', $courseIds)
            ->whereNotNull('session_id')
            ->selectRaw('course_id, COUNT(DISTINCT session_id) as attended')
            ->groupBy('course_id')
            ->pluck('attended', 'course_id');

        $rawRowAttendance = Attendance::where('user_id', $user->id)
            ->whereIn('course_id', $courseIds)
            ->selectRaw('course_id, COUNT(*) as attended')
            ->groupBy('course_id')
            ->pluck('attended', 'course_id');

        $results = [];

        foreach ($courses as $courseId => $course) {
            $hasCertificate = in_array($courseId, $certifiedCourseIds, true);
            $cohortId = $cohortIdsByCourse->get($courseId);
            $cohort = $cohortId ? $cohorts->get($cohortId) : null;

            $attendancePercent = $this->computeAttendancePercent(
                $course,
                $cohort,
                (int) ($distinctSessionAttendance->get($courseId) ?? 0),
                (int) ($rawRowAttendance->get($courseId) ?? 0),
            );

            $exam = $finalExamsByCourse->get($courseId);
            $attempt = $exam ? $attemptsByExam->get($exam->id) : null;
            $scorePercent = $this->computeScorePercent($exam, $attempt);

            $courseEnded = $this->computeCourseEnded(
                $course,
                $cohort,
                $cohortId ? (int) ($heldSessionsByCohort->get($cohortId) ?? 0) : 0,
            );

            $onPace = $this->attendanceOnPace(
                $course,
                $cohort,
                (int) ($distinctSessionAttendance->get($courseId) ?? 0),
                $cohortId ? (int) ($heldSessionsByCohort->get($cohortId) ?? 0) : 0,
            );

            $results[$courseId] = $this->decide($course, $hasCertificate, $attendancePercent, $scorePercent, $courseEnded, $onPace);
        }

        return $results;
    }

    /**
     * Does the learner MEET the certificate rule right now, on the strength
     * of measured evidence alone?
     *
     * Unlike {@see projectForCourse}, this ignores whether a certificate has
     * already been issued, so it can drive actual issuance without the earned
     * short-circuit making it always-true once a cert exists.
     *
     * One rule for every issuing path (content, final exam, evaluation,
     * attendance; D-084, human 2026-10-11): each metric the Platform or course
     * rule requires must be measured and meet its threshold. A metric the
     * course cannot produce (attendance on a course with no sessions) blocks
     * the certificate rather than being skipped, so such a course needs a
     * per-course rule that only asks for what it can measure.
     */
    public function meetsIssuanceCriteria(User $user, Course $course): bool
    {
        return $this->policy->forCourse($course)->isFullyMet(
            $this->attendancePercent($user, $course),
            $this->scorePercent($user, $course),
        );
    }

    /**
     * The check the completion events (final exam passed, evaluation
     * submitted) run before issuing. Since D-084 it is the same strict rule as
     * {@see meetsIssuanceCriteria}: the event alone no longer stands in for an
     * unmeasured metric.
     */
    public function satisfiesPolicy(User $user, Course $course): bool
    {
        return $this->meetsIssuanceCriteria($user, $course);
    }

    /* ------------------------------------------------------------------ *
     |  SHARED DECISION LOGIC                                             |
     * ------------------------------------------------------------------ */

    private function decide(Course $course, bool $hasCertificate, ?int $attendancePercent, ?int $scorePercent, bool $courseEnded, ?bool $attendanceOnPace = null): array
    {
        if ($hasCertificate) {
            return array_merge($this->baseShape($course), ['status' => self::STATUS_EARNED]);
        }

        // Unmeasurable metrics come back as null and are skipped, so they
        // never push a learner into at_risk for something their course
        // simply cannot report.
        $checks = $this->policy->forCourse($course)->checks($attendancePercent, $scorePercent);

        $failing = array_keys(array_filter($checks, fn ($meets) => $meets === false));

        // While the cohort runs, attendance is judged on the sessions held so
        // far, not the whole course (D-091): before the first session, or
        // while attending every session, the learner is on track, not at
        // risk for sessions that have not happened yet.
        if (! $courseEnded && $attendanceOnPace === true) {
            $failing = array_values(array_diff($failing, ['attendance']));
        }

        $status = self::STATUS_ON_TRACK;
        $blockedReason = null;

        if (!empty($failing)) {
            $status = $courseEnded ? self::STATUS_BLOCKED : self::STATUS_AT_RISK;
            $blockedReason = count($failing) > 1 ? 'both' : $failing[0];
        }

        $message = null;
        if ($status === self::STATUS_BLOCKED) {
            $message = match ($blockedReason) {
                'attendance' => __('messages.certificate_status.blocked_attendance'),
                'score' => __('messages.certificate_status.blocked_score'),
                default => __('messages.certificate_status.blocked_both'),
            };
        }

        return array_merge($this->ruleShape($course), [
            'status' => $status,
            'blocked_reason' => $status === self::STATUS_BLOCKED ? $blockedReason : null,
            'message' => $message,
            'attendance_percent' => $attendancePercent,
            'score_percent' => $scorePercent,
        ]);
    }

    /**
     * The configured rule, in the wire shape the learner apps already expect.
     *
     * `certificate_mode` keeps its name for contract stability (Angular and
     * mobile both read it) and carries the basis of the rule this course
     * follows: Platform Config's, or the course's own (D-058).
     *
     * @return array<string, mixed>
     */
    private function ruleShape(Course $course): array
    {
        $rule = $this->policy->forCourse($course);

        return [
            'certificate_mode'      => $rule->basis,
            'attendance_threshold'  => $rule->requires(CertificatePolicy::METRIC_ATTENDANCE)
                ? $rule->minAttendance
                : null,
            'score_threshold'       => $rule->requires(CertificatePolicy::METRIC_SCORE)
                ? $rule->minScore
                : null,
        ];
    }

    private function baseShape(Course $course): array
    {
        return array_merge($this->ruleShape($course), [
            'blocked_reason' => null,
            'message' => null,
            'attendance_percent' => null,
            'score_percent' => null,
        ]);
    }

    /* ------------------------------------------------------------------ *
     |  SINGLE-COURSE DATA RESOLUTION (projectForCourse)                  |
     * ------------------------------------------------------------------ */

    private function hasActiveCertificate(User $user, Course $course): bool
    {
        return UserCertificate::query()
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->active()
            ->exists();
    }

    /** % of the learner's cohort sessions attended, or null when unmeasurable. */
    private function attendancePercent(User $user, Course $course): ?int
    {
        $cohortId = UsersCourse::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->value('group_id');

        $cohort = $cohortId ? CourseSection::find($cohortId) : null;

        $distinct = Attendance::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->whereNotNull('session_id')
            ->distinct('session_id')
            ->count('session_id');

        $raw = $distinct === 0
            ? Attendance::where('user_id', $user->id)->where('course_id', $course->id)->count()
            : 0;

        return $this->computeAttendancePercent($course, $cohort, $distinct, $raw);
    }

    /** % of the course's final exam total score reached, or null when not yet attempted. */
    private function scorePercent(User $user, Course $course): ?int
    {
        $exam = CourseExam::where('course_id', $course->id)->where('is_final', true)->first();
        $attempt = $exam ? UserExam::where('user_id', $user->id)->where('exam_id', $exam->id)
            ->whereRaw(\App\Support\CourseCompletion::submittedExamSql('user_exams'))->first() : null;

        return $this->computeScorePercent($exam, $attempt);
    }

    /** Whether the learner's cohort has run its course (no more chances to improve). */
    private function isCourseEnded(User $user, Course $course): bool
    {
        $cohortId = UsersCourse::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->value('group_id');

        $cohort = $cohortId ? CourseSection::find($cohortId) : null;
        $held = $cohortId ? CourseSession::where('section_id', $cohortId)->ended()->count() : 0;

        return $this->computeCourseEnded($course, $cohort, $held);
    }

    /* ------------------------------------------------------------------ *
     |  PURE COMPUTATION (shared by both the single and batch paths)      |
     * ------------------------------------------------------------------ */

    private function computeAttendancePercent(Course $course, ?CourseSection $cohort, int $distinctSessionCount, int $rawRowCount): ?int
    {
        $target = $this->sessionTarget($course, $cohort);

        if (!$target || $target <= 0) {
            return null;
        }

        $attended = $distinctSessionCount > 0 ? $distinctSessionCount : $rawRowCount;

        return (int) min(100, round(($attended / $target) * 100));
    }

    /**
     * Is the learner keeping up with the attendance rule so far (D-091)?
     * True before any session is held, or when the sessions attended so far
     * meet the threshold and the rest can still bring the total up to it.
     * False when behind, or when even attending every remaining session
     * cannot reach it. Null when the rule does not ask for attendance or the
     * course has no session count.
     */
    private function attendanceOnPace(Course $course, ?CourseSection $cohort, int $attended, int $held): ?bool
    {
        $rule = $this->policy->forCourse($course);
        $target = (int) $this->sessionTarget($course, $cohort);
        if (! $rule->requires(CertificatePolicy::METRIC_ATTENDANCE) || $target <= 0) {
            return null;
        }

        $threshold = (float) $rule->minAttendance;
        $reachable = ($attended + max(0, $target - $held)) * 100 / $target;
        if ($reachable < $threshold) {
            return false;
        }

        return $held === 0 || $attended * 100 / $held >= $threshold;
    }

    /**
     * How many sessions the cohort has: its sheet's session rows, the same
     * count My Learnings, mobile and "ended" use (B-182); the stored planned
     * number only when the sheet has none.
     */
    private function sessionTarget(Course $course, ?CourseSection $cohort): ?int
    {
        $rows = $cohort !== null ? CourseSession::where('section_id', $cohort->id)->count() : 0;

        return $rows > 0 ? $rows : ($cohort?->number_of_sessions ?? $course->number_of_sessions);
    }

    /** The cohort's own session count (sheet rows, else its planned number): when it is over. */
    private function cohortSessionCount(CourseSection $cohort): ?int
    {
        $rows = CourseSession::where('section_id', $cohort->id)->count();

        return $rows > 0 ? $rows : $cohort->number_of_sessions;
    }

    private function computeScorePercent(?CourseExam $exam, ?UserExam $attempt): ?int
    {
        if (!$exam || !$attempt) {
            return null;
        }

        if ($attempt->max_score !== null && $attempt->max_score > 0) {
            return (int) round(($attempt->total_score / $attempt->max_score) * 100);
        }

        if ($exam->degree > 0) {
            return (int) round(($attempt->user_degree / $exam->degree) * 100);
        }

        return null;
    }

    private function computeCourseEnded(Course $course, ?CourseSection $cohort, int $heldSessions): bool
    {
        if ($cohort) {
            $start = $cohort->start_date instanceof Carbon ? $cohort->start_date : ($cohort->start_date ? Carbon::parse($cohort->start_date) : null);
            $end = $cohort->end_date instanceof Carbon ? $cohort->end_date : ($cohort->end_date ? Carbon::parse($cohort->end_date) : null);

            return Course::deriveCohortStatus($cohort->status, $start, $end, $this->cohortSessionCount($cohort), $heldSessions) === 'completed';
        }

        return $course->effectiveStatus() === 'inactive';
    }
}
