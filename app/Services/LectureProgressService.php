<?php

namespace App\Services;

use App\Models\Course;
use App\Models\CourseLecture;
use App\Models\User;
use App\Models\UserLectureProgress;
use App\Support\CohortModules;

class LectureProgressService
{
    /**
     * Record or update lecture watch progress for a user.
     *
     * Two ways to mark a lecture complete:
     *   - Legacy: numeric `progress` >= 90 (video watch %). Preserved
     *     exactly for existing callers that never send `confirmed`.
     *   - New: an explicit `confirmed` boolean — the "Did you complete this
     *     video?/read this article?" Yes/No prompt (video/document/article)
     *     and the "Mark as complete" click (link) both resolve to this same
     *     boolean; no need for a separate field per content type. When
     *     provided, it wins outright over the numeric-progress rule.
     */
    public function track(int $userId, int $lectureId, ?int $progress, ?bool $confirmed = null): UserLectureProgress
    {
        if ($confirmed !== null) {
            $completed = $confirmed;
            $resolvedProgress = $progress ?? ($confirmed ? 100 : 0);
        } else {
            $resolvedProgress = $progress ?? 0;
            $completed = $resolvedProgress >= 90;
        }

        // Watching a finished video again reports a lower progress: that
        // must not undo the completion, only the learner's own "No" does.
        $existing = UserLectureProgress::query()->where('user_id', $userId)->where('lecture_id', $lectureId)->first();
        if ($existing !== null && $existing->completed && $confirmed === null) {
            $completed = true;
            $resolvedProgress = max((int) $existing->progress, $resolvedProgress);
        }

        $row = UserLectureProgress::updateOrCreate(
            ['user_id' => $userId, 'lecture_id' => $lectureId],
            ['progress' => $resolvedProgress, 'completed' => $completed],
        );

        // Finishing the last content item can complete the course (NEW2B-6205).
        if ($completed) {
            $course = Course::query()->find(CourseLecture::query()->whereKey($lectureId)->value('course_id'));
            $user = User::query()->find($userId);
            if ($course && $user) {
                app(CertificateService::class)->issueFromContent($user, $course);
            }
        }

        return $row;
    }

    /**
     * Calculate overall course completion % for a user.
     * Mirrors HelperTrait::userCourseProgress().
     */
    public function getCourseProgress(int $userId, int $courseId): int
    {
        Course::query()->findOrFail($courseId);
        // The modules of the learner's cohort sheet (D-089).
        $lectureIds = CohortModules::lectureIds($userId, $courseId);

        $totalLectures = count($lectureIds);
        if ($totalLectures === 0) {
            return 0;
        }

        $completed = UserLectureProgress::where('user_id', $userId)
            ->whereIn('lecture_id', $lectureIds)
            ->where('completed', true)
            ->count();

        return (int) round(($completed / $totalLectures) * 100);
    }

    /**
     * Batch variant of getCourseProgress — resolves module completion % for
     * MANY courses in 2 queries total instead of 1 per course, so the
     * `my/learnings` dashboard composite (#4) doesn't N+1 across a
     * learner's course list. Same rule as getCourseProgress: completed
     * lecture rows ÷ total lectures for that course.
     *
     * @param  array<int, int>  $courseIds
     * @return array<int, int>  course_id => percent (0-100)
     */
    public function getCourseProgressBatch(int $userId, array $courseIds): array
    {
        if (empty($courseIds)) {
            return [];
        }

        // Only the modules of the learner's cohort sheet (D-089).
        $totalsByCourse = CourseLecture::whereIn('course_id', $courseIds)
            ->whereRaw(CohortModules::requiredSql((string) $userId, 'course_lectures'))
            ->selectRaw('course_id, COUNT(*) as total')
            ->groupBy('course_id')
            ->pluck('total', 'course_id');

        $completedByCourse = CourseLecture::whereIn('course_lectures.course_id', $courseIds)
            ->join('user_lecture_progress', function ($join) use ($userId) {
                $join->on('user_lecture_progress.lecture_id', '=', 'course_lectures.id')
                    ->where('user_lecture_progress.user_id', '=', $userId)
                    ->where('user_lecture_progress.completed', '=', true);
            })
            ->whereRaw(CohortModules::requiredSql((string) $userId, 'course_lectures'))
            ->selectRaw('course_lectures.course_id as course_id, COUNT(*) as completed')
            ->groupBy('course_lectures.course_id')
            ->pluck('completed', 'course_id');

        $result = [];
        foreach ($courseIds as $courseId) {
            $total = (int) ($totalsByCourse->get($courseId) ?? 0);
            $completed = (int) ($completedByCourse->get($courseId) ?? 0);
            $result[$courseId] = $total > 0 ? (int) round(($completed / $total) * 100) : 0;
        }

        return $result;
    }

    /**
     * Get per-lecture progress for a user within a course, including the
     * content-type/completion-signal fields the course-player needs and the
     * "week/module" grouping the sidebar renders as a heading.
     *
     * The grouping field is `course_lectures.section_id` → the enrolling
     * `course_sections` row's translatable `name` (e.g. "Week 1: CX
     * Foundations") — confirmed by tracing the Figma sidebar headings
     * against the schema (course_sections is the only "module" grouping
     * concept lectures actually belong to; `session_id` is a separate,
     * optional cohort-scoping FK, not a grouping label).
     */
    public function getLectureProgress(int $userId, int $courseId): array
    {
        $course = Course::with(['lectures.section:id,name'])->findOrFail($courseId);
        // Only the modules the learner's cohort sheet schedules (D-089).
        $required = array_flip(CohortModules::lectureIds($userId, $courseId));
        $lectures = $course->lectures->filter(fn ($lecture) => isset($required[(int) $lecture->id]))->values();

        $progressMap = UserLectureProgress::where('user_id', $userId)
            ->whereIn('lecture_id', $lectures->pluck('id'))
            ->get()
            ->keyBy('lecture_id');

        return $lectures->map(fn ($lecture) => [
            'lecture_id'         => $lecture->id,
            'title'              => $lecture->title,
            'content_type'       => $lecture->content_type,
            'require_completion' => (bool) $lecture->require_completion,
            'module'             => $lecture->section ? [
                'id'   => $lecture->section->id,
                'name' => $lecture->section->getTranslation('name', app()->getLocale()),
            ] : null,
            'progress'   => $progressMap[$lecture->id]->progress ?? 0,
            'completed'  => (bool) ($progressMap[$lecture->id]->completed ?? false),
        ])->all();
    }
}
