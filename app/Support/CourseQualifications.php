<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Which qualifications a course gives a learner (D-106, NEW2B-6049).
 *
 * A course's qualifications are `course_qualification_skills`. When an admin
 * changes them, the cohorts already under way keep the list they started
 * with: CourseQualificationEdit freezes it into `cohort_qualification_skills`
 * and stamps `course_sections.qualifications_frozen_at`. So, for a learner:
 *
 *   - enrolled in a frozen cohort of the course -> that cohort's list;
 *   - otherwise (a later cohort, or not enrolled) -> the course's list.
 *
 * Every "holds / progress / compliance" calculation (QualificationHolding,
 * D-056 / D-093) reads the course-qualification links through this class,
 * in PHP or in SQL, so old cohorts are never affected by a later edit.
 *
 * SQL arguments are column references written by calling code, never input.
 */
final class CourseQualifications
{
    /**
     * Per learner, the courses linked to each qualification as that learner
     * sees them.
     *
     * @param  array<int, int>  $userIds
     * @param  array<int, int>  $qualificationIds
     * @return array<int, array<int, list<int>>>  user id => qualification id => course ids
     */
    public static function coursesByQualification(array $userIds, array $qualificationIds): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        $qualificationIds = array_values(array_unique(array_map('intval', $qualificationIds)));
        if ($userIds === [] || $qualificationIds === []) {
            return array_fill_keys($userIds, []);
        }

        $current = [];
        foreach (DB::table('course_qualification_skills')->whereIn('qualification_skill_id', $qualificationIds)
            ->get(['qualification_skill_id', 'course_id']) as $row) {
            $current[(int) $row->qualification_skill_id][(int) $row->course_id] = true;
        }

        // The learners' frozen enrolments, and what those cohorts give.
        $frozen = [];
        foreach (DB::table('users_courses as uc')
            ->join('course_sections as cs', function ($j) {
                $j->on('cs.id', '=', 'uc.group_id')->on('cs.course_id', '=', 'uc.course_id');
            })
            ->whereIn('uc.user_id', $userIds)
            ->whereNotNull('cs.qualifications_frozen_at')
            ->get(['uc.user_id', 'uc.course_id', 'uc.group_id']) as $row) {
            $frozen[(int) $row->user_id][(int) $row->course_id] = (int) $row->group_id;
        }
        $snapshot = [];
        $cohortIds = array_values(array_unique(array_merge([], ...array_map('array_values', $frozen ?: [[]]))));
        if ($cohortIds !== []) {
            foreach (DB::table('cohort_qualification_skills')->whereIn('section_id', $cohortIds)
                ->whereIn('qualification_skill_id', $qualificationIds)
                ->get(['section_id', 'qualification_skill_id']) as $row) {
                $snapshot[(int) $row->section_id][] = (int) $row->qualification_skill_id;
            }
        }

        $result = [];
        foreach ($userIds as $userId) {
            $mine = $frozen[$userId] ?? [];
            $map = [];
            foreach ($qualificationIds as $q) {
                $courses = array_keys($current[$q] ?? []);
                // A frozen course counts only through its cohort's list.
                $map[$q] = array_values(array_filter($courses, fn (int $c) => ! isset($mine[$c])));
            }
            foreach ($mine as $courseId => $cohortId) {
                foreach ($snapshot[$cohortId] ?? [] as $q) {
                    $map[$q][] = $courseId;
                }
            }
            $result[$userId] = array_map(fn (array $c) => array_values(array_unique($c)), $map);
        }

