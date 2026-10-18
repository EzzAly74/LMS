<?php

namespace App\Reports\Support;

use App\Reports\ReportContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Course scope (D-074) for report queries: an account limited to its own
 * courses sees only those courses' rows and learners. The same rule as
 * App\Services\Admin\CourseScope, applied to the ids the context carries.
 */
final class Scope
{
    public static function courses(Builder $query, ReportContext $context, string $column): Builder
    {
        if ($context->courseIds !== null) {
            $query->whereIn($column, $context->courseIds === [] ? [0] : $context->courseIds);
        }

        return $query;
    }

    public static function learners(Builder $query, ReportContext $context, string $column): Builder
    {
        if ($context->courseIds !== null) {
            $query->whereIn($column, DB::table('users_courses')->select('user_id')
                ->whereIn('course_id', $context->courseIds === [] ? [0] : $context->courseIds));
        }

        return $query;
    }

    /** Employees still employed: an active account and no past resignation (D-112). */
    public static function activeEmployees(Builder $query, string $u = 'u'): Builder
    {
        return $query
            ->where("{$u}.status", 'active')
            ->where(fn (Builder $w) => $w->whereNull("{$u}.resignation_date")->orWhere("{$u}.resignation_date", '>', now()->toDateString()));
    }
}
