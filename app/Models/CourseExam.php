<?php

namespace App\Models;

use App\Models\Concerns\ScopedToAdminCourses;
use App\Models\Concerns\VisibleToLearnerCohort;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

class CourseExam extends Model
{
    use ScopedToAdminCourses;
    use VisibleToLearnerCohort;

    use HasFactory, HasTranslations;

    /** The cohorts chosen when cohort_scope is "specific" (NEW2B-6255). */
    public const COHORT_PIVOT = ['course_exam_cohorts', 'course_exam_id'];

    /** Sessions that list this quiz in the schedule sheet (D-109). */
    public const SESSION_PIVOT = ['course_session_exams', 'exam_id'];

    /**
     * What a learner may see: published (a draft is the admin's work in
     * progress) and built with the question editor. Legacy rows without a
     * status count as published. One query, no per-quiz lookups.
     */
    public function scopeLearnerReady(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        $table = $this->getTable();

        return $query
            ->where(fn ($w) => $w->whereNull("{$table}.status")->orWhere("{$table}.status", '!=', 'draft'))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('course_exam_questions as lr_q')
                ->whereColumn('lr_q.course_exam_id', "{$table}.id")
                ->whereNotNull('lr_q.question_en'));
    }

    public array $translatable = ['title'];

    protected $guarded = ['id'];

    protected $casts = [
        'due_date' => 'date',
    ];

    /** Pre / Mid / Post (D-065). Post is the final exam. */
    public const TYPES = ['pre', 'mid', 'post'];

    /**
     * Keep `type` and `is_final` telling the same story (D-065): Post means
     * final exam, and the certificate / completion rules read `is_final`.
     * Whichever of the two a caller changed wins: the new admin form sets
     * `type`, the legacy exam endpoints set `is_final`.
     */
    protected static function booted(): void
    {
        static::saving(function (self $exam) {
            if ($exam->isDirty('type')) {
                $exam->is_final = $exam->type === 'post';
            } elseif ($exam->isDirty('is_final')) {
                if ($exam->is_final) {
                    $exam->type = 'post';
                } elseif ($exam->type === 'post') {
                    $exam->type = null;
                }
            }
        });
    }

    public function questions()
    {
        return $this->hasMany(CourseExamQuestion::class, 'course_exam_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Additive relationships for the 2026 rich-question Quiz workflow.
    | These are NEW methods only — existing behaviour is untouched.
    |--------------------------------------------------------------------------
    */

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function richQuestions()
    {
        return $this->hasMany(CourseExamQuestion::class, 'course_exam_id')
            ->orderBy('position');
    }

    public function cohorts()
    {
        return $this->hasMany(CourseExamCohort::class, 'course_exam_id');
    }

    public function creator()
    {
        // `created_by` holds the Dashboard account (admins.id) that saved it.
        return $this->belongsTo(Admin::class, 'created_by');
    }

    public function submissions()
    {
        return $this->hasMany(UserExam::class, 'exam_id');
    }
}
