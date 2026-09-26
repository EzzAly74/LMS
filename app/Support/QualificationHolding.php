<?php

namespace App\Support;

/**
 * The single definition of "this learner holds this qualification" (D-056).
 *
 * Decided by the human 2026-09-26: a learner holds a qualification when
 *   (a) an admin granted it to them directly (`user_qualification_skill`,
 *       D-045), or
 *   (b) the qualification has at least one linked course and the learner has
 *       completed EVERY course linked to it (App\Support\CourseCompletion).
 *
 * Three screens used to answer this differently: the job-title detail counted
 * only the linked courses the learner happened to be enrolled in (so passing
 * 1 of 3 read as "1 of 1 - earned"), the learner-detail tile counted any one
 * passed linked course, and the Website counted all of them. They now share
 * this class, in SQL and in PHP.
 *
 * Progress towards a qualification is passed linked courses / linked courses,
 * or 100% with a direct grant. A grant never invents coursework: callers that
 * show "N of M courses" keep the real counts next to a 100% progress value.
 *
 * Every argument is a SQL column reference written by calling code, never user
 * input - the fragments interpolate them.
 */
final class QualificationHolding
{
    /** Correlated count of the courses linked to qualification {$qualification}. */
    public static function linkedCoursesSql(string $qualification): string
    {
        return "(SELECT COUNT(*) FROM course_qualification_skills qh_l
            WHERE qh_l.qualification_skill_id = {$qualification})";
    }

    /** Correlated count of those courses that {$user} has completed. */
    public static function passedCoursesSql(string $user, string $qualification): string
    {
        return "(SELECT COUNT(*) FROM course_qualification_skills qh_p
            WHERE qh_p.qualification_skill_id = {$qualification}
              AND ".CourseCompletion::existsSql($user, 'qh_p.course_id').')';
    }

    /** EXISTS fragment: {$user} was granted {$qualification} directly. */
    public static function grantedSql(string $user, string $qualification): string
    {
        return "EXISTS (SELECT 1 FROM user_qualification_skill qh_g
            WHERE qh_g.user_id = {$user}
              AND qh_g.qualification_skill_id = {$qualification})";
    }

    /** Boolean fragment: {$user} holds {$qualification}. */
    public static function holdsSql(string $user, string $qualification): string
    {
        $linked = self::linkedCoursesSql($qualification);
        $passed = self::passedCoursesSql($user, $qualification);

        return '('.self::grantedSql($user, $qualification)." OR ({$linked} > 0 AND {$passed} >= {$linked}))";
    }

    /** Numeric fragment in [0, 1]: {$user}'s progress towards {$qualification}. */
    public static function progressSql(string $user, string $qualification): string
    {
        $linked = self::linkedCoursesSql($qualification);
        $passed = self::passedCoursesSql($user, $qualification);

        return 'CASE WHEN '.self::grantedSql($user, $qualification).' THEN 1'
            ." WHEN {$linked} > 0 THEN LEAST(1, {$passed} / {$linked})"
            .' ELSE 0 END';
    }

    /** The same rule for values already loaded in PHP. */
    public static function holds(bool $granted, int $linked, int $passed): bool
    {
        return $granted || ($linked > 0 && $passed >= $linked);
    }

    /** Progress as a whole percentage, matching progressSql(). */
    public static function percent(bool $granted, int $linked, int $passed): int
    {
        if ($granted) {
            return 100;
        }

        return $linked > 0 ? (int) min(100, max(0, round($passed * 100 / $linked))) : 0;
    }
}
