<?php

namespace App\Support;

use App\Models\Course;
use App\Models\Instructor;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The "instructor" of a quiz or assignment is the course's instructor, who
 * teaches and grades it (NEW2B-6291) - not the Dashboard account that typed
 * it in (`created_by`, often a super admin). Shared by the quiz and
 * assignment lists, their filters and submission views.
 */
final class CourseInstructors
{
    /** Eager-load constraint for `course.instructors`: only what is shown. */
    public static function eager(): \Closure
    {
        return fn ($q) => $q->select('instructors.id', 'instructors.name');
    }

    /** The course's instructors in the reader's language, comma-separated; null when none. */
    public static function names(?Course $course): ?string
    {
        if ($course === null) {
            return null;
        }
        $locale = app()->getLocale();
        $names = $course->instructors
            ->map(fn (Instructor $i) => trim((string) ($i->getTranslation('name', $locale, false) ?: $i->getTranslation('name', $locale === 'ar' ? 'en' : 'ar', false))))
            ->filter()
            ->unique()
            ->values();

        return $names->isEmpty() ? null : $names->implode(', ');
    }

    /**
     * Filter choices: the instructors teaching any of `$courseIds` (a
     * subquery of course ids), in the reader's language.
     *
     * @return list<array{id:int,name:string}>
     */
    public static function options(Builder|\Illuminate\Database\Eloquent\Builder $courseIds, int $limit = 500): array
    {
        $locale = app()->getLocale();

        return Instructor::query()
            ->whereIn('id', DB::table('courses_instructors')->select('instructor_id')->whereIn('course_id', $courseIds))
            ->limit($limit)
            ->get(['id', 'name'])
            ->map(fn (Instructor $i) => ['id' => (int) $i->id, 'name' => (string) ($i->getTranslation('name', $locale, false) ?: $i->getTranslation('name', $locale === 'ar' ? 'en' : 'ar', false))])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /** Course ids taught by any of `$instructorIds`, as a subquery. */
    public static function coursesOf(array $instructorIds): Builder
    {
        return DB::table('courses_instructors')->select('course_id')->whereIn('instructor_id', $instructorIds);
    }
}