        return $result;
    }

    /**
     * Before a course's qualifications change, the cohorts already under way
     * (started, or with a learner) keep the current list (NEW2B-6049). A
     * cohort that is frozen stays frozen; later and empty cohorts follow the
     * course. No-op when the list does not change. Call inside the edit's
     * transaction.
     *
     * @param  array<int, int>  $next  the qualification ids being saved
     */
    public static function freezeCohortsBeforeChange(\App\Models\Course $course, array $next): void
    {
        $current = DB::table('course_qualification_skills')->where('course_id', $course->getKey())
            ->pluck('qualification_skill_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $wanted = collect($next)->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
        if ($current === $wanted) {
            return;
        }

        $today = now()->toDateString();
        $cohortIds = DB::table('course_sections as cs')
            ->where('cs.course_id', $course->getKey())
            ->whereNull('cs.qualifications_frozen_at')
            ->where(function ($q) use ($today) {
                $q->whereDate('cs.start_date', '<=', $today)
                    ->orWhereExists(fn ($e) => $e->selectRaw('1')->from('users_courses as uc')
                        ->whereColumn('uc.group_id', 'cs.id')->whereColumn('uc.course_id', 'cs.course_id'));
            })
            ->lockForUpdate()
            ->pluck('cs.id')
            ->map(fn ($id) => (int) $id)
            ->all();
        if ($cohortIds === []) {
            return;
        }

        $rows = [];
        foreach ($cohortIds as $cohortId) {
            foreach ($current as $q) {
                $rows[] = ['section_id' => $cohortId, 'qualification_skill_id' => $q];
            }
        }
        if ($rows !== []) {
            DB::table('cohort_qualification_skills')->insertOrIgnore($rows);
        }
        DB::table('course_sections')->whereIn('id', $cohortIds)->update(['qualifications_frozen_at' => now()]);
    }

    /**
     * The qualifications each of these courses gives {$userId}.
     *
     * @param  array<int, int>  $courseIds
     * @return array<int, list<int>>  course id => qualification ids
     */
    public static function forUserCourses(int $userId, array $courseIds): array
    {
        $courseIds = array_values(array_unique(array_map('intval', $courseIds)));
        if ($courseIds === []) {
            return [];
        }

        $out = array_fill_keys($courseIds, []);
        $frozen = DB::table('users_courses as uc')
            ->join('course_sections as cs', function ($j) {
                $j->on('cs.id', '=', 'uc.group_id')->on('cs.course_id', '=', 'uc.course_id');
            })
            ->where('uc.user_id', $userId)
            ->whereIn('uc.course_id', $courseIds)
            ->whereNotNull('cs.qualifications_frozen_at')
            ->pluck('uc.group_id', 'uc.course_id');

        foreach (DB::table('course_qualification_skills')->whereIn('course_id', $courseIds)
            ->whereNotIn('course_id', $frozen->keys()->all() ?: [0])
            ->get(['course_id', 'qualification_skill_id']) as $row) {
            $out[(int) $row->course_id][] = (int) $row->qualification_skill_id;
        }
        if ($frozen->isNotEmpty()) {
            $byCohort = DB::table('cohort_qualification_skills')->whereIn('section_id', $frozen->values()->all())
                ->get(['section_id', 'qualification_skill_id'])->groupBy('section_id');
            foreach ($frozen as $courseId => $cohortId) {
                $out[(int) $courseId] = ($byCohort->get($cohortId) ?? collect())
                    ->pluck('qualification_skill_id')->map(fn ($id) => (int) $id)->values()->all();
            }
        }

        return $out;
    }

    /**
     * Correlated count of the distinct courses linked to any qualification of
     * job title {$jobTitle} for {$user}; with $completedOnly, only those the
     * learner completed. The "N of M courses" on the job-title page.
     */
    public static function jobTitleCoursesSql(string $user, string $jobTitle, bool $completedOnly = false): string
    {
        $doneCurrent = $completedOnly ? ' AND '.CourseCompletion::existsSql($user, 'cq_jc.course_id') : '';
        $doneFrozen = $completedOnly ? ' AND '.CourseCompletion::existsSql($user, 'cq_ju.course_id') : '';

        return "((SELECT COUNT(DISTINCT cq_jc.course_id) FROM course_qualification_skills cq_jc
                JOIN job_title_qualification_skill cq_jt ON cq_jt.qualification_skill_id = cq_jc.qualification_skill_id
                WHERE cq_jt.job_title_id = {$jobTitle}
                  AND NOT ".self::frozenSql($user, 'cq_jc.course_id')."{$doneCurrent})
            + (SELECT COUNT(DISTINCT cq_ju.course_id) FROM cohort_qualification_skills cq_js
                JOIN users_courses cq_ju ON cq_ju.group_id = cq_js.section_id AND cq_ju.user_id = {$user}
                JOIN course_sections cq_jss ON cq_jss.id = cq_js.section_id AND cq_jss.course_id = cq_ju.course_id
                JOIN job_title_qualification_skill cq_jt2 ON cq_jt2.qualification_skill_id = cq_js.qualification_skill_id
                WHERE cq_jt2.job_title_id = {$jobTitle}
                  AND cq_jss.qualifications_frozen_at IS NOT NULL{$doneFrozen}))";
    }

    /** EXISTS fragment: {$user}'s enrolment in {$course} is in a frozen cohort. */
    public static function frozenSql(string $user, string $course): string
    {
        return "EXISTS (SELECT 1 FROM users_courses cq_fu
            JOIN course_sections cq_fs ON cq_fs.id = cq_fu.group_id AND cq_fs.course_id = cq_fu.course_id
            WHERE cq_fu.user_id = {$user} AND cq_fu.course_id = {$course}
              AND cq_fs.qualifications_frozen_at IS NOT NULL)";
    }

    /**
     * Correlated count of the courses linked to {$qualification} for {$user},
     * optionally only those matching the extra condition on the course column
     * (`{course}` in $courseCondition is replaced by the course column).
     */
    public static function countSql(string $user, string $qualification, ?string $courseCondition = null): string
    {
        $currentCond = $courseCondition === null ? '' : ' AND '.str_replace('{course}', 'cq_c.course_id', $courseCondition);
        $frozenCond = $courseCondition === null ? '' : ' AND '.str_replace('{course}', 'cq_su.course_id', $courseCondition);

        return "((SELECT COUNT(*) FROM course_qualification_skills cq_c
                WHERE cq_c.qualification_skill_id = {$qualification}
                  AND NOT ".self::frozenSql($user, 'cq_c.course_id')."{$currentCond})
            + (SELECT COUNT(*) FROM cohort_qualification_skills cq_s
                JOIN users_courses cq_su ON cq_su.group_id = cq_s.section_id AND cq_su.user_id = {$user}
                JOIN course_sections cq_ss ON cq_ss.id = cq_s.section_id AND cq_ss.course_id = cq_su.course_id
                WHERE cq_s.qualification_skill_id = {$qualification}
                  AND cq_ss.qualifications_frozen_at IS NOT NULL{$frozenCond}))";
    }
}
