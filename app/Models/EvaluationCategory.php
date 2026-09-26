<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Spatie\Translatable\HasTranslations;

/**
 * An evaluation template (the table predates the name).
 *
 * Scope (D-054): course_id NULL = every evaluable course; section_id NULL =
 * every cohort of that course. A section is only set together with its course.
 */
class EvaluationCategory extends Model
{
    use HasFactory, HasTranslations;

    public array $translatable = ['name'];

    protected $fillable = ['name', 'course_id', 'section_id'];

    public function evaluations(): HasMany
    {
        return $this->hasMany(Evaluation::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(CourseSection::class, 'section_id');
    }

    /**
     * Once any learner has answered, a template is read-only (decided by the
     * human 2026-09-26): editing wording or structure would change what the
     * existing answers mean.
     */
    public function hasResponses(): bool
    {
        return DB::table('user_course_evaluations')->where('evaluation_category_id', $this->id)->exists();
    }

    /** Templates a learner in this course (and cohort) is asked to answer. */
    public function scopeForCourse(Builder $query, int $courseId, ?int $sectionId): Builder
    {
        return $query
            ->where(fn ($q) => $q->whereNull('course_id')->orWhere('course_id', $courseId))
            ->where(fn ($q) => $q->whereNull('section_id')
                ->when($sectionId !== null, fn ($q2) => $q2->orWhere('section_id', $sectionId)));
    }
}
