<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A trainer's generated assessment (D-113). Owned by one admin (H2: each
 * author sees only their own; super admins see all).
 */
class AiAssessment extends Model
{
    use HasFactory;

    public const TYPES = ['pre', 'post', 'quiz'];
    public const LANGUAGES = ['en', 'ar'];
    public const DIFFICULTIES = ['easy', 'medium', 'hard', 'mixed'];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_GENERATING = 'generating';
    public const STATUS_READY = 'ready';

    protected $fillable = [
        'admin_id', 'course_id', 'duplicated_from_id', 'title', 'type', 'content_language', 'difficulty',
        'audience', 'bloom_levels', 'objectives', 'question_counts', 'version', 'status', 'current_run_id',
        'generation_started_at', 'last_error',
    ];

    protected $casts = [
        'bloom_levels'          => 'array',
        'question_counts'       => 'array',
        'generation_started_at' => 'datetime',
        'version'               => 'integer',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function sources(): HasMany
    {
        return $this->hasMany(AiAssessmentSource::class)->orderBy('id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(AiAssessmentQuestion::class)->orderBy('position')->orderBy('id');
    }

    /** The configured counts, only the types asked for, in QuestionType order. */
    public function requestedCounts(): array
    {
        $counts = [];
        foreach (\App\AiAssessments\QuestionType::ALL as $type) {
            $n = (int) ($this->question_counts[$type] ?? 0);
            if ($n > 0) {
                $counts[$type] = $n;
            }
        }

        return $counts;
    }

    public function isGenerating(): bool
    {
        return $this->status === self::STATUS_GENERATING;
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }

        return $query->where('title', 'like', '%'.addcslashes($term, '%_\\').'%');
    }
}
