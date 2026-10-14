<?php

namespace App\Models;

use App\Models\Concerns\ScopedToAdminCourses;
use App\Models\Concerns\VisibleToLearnerCohort;
use App\Http\Traits\HasFile;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CourseAssignment extends Model
{
    use ScopedToAdminCourses;
    use VisibleToLearnerCohort;

    use HasFactory, HasFile;

    /** The cohorts chosen when cohort_scope is "specific" (NEW2B-6255). */
    public const COHORT_PIVOT = ['course_assignment_cohorts', 'course_assignment_id'];

    /**
     * What a learner may see: published (not a draft) and with questions.
     * Legacy rows without a status count as published.
     */
    public function scopeLearnerReady(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        $table = $this->getTable();

        return $query
            ->where(fn ($w) => $w->whereNull("{$table}.status")->orWhere("{$table}.status", '!=', 'draft'))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('course_assignment_questions as lr_q')
                ->whereColumn('lr_q.course_assignment_id', "{$table}.id"));
    }
    protected $guarded = ['id'];

    protected $casts = [
        'due_date' => 'date',
    ];

    /**
     * Without a pass score an assignment is passed at half its total
     * (NEW2B-6276, D-090). PASS_SCORE_SQL is the same rule for a query
     * that has `course_assignments` to hand.
     */
    public const DEFAULT_PASS_RATIO = 0.5;
    public const PASS_SCORE_SQL = 'COALESCE(course_assignments.pass_score, CEIL(course_assignments.total_score * 0.5))';

    public static function defaultPassScore(int $total): int
    {
        return (int) ceil($total * self::DEFAULT_PASS_RATIO);
    }

    public function effectivePassScore(): int
    {
        return $this->pass_score !== null ? (int) $this->pass_score : self::defaultPassScore((int) $this->total_score);
    }

    /** The due date is over: the whole day counts (NEW2B-6258). */
    public function isOverdue(): bool
    {
        return $this->due_date !== null && now()->greaterThan($this->due_date->copy()->endOfDay());
    }


    /**
     * The name in the reader's language (NEW2B-6253): `title` is the English
     * name, `title_ar` the Arabic; either falls back to the other.
     */
    public function localizedTitle(?string $locale = null): string
    {
        $en = trim((string) ($this->title ?? ''));
        $ar = trim((string) ($this->title_ar ?? ''));

        return ($locale ?? app()->getLocale()) === 'ar' ? ($ar !== '' ? $ar : $en) : ($en !== '' ? $en : $ar);
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_course_assignments', 'course_assignment_id', 'user_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Additive relationships for the rich-question assignment system.
    | These are NEW methods only — no existing behaviour is modified.
    |--------------------------------------------------------------------------
    */

    public function questions()
    {
        return $this->hasMany(CourseAssignmentQuestion::class, 'course_assignment_id')
            ->orderBy('position');
    }

    public function cohorts()
    {
        return $this->hasMany(CourseAssignmentCohort::class, 'course_assignment_id');
    }

    public function creator()
    {
        // `created_by` holds the Dashboard account (admins.id) that saved it.
        return $this->belongsTo(Admin::class, 'created_by');
    }

    public function submissions()
    {
        return $this->hasMany(UserCourseAssignment::class, 'course_assignment_id');
    }
}
