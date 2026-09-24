<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The single definition of "this learner completed this course" (B-104).
 *
 * ── Why this class exists ────────────────────────────────────────────────────
 * The codebase carried two incompatible definitions at the same time:
 *
 *   1. `users_courses.updated_at > created_at`
 *      — DashboardRepository, JobTitleRepository, JobTitleService
 *   2. `user_exams.status IN ('passed', 'completed')`
 *      — AdminUserService, AdminLearnerProfileService
 *
 * They disagree, and they disagreed on screens shown side by side: a job-title
 * card could report a learner as compliant while the learner detail it drills
 * into reported the same person as having completed nothing.
 *
 * (1) is not a completion check at all. `updated_at > created_at` is true as
 * soon as anything touches the enrolment row — a progress tick, a re-save, a
 * bulk update, a column backfill. It counts *activity*, and it silently
 * inflates every completion and compliance figure derived from it.
 *
 * (2) is decided as the project-wide definition (7.2 in `06-open-questions.md`).
 * A course is completed when the learner has a passing exam record for it.
 *
 * ── Consequence, stated plainly ──────────────────────────────────────────────
 * Completion and compliance numbers FALL wherever (1) was inflating them. That
 * is a correction, not a regression, but it is visible: dashboard completion
 * counts, the completion trend, job-title compliance bars and "courses
 * completed" per learner all move.
 *
 * ── Courses with no exam ─────────────────────────────────────────────────────
 * A course that has no exam can never be completed under this definition. That
 * is deliberate: the alternative is to infer completion from activity, which is
 * exactly the defect being removed. Courses that should be completable without
 * an assessment need an explicit completion signal, which does not exist in the
 * schema today and is not invented here.
 */
final class CourseCompletion
{
    /**
     * Statuses on `user_exams` that count as a pass.
     *
     * Compared case-insensitively because the column is free-form text and the
     * existing data is mixed-case.
     */
    public const PASSING_STATUSES = ['passed', 'completed'];

    /**
     * A correlated `EXISTS (...)` fragment for use inside raw SQL.
     *
     * Both arguments are SQL column references supplied by calling code, never
     * by user input — they are interpolated, so they must stay that way.
     *
     * Usable anywhere a boolean expression is: `whereRaw()`, a `CASE WHEN`, a
     * `COUNT(DISTINCT CASE WHEN ... END)`.
     *
     * Example:
     *   COUNT(DISTINCT CASE WHEN {existsSql} THEN users_courses.course_id END)
     */
    public static function existsSql(string $userColumn, string $courseColumn): string
    {
        $statuses = implode(', ', array_map(
            static fn (string $s) => "'".$s."'",
            self::PASSING_STATUSES,
        ));

        return "EXISTS (
            SELECT 1 FROM user_exams ce
            WHERE ce.user_id   = {$userColumn}
              AND ce.course_id = {$courseColumn}
              AND LOWER(COALESCE(ce.status, '')) IN ({$statuses})
        )";
    }

    /**
     * Distinct (user_id, course_id) pairs that are completed, with the moment
     * completion happened.
     *
     * `completed_at` is the EARLIEST passing submission for the pair, so a
     * later retake cannot move a completion into a different reporting bucket.
     * It falls back to `updated_at` for rows predating `submitted_at`.
     */
    public static function query(): Builder
    {
        $statuses = self::PASSING_STATUSES;

        return DB::table('user_exams')
            ->selectRaw('user_id, course_id, MIN(COALESCE(submitted_at, updated_at)) AS completed_at')
            ->whereRaw(
                'LOWER(COALESCE(status, "")) IN ('.implode(', ', array_fill(0, count($statuses), '?')).')',
                $statuses,
            )
            ->whereNotNull('course_id')
            ->groupBy('user_id', 'course_id');
    }

    /**
     * The course ids a single learner has completed.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    public static function courseIdsFor(int $userId): \Illuminate\Support\Collection
    {
        return DB::table('user_exams')
            ->where('user_id', $userId)
            ->whereRaw(
                'LOWER(COALESCE(status, "")) IN (?, ?)',
                self::PASSING_STATUSES,
            )
            ->whereNotNull('course_id')
            ->distinct()
            ->pluck('course_id');
    }
}
