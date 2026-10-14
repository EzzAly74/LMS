<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Learner compliance for many learners in a fixed number of queries (D-093):
 * the mean of their job title's qualification percents, a direct grant
 * counting 100 ({@see QualificationHolding}); completed courses by the one
 * completion rule ({@see CourseCompletion}). The learners list and the
 * report exports read it, so both show the same numbers.
 */
final class LearnerCompliance
{
    /**
     * @param  list<int>  $userIds
     * @return array<int, array{completed: array<int, string|null>, compliance: ?int, qualified: bool, required: int}>
     *         per user: completed course id => completed_at; compliance (null
     *         when the job title requires nothing); qualified = holds every
     *         required qualification; required = how many it requires
     */
    public static function forUsers(array $userIds): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if ($userIds === []) {
            return [];
        }

        $completed = [];
        foreach (CourseCompletion::query($userIds)->get() as $pair) {
            $completed[(int) $pair->user_id][(int) $pair->course_id] = $pair->completed_at ?? null;
        }

        $jobTitleByUser = DB::table('users')->whereIn('id', $userIds)->pluck('job_title_id', 'id');
        $qualsByJobTitle = DB::table('job_title_qualification_skill')
            ->whereIn('job_title_id', $jobTitleByUser->filter()->unique()->values()->all() ?: [0])
            ->get(['job_title_id', 'qualification_skill_id'])
            ->groupBy('job_title_id')
            ->map(fn ($rows) => $rows->pluck('qualification_skill_id')->map(fn ($id) => (int) $id)->unique()->values()->all());
        // Each learner's own links: a frozen cohort keeps its list (D-106).
        $coursesByUser = CourseQualifications::coursesByQualification($userIds, $qualsByJobTitle->flatten()->unique()->values()->all());
        $grantedByUser = DB::table('user_qualification_skill')
            ->whereIn('user_id', $userIds)
            ->get(['user_id', 'qualification_skill_id'])
            ->groupBy('user_id')
            ->map(fn ($rows) => array_flip($rows->pluck('qualification_skill_id')->map(fn ($id) => (int) $id)->all()));

        $result = [];
        foreach ($userIds as $userId) {
            $done = $completed[$userId] ?? [];
            $granted = $grantedByUser[$userId] ?? [];
            $jobTitle = $jobTitleByUser[$userId] ?? null;
            $quals = $jobTitle !== null ? ($qualsByJobTitle[$jobTitle] ?? []) : [];

            $percents = [];
            $held = 0;
            foreach ($quals as $q) {
                $courses = $coursesByUser[$userId][$q] ?? [];
                $passed = count(array_filter($courses, fn (int $id) => array_key_exists($id, $done)));
                $percents[] = QualificationHolding::percent(isset($granted[$q]), count($courses), $passed);
                $held += QualificationHolding::holds(isset($granted[$q]), count($courses), $passed) ? 1 : 0;
            }

            $result[$userId] = [
                'completed' => $done,
                'compliance' => QualificationHolding::compliance($percents),
                'qualified' => $quals !== [] && $held === count($quals),
                'required' => count($quals),
            ];
        }

        return $result;
    }
}
