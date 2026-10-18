<?php

namespace App\Jobs;

use App\AiQuestions\GenerationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Writes a quiz's or assignment's AI questions (D-113) on a queue worker,
 * when one is configured (QUEUE_CONNECTION other than sync). Without a worker
 * the dialog's first poll runs the generation instead
 * (GenerationService::runsOnPoll). Either way the run is claimed once and the
 * outcome stored; the service never throws.
 */
class GenerateAiQuestionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public readonly int $generationId) {}

    public function handle(GenerationService $service): void
    {
        $service->run($this->generationId);
    }
}
