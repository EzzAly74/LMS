<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One question of an AI assessment; shape per type in App\AiAssessments\QuestionType. */
class AiAssessmentQuestion extends Model
{
    public const STATUSES = ['pending', 'approved', 'flagged'];

    protected $fillable = [
        'ai_assessment_id', 'run_id', 'position', 'type', 'context', 'prompt', 'options', 'answer',
        'explanation', 'source_ref', 'bloom', 'difficulty', 'status',
    ];

    protected $casts = [
        'options'  => 'array',
        'answer'   => 'array',
        'position' => 'integer',
    ];

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(AiAssessment::class, 'ai_assessment_id');
    }
}
