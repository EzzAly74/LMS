<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One "Generate with AI" in a quiz or assignment form (D-113). Owned by the
 * admin who started it; nobody else can read it.
 */
class AiGeneration extends Model
{
    use MassPrunable;

    public const TARGET_QUIZ = 'quiz';
    public const TARGET_ASSIGNMENT = 'assignment';
    public const TARGETS = [self::TARGET_QUIZ, self::TARGET_ASSIGNMENT];

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';

    /** Not finished yet: waiting to be claimed, or being written. */
    public const ACTIVE = [self::STATUS_QUEUED, self::STATUS_RUNNING];

    protected $fillable = [
        'admin_id', 'target', 'course_id', 'status', 'settings', 'source_names', 'source_text', 'source_files',
        'questions', 'error', 'model', 'input_tokens', 'output_tokens', 'finished_at',
    ];

    protected $casts = [
        'settings'      => 'array',
        'source_names'  => 'array',
        'source_files'  => 'array',
        'questions'     => 'array',
        'input_tokens'  => 'integer',
        'output_tokens' => 'integer',
        'finished_at'   => 'datetime',
    ];

    protected $hidden = ['source_text', 'source_files'];

    public function isRunning(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    /** @return array<string, int> question type => count, only those asked for */
    public function counts(): array
    {
        return array_filter(array_map('intval', (array) ($this->settings['counts'] ?? [])), fn (int $n) => $n > 0);
    }

    /**
     * Rows older than 90 days go (`php artisan model:prune`): the questions
     * live in the quiz or assignment once saved; cost history stays 90 days.
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', Carbon::now()->subDays(90));
    }
}
