<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The modules a learner must take on a course (D-089).
 *
 * The cohort's uploaded sessions sheet is the source of truth: a module is
 * required when a session of the learner's cohort lists it in its "content"
 * column (course_session_lectures, D-079). Modules stay course-wide; nothing
 * ties a module to a cohort directly.
 *
 * Fallback, so existing enrolments keep working: when the learner has no
 * cohort on the course, or their cohort's sheet schedules no module at all,
 * every module of the course is required, as before.
 *
 * Everything that lists, counts or completes a learner's modules uses this:
 * the course player, progress percentages, course completion, certificates,
 * the Dashboard learner screens and the mobile API.
 */
final class CohortModules
{
    private static int $alias = 0;

    /**
     * SQL true when lecture row `$lecture` (an alias of course_lectures) is
     * required for the user in SQL expression `$user`.
     *
     * Both arguments are column references written by the caller, never
     * user input: they are interpolated.
     */
    public static function requiredSql(string $user, string $lecture): string
    {
        $n = ++self::$alias;

        return "(NOT EXISTS (
                SELECT 1 FROM users_courses cm_uc{$n}
                JOIN course_sessions cm_s{$n} ON cm_s{$n}.section_id = cm_uc{$n}.group_id
                JOIN course_session_lectures cm_l{$n} ON cm_l{$n}.session_id = cm_s{$n}.id
                WHERE cm_uc{$n}.user_id = {$user} AND cm_uc{$n}.course_id = {$lecture}.course_id
            ) OR EXISTS (
                SELECT 1 FROM users_courses cm_ux{$n}
                JOIN course_sessions cm_sx{$n} ON cm_sx{$n}.section_id = cm_ux{$n}.group_id
                JOIN course_session_lectures cm_lx{$n} ON cm_lx{$n}.session_id = cm_sx{$n}.id
                WHERE cm_ux{$n}.user_id = {$user} AND cm_ux{$n}.course_id = {$lecture}.course_id
                  AND cm_lx{$n}.lecture_id = {$lecture}.id
            ))";
    }

    /**
     * The ids of the modules the learner must take, in module order.
     *
     * @return list<int>
     */
    public static function lectureIds(int $userId, int $courseId): array
    {
        return DB::table('course_lectures as cl')
            ->where('cl.course_id', $courseId)
            ->whereRaw(self::requiredSql((string) $userId, 'cl'))
            ->orderBy('cl.id')
            ->pluck('cl.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** Whether the module is one the learner must take. */
    public static function isRequired(int $userId, int $lectureId): bool
    {
        return DB::table('course_lectures as cl')
            ->where('cl.id', $lectureId)
            ->whereRaw(self::requiredSql((string) $userId, 'cl'))
            ->exists();
    }
}
