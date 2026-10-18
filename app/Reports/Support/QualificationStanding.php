<?php

namespace App\Reports\Support;

use App\Support\CourseCompletion;
use App\Support\CourseQualifications;
use App\Support\QualificationHolding;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Each learner's standing on every qualification that applies to them (their
 * job title's, plus any granted directly), with the dates the compliance
 * reports show. A fixed number of queries for any number of learners.
 *
 * "Holds" and the percent come from QualificationHolding (D-056, D-093); a
 * course counts once completed by the one rule (CourseCompletion, D-094),
 * through each learner's own course links (CourseQualifications, D-106).
 *
 * With `$asOf`, only completions, grants and enrolments up to that moment
 * count: the standing on that day (the "as of" filter, D-112).
 */
final class QualificationStanding
{
    /**
     * @param  list<int>  $userIds
     * @return array<int, array<int, array{
     *   required: bool, granted: bool, assigned_at: ?string, linked: int, passed: int,
     *   percent: int, holds: bool, started: bool, completed_at: ?string
     * }>>  user id => qualification id => standing
     */
    public static function forUsers(array $userIds, ?Carbon $asOf = null): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if ($userIds === []) {
            return [];
        }
        $cutoff = $asOf?->toDateTimeString();

        $jobTitleByUser = DB::table('users')->whereIn('id', $userIds)->pluck('job_title_id', 'id');
        $required = [];
        foreach (DB::table('job_title_qualification_skill')
            ->whereIn('job_title_id', $jobTitleByUser->filter()->unique()->values()->all() ?: [0])
            ->get(['job_title_id', 'qualification_skill_id', 'created_at']) as $row) {
            $required[(int) $row->job_title_id][(int) $row->qualification_skill_id] = $row->created_at;
        }

        $grants = [];
        foreach (DB::table('user_qualification_skill')->whereIn('user_id', $userIds)
            ->when($cutoff, fn ($q) => $q->where(fn ($w) => $w->whereNull('assigned_at')->orWhere('assigned_at', '<=', $cutoff)))
            ->get(['user_id', 'qualification_skill_id', 'assigned_at', 'created_at']) as $row) {
            $grants[(int) $row->user_id][(int) $row->qualification_skill_id] = $row->assigned_at ?? $row->created_at;
        }

        $qualificationIds = [];
        foreach ($userIds as $userId) {
            $jobTitle = $jobTitleByUser[$userId] ?? null;
            $qualificationIds = array_merge($qualificationIds, array_keys($required[$jobTitle] ?? []), array_keys($grants[$userId] ?? []));
        }
        $qualificationIds = array_values(array_unique($qualificationIds));
        $courses = CourseQualifications::coursesByQualification($userIds, $qualificationIds);

        $completed = [];
        foreach (CourseCompletion::query($userIds)->get() as $pair) {
            if ($cutoff === null || ($pair->completed_at !== null && $pair->completed_at <= $cutoff)) {
                $completed[(int) $pair->user_id][(int) $pair->course_id] = $pair->completed_at;
            }
        }
        $enrolled = [];
        foreach (DB::table('users_courses')->whereIn('user_id', $userIds)
            ->when($cutoff, fn ($q) => $q->where('created_at', '<=', $cutoff))
            ->get(['user_id', 'course_id']) as $row) {
            $enrolled[(int) $row->user_id][(int) $row->course_id] = true;
        }

        $result = [];
        foreach ($userIds as $userId) {
            $jobTitle = $jobTitleByUser[$userId] ?? null;
            $mine = $required[$jobTitle] ?? [];
            $granted = $grants[$userId] ?? [];
            $standing = [];
            foreach (array_unique(array_merge(array_keys($mine), array_keys($granted))) as $q) {
                $linked = $courses[$userId][$q] ?? [];
                $doneDates = array_values(array_filter(array_map(fn (int $c) => $completed[$userId][$c] ?? null, $linked)));
                $passed = count(array_filter($linked, fn (int $c) => array_key_exists($c, $completed[$userId] ?? [])));
                $isGranted = array_key_exists($q, $granted);
                $holds = QualificationHolding::holds($isGranted, count($linked), $passed);

                $completedAt = null;
                if ($holds) {
                    $byCourses = count($linked) > 0 && $passed >= count($linked) && $doneDates !== [] ? max($doneDates) : null;
                    $completedAt = self::earliest($isGranted ? $granted[$q] : null, $byCourses);
                }

                $standing[$q] = [
                    'required' => array_key_exists($q, $mine),
                    'granted' => $isGranted,
                    'assigned_at' => self::earliest($mine[$q] ?? null, $granted[$q] ?? null),
                    'linked' => count($linked),
                    'passed' => $passed,
                    'percent' => QualificationHolding::percent($isGranted, count($linked), $passed),
                    'holds' => $holds,
                    'started' => $holds || $passed > 0
                        || count(array_filter($linked, fn (int $c) => isset($enrolled[$userId][$c]))) > 0,
                    'completed_at' => $completedAt,
                ];
            }
            $result[$userId] = $standing;
        }

        return $result;
    }

    private static function earliest(?string $a, ?string $b): ?string
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        return min($a, $b);
    }
}
