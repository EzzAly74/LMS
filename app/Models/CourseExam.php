<?php

namespace App\Models;

use App\Models\Concerns\ScopedToAdminCourses;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

class CourseExam extends Model
{
    use ScopedToAdminCourses;

    use HasFactory, HasTranslations;

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
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submissions()
    {
        return $this->hasMany(UserExam::class, 'exam_id');
    }
}
