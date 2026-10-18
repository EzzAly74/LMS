<?php

namespace App\Jobs;

use App\AiAssessments\GenerationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Writes an assessment's questions (D-113). Dispatched after the response
 * (like the HR sync), so Generate answers at once and the Dashboard polls the
 * assessment while questions appear type by type. With a real queue worker it
 * runs there instead; the service never throws, it stores the outcome.
 */
class GenerateAiAssessmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public readonly int $runId) {}

    public function handle(GenerationService $service): void
    {
        // After-response runs inside the web PHP process: lift its time limit
        // for this job only (the stale guard still releases a dead run).
        @set_time_limit($this->timeout);
        ignore_user_abort(true);

        $service->run($this->runId);
    }
}
