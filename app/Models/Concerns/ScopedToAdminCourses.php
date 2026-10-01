<?php

namespace App\Models\Concerns;

use App\Services\Admin\CourseScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Course scope as an Eloquent global scope (D-074): while the request belongs
 * to a Dashboard account limited to its own courses, every query on a model
 * using this trait only sees rows of those courses: lists, counts,
 * relations and route-model binding (an out-of-scope id is a 404).
 *
 * A no-op for everyone else: learners, unscoped admins, console commands and
 * queued jobs (no signed-in principal). Raw DB::table() queries are not
 * covered and constrain through CourseScope explicitly.
 *
 * A model whose course id is not its own `course_id` column overrides
 * constrainToCourses().
 */
trait ScopedToAdminCourses
{
    public static function bootScopedToAdminCourses(): void
    {
        static::addGlobalScope('admin_course_scope', static function (Builder $query): void {
            $principal = app()->bound('request') ? request()->user() : null;
            if ($principal === null) {
                return;
            }

            $scope = app(CourseScope::class);
            $ids = $scope->courseIds($principal);
            if ($ids !== null) {
                static::constrainToCourses($query, $ids === [] ? [0] : $ids);
            }
        });
    }

    /** @param list<int> $courseIds */
    protected static function constrainToCourses(Builder $query, array $courseIds): void
    {
        $query->whereIn($query->getModel()->qualifyColumn('course_id'), $courseIds);
    }
}
