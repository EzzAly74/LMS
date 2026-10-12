<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * A quiz or assignment made for "specific" cohorts reaches only learners in
 * one of those cohorts (NEW2B-6255). `cohort_scope = 'all'` (the default)
 * reaches every learner of the course. The chosen cohorts are in a pivot
 * whose `course_section_id` is the cohort; the learner's cohort is
 * users_courses.group_id.
 *
 * The model declares the pivot table and its foreign key in
 * COHORT_PIVOT = [table, foreign key].
 */
trait VisibleToLearnerCohort
{
    /** Only the rows this learner may see. */
    public function scopeVisibleTo(Builder $query, int $userId): Builder
    {
        [$pivot, $foreignKey] = static::COHORT_PIVOT;
        $table = $this->getTable();

        return $query->where(function (Builder $w) use ($table, $pivot, $foreignKey, $userId) {
            $w->whereNull("{$table}.cohort_scope")
                ->orWhere("{$table}.cohort_scope", '!=', 'specific')
                ->orWhereExists(function ($exists) use ($table, $pivot, $foreignKey, $userId) {
                    $exists->selectRaw('1')
                        ->from("{$pivot} as vc_pivot")
                        ->join('users_courses as vc_uc', 'vc_uc.group_id', '=', 'vc_pivot.course_section_id')
                        ->whereColumn("vc_pivot.{$foreignKey}", "{$table}.id")
                        ->whereColumn('vc_uc.course_id', "{$table}.course_id")
                        ->where('vc_uc.user_id', $userId);
                });
        });
    }

    public function isVisibleTo(int $userId): bool
    {
        return static::query()->whereKey($this->getKey())->visibleTo($userId)->exists();
    }
}
