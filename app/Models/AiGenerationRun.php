<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One call to the AI provider: the daily cap counts these (D-113, H4). */
class AiGenerationRun extends Model
{
    public const KIND_GENERATE = 'generate';
    public const KIND_REGENERATE = 'regenerate';

    protected $fillable = [
        'admin_id', 'ai_assessment_id', 'kind', 'status', 'model', 'input_tokens', 'output_tokens', 'error', 'finished_at',
    ];

    protected $casts = ['finished_at' => 'datetime'];
}
