<?php

namespace App\Services\Admin;

use App\Models\UsersCourse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The learner's own cohort in a course, for submission rows (Course Details
 * Quizzes / Assignments tabs, Figma 2295:53311, 2294:51575).
 *
 * A quiz or assignment can be offered to several cohorts; the row's "Cohort"
 * column is the cohort the submitting learner is enrolled in. Resolved in one
 * query per page, never per row.
 */
class LearnerCohorts
{
    /**
     * Set `learner_cohort` ({id, name} or null) on each row.
     *
     * @param  Collection<int, \Illuminate\Database\Eloquent\Model>  $rows
     * @param  callable(\Illuminate\Database\Eloquent\Model): ?int  $courseOf
     */
    public function attach(Collection $rows, callable $courseOf): void
    {
        $pairs = $rows->map(fn ($r) => [$courseOf($r), (int) $r->user_id])
            ->filter(fn ($p) => $p[0] !== null);

        if ($pairs->isEmpty()) {
            $rows->each(fn ($r) => $r->setAttribute('learner_cohort', null));

            return;
        }

        $enrolments = UsersCourse::query()
            ->with('group:id,name')
            ->whereIn('course_id', $pairs->pluck(0)->unique()->values())
            ->whereIn('user_id', $pairs->pluck(1)->unique()->values())
            ->whereNotNull('group_id')
            ->get(['id', 'course_id', 'user_id', 'group_id'])
            ->keyBy(fn ($e) => $e->course_id.'-'.$e->user_id);

        $locale = app()->getLocale();

        $rows->each(function ($r) use ($courseOf, $enrolments, $locale) {
            $group = $enrolments->get($courseOf($r).'-'.$r->user_id)?->group;
            $r->setAttribute('learner_cohort', $group ? [
                'id'   => $group->id,
                'name' => $group->getTranslation('name', $locale),
            ] : null);
        });
    }

    /**
     * Only rows whose learner is enrolled in the given cohort of the course.
     * `$userColumn` is the qualified user id column of the row table.
     */
    public function whereInCohort(Builder $query, string $userColumn, int $courseId, int $sectionId): Builder
    {
        return $query->whereExists(fn ($q) => $q->selectRaw('1')
            ->from('users_courses as uc')
            ->whereColumn('uc.user_id', $userColumn)
            ->where('uc.course_id', $courseId)
            ->where('uc.group_id', $sectionId));
    }
}
