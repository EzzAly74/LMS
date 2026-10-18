<?php

namespace App\Http\Controllers\apis\Admin;

use App\AiQuestions\GenerationRefused;
use App\AiQuestions\GenerationService;
use App\Http\Controllers\apis\ApiController;
use App\Http\Requests\Api\Admin\AiQuestionGenerateRequest;
use App\Http\Resources\Admin\AiGenerationResource;
use App\Jobs\GenerateAiQuestionsJob;
use App\Models\Admin;
use App\Models\AiGeneration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Generate with AI" in the Add / Edit Quiz and Assignment forms (D-113):
 *   POST admin/{quizzes|assignments}/ai-generations       start
 *   GET  admin/{quizzes|assignments}/ai-generations/{id}  poll (own runs only, 404 otherwise)
 *   GET  admin/{quizzes|assignments}/ai-generations/usage today's runs / cap / configured
 *
 * Authorization is the section's own (`section:quizzes` create / view, or
 * `section:assignments`), with course scope on a body course_id (D-074).
 * Nothing is saved to the quiz or assignment here: the questions go back to
 * the form, which saves them through its own endpoint and rules.
 */
class AdminAiQuestionController extends ApiController
{
    public function __construct(private readonly GenerationService $service) {}

    public function store(AiQuestionGenerateRequest $request): JsonResponse
    {
        $admin = $this->admin($request);

        try {
            $generation = $this->service->start(
                $admin,
                $this->target($request),
                $request->integer('course_id') ?: null,
                $request->settings(),
                array_values($request->file('files') ?? []),
                $request->input('text'),
            );
        } catch (GenerationRefused $e) {
            return $this->error(__('ai_questions.errors.'.$e->reason), $e->status, ['reason' => [$e->reason]]);
        }

        // A queue worker takes the run; without one the first poll runs it (GenerationService::runsOnPoll).
        if (! $this->service->runsOnPoll()) {
            GenerateAiQuestionsJob::dispatch($generation->id);
        }

        return $this->success(__('ai_questions.started'), [
            'generation' => new AiGenerationResource($generation),
            'usage'      => $this->service->usage($admin),
        ], Response::HTTP_ACCEPTED);
    }

    public function show(Request $request): JsonResponse
    {
        $generation = $this->service->find($this->admin($request), (int) $request->route('generation'), $this->target($request));

        // No queue worker: this poll does the work (claimed once; later polls just read).
        if ($generation->status === AiGeneration::STATUS_QUEUED && $this->service->runsOnPoll()) {
            $this->service->run($generation->id);
            $generation->refresh();
        }

        return $this->success(__('messages.retrieved'), new AiGenerationResource($generation));
    }

    public function usage(Request $request): JsonResponse
    {
        return $this->success(__('messages.retrieved'), $this->service->usage($this->admin($request)));
    }

    /** 'quiz' or 'assignment', from the route's defaults (read by name: Laravel passes parameters by position). */
    private function target(Request $request): string
    {
        $target = (string) $request->route('target');
        abort_unless(in_array($target, AiGeneration::TARGETS, true), 404);

        return $target;
    }

    private function admin(Request $request): Admin
    {
        /** @var Admin */
        return $request->user();
    }
}
