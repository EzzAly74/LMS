<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\Models\Course;
use App\Models\User;
use App\Repositories\Contracts\Mobile\MyLearningRepositoryInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Compose the S-05 view-models. Pure orchestration over the repo +
 * MobileSettings — never queries the DB directly.
 */
final class MyLearningService
{
    public function __construct(
        private readonly MyLearningRepositoryInterface $repository,
        private readonly MobileSettings $settings,
        private readonly QualificationProgressService $qualifications,
    ) {}

    public function activeCoursesPaginated(User $user): LengthAwarePaginator
    {
        return $this->repository->activeCoursesForUser(
            $user,
            $this->settings->myLearningActivePerPage(),
            $this->qualifications->finishedCourseIdsForUser((int) $user->id)->all(),
        );
    }

    public function activeCoursesPreview(User $user): EloquentCollection
    {
        return $this->repository->previewActiveCourses(
            $user,
            $this->settings->myLearningActivePreviewCount(),
            $this->qualifications->finishedCourseIdsForUser((int) $user->id)->all(),
        );
    }

    /**
     * Load what a page of active-course cards needs in a few queries instead
     * of a dozen per card: the certificate projection (the Website's batch,
     * with each course's own rule), the progress percent (the Website's
     * rule, D-089 / D-094), each cohort's session rows and the learner's
     * ratings. The card resource reads them from these attributes.
     *
     * @param  iterable<Course>  $courses
     */
    public function prepareCards(User $user, iterable $courses): void
    {
        $courses = collect($courses);
        if ($courses->isEmpty()) {
            return;
        }
        $ids = $courses->map(fn (Course $c) => (int) $c->id)->all();

        $projections = app(\App\Services\CertificateProjectionService::class)->projectForCourses($user, $courses);
        $percents = app(\App\Services\LectureProgressService::class)->getCourseProgressBatch((int) $user->id, $ids);

        $cohortIds = $courses->map(fn (Course $c) => $c->usersCourses->first()?->group_id)->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        $sessions = $cohortIds === [] ? collect() : \Illuminate\Support\Facades\DB::table('course_sessions')
            ->whereIn('section_id', $cohortIds)
            ->orderBy('session_date')->orderBy('time_from')->orderBy('id')
            ->get(['id', 'section_id', 'session_date', 'time_from', 'time_to'])
            ->groupBy('section_id');

        $ratings = \App\Models\CourseRating::query()
            ->where('user_id', $user->id)->whereIn('course_id', $ids)
            ->get(['course_id', 'rating'])->keyBy('course_id');

        foreach ($courses as $course) {
            $cohortId = (int) ($course->usersCourses->first()?->group_id ?? 0);
            $course->setAttribute('card_projection', $projections[(int) $course->id] ?? null);
            $course->setAttribute('card_progress', $percents[(int) $course->id] ?? null);
            $course->setAttribute('card_sessions', $sessions->get($cohortId, collect())->values());
            $course->setAttribute('card_rating', $ratings->has($course->id) ? (int) $ratings[$course->id]->rating : null);
        }
    }

    public function progressFor(User $user, Course $course, int $cohortId, string $locale): array
    {
        return $this->repository->courseProgressSummary(
            $user,
            $course->id,
            $cohortId,
            $locale,
        );
    }

    public function sessionsAttendance(User $user, int $courseId, int $cohortId): Collection
    {
        return $this->repository->sessionsAttendance($user, $courseId, $cohortId);
    }

    public function userRatingForCourse(User $user, int $courseId): ?object
    {
        return $this->repository->userRatingForCourse($user, $courseId);
    }

    /**
     * Sequence number + localized label of the session that is up next
     * for the cohort, or null when there's nothing upcoming.
     *
     * @return array{number: int, name: string}|null
     */
    public function nextSessionFor(User $user, int $courseId, int $cohortId, string $locale): ?array
    {
        return $this->repository->nextSessionFor($user, $courseId, $cohortId, $locale);
    }

    /**
     * Live-session detection driver for the S-05 card. Returns null
     * if no session is happening now, otherwise the smallest
     * relevant session row.
     *
     * The detection re-uses the same buffers as the passcode flow so
     * "Live Now" and "Mark Present" stay in lock-step.
     */
    public function liveSessionFor(User $user, int $courseId, int $cohortId, Carbon $now): ?object
    {
        $openBuf  = $this->settings->attendanceSessionOpenBufferMinutes();
        $graceBuf = $this->settings->attendanceSessionGraceMinutes();

        $startOfDay = $now->copy()->startOfDay();
        $nowTime    = $now->format('H:i:s');

        // One query per card: the session the instructor opened attendance
        // for today (it holds the code; it may run early, late or moved,
        // D-105) comes first, else the one the timetable says is on now.
        $started = fn ($s) => $s->whereNotNull('passcode')->where('passcode_issued_at', '>=', $startOfDay);
        $onNow = function ($w) use ($now, $nowTime, $openBuf, $graceBuf) {
            $w->where(fn ($q) => $q->whereNull('session_date')->orWhereDate('session_date', $now->toDateString()))
                ->where(function ($q) use ($nowTime, $openBuf, $graceBuf) {
                    $q->where(fn ($q2) => $q2->whereNull('time_from')->whereNull('time_to'))
                        // `time_from`/`time_to` are TIME columns; TIMESTAMPDIFF on
                        // time-only values yields NULL, so compare on the clock
                        // directly. Open buffer = how early the window opens before
                        // start; grace = how late it stays open after the end.
                        ->orWhere(fn ($q2) => $q2
                            ->whereRaw('time_from <= ADDTIME(?, SEC_TO_TIME(? * 60))', [$nowTime, $openBuf])
                            ->whereRaw('time_to >= SUBTIME(?, SEC_TO_TIME(? * 60))', [$nowTime, $graceBuf]));
                });
        };

        return \DB::table('course_sessions')
            ->where('course_id', $courseId)
            ->where('section_id', $cohortId)
            ->where(fn ($q) => $q->where($started)->orWhere($onNow))
            ->orderByRaw('(passcode IS NOT NULL AND passcode_issued_at >= ?) DESC', [$startOfDay])
            ->orderBy('time_from')
            ->orderBy('id')
            ->first();
    }
}
