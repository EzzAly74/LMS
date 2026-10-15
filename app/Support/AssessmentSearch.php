<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * The Quizzes / Assignments lists' search (NEW2B-6357): a quiz or assignment
 * is found by its own title (English or Arabic) or by its course's name, in
 * the "created" table, the View All dialog and, with the learner's name, the
 * attempts table. The term is matched literally (% and _ are not wildcards).
 */
final class AssessmentSearch
{
    /** `%term%` with LIKE's wildcards escaped. */
    public static function like(string $term): string
    {
        return '%'.addcslashes(trim($term), '\\%_').'%';
    }

    /**
     * Rows of course_exams / course_assignments whose title or course matches.
     *
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     */
    public static function itemOrCourse(Builder $query, string $term): Builder
    {
        $like = self::like($term);
        $table = $query->getModel()->getTable();

        return $query->where(fn (Builder $w) => $w
            ->where("{$table}.title", 'like', $like)
            ->orWhere("{$table}.title_ar", 'like', $like)
            ->orWhereHas('course', fn (Builder $c) => $c->where('courses.title', 'like', $like)));
    }
}
