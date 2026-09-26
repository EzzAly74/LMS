<?php

namespace App\Http\Controllers\apis\Admin;

use App\Http\Controllers\apis\ApiController;
use App\Http\Requests\Api\Admin\AdminEvaluationTemplateRequest;
use App\Models\EvaluationCategory;
use App\Services\Admin\EvaluationTemplateService;
use Illuminate\Http\JsonResponse;

/**
 * The evaluation template builder (Figma 2409:132793 / 2409:133222).
 *
 * Replaces the legacy evaluation-categories / evaluations CRUD, which nothing
 * called any more and which could edit a template after learners had answered
 * it (retired per Q-060 once this flow shipped).
 */
class AdminEvaluationTemplateController extends ApiController
{
    public function __construct(private readonly EvaluationTemplateService $templates) {}

    /**
     * GET admin/evaluations/templates/options - the builder's Course and Cohort
     * choices (only courses with evaluation enabled, each with its cohorts),
     * and the pass limit its "Business rule" note states.
     */
    public function options(): JsonResponse
    {
        return $this->success(__('messages.retrieved'), $this->templates->builderOptions());
    }

    /** GET admin/evaluations/templates/{template} - the builder's edit load. */
    public function show(EvaluationCategory $template): JsonResponse
    {
        return $this->success(__('messages.retrieved'), $this->templates->show($template));
    }

    /** POST admin/evaluations/templates - "Publish Evaluation". */
    public function store(AdminEvaluationTemplateRequest $request): JsonResponse
    {
        $template = $this->templates->create($request->payload());

        return $this->created(__('messages.created'), $this->templates->show($template));
    }

    /** PUT admin/evaluations/templates/{template} - refused once any learner has answered. */
    public function update(AdminEvaluationTemplateRequest $request, EvaluationCategory $template): JsonResponse
    {
        $template = $this->templates->update($template, $request->payload());

        return $this->success(__('messages.updated'), $this->templates->show($template));
    }
}
