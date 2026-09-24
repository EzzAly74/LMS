<?php

namespace App\Http\Controllers\apis\Admin;

use App\Http\Controllers\apis\ApiController;
use App\Http\Requests\Api\Admin\AdminEvaluationScoresRequest;
use App\Http\Resources\Admin\AdminEvaluationScoreRowResource;
use App\Models\Course;
use App\Models\EvaluationCategory;
use App\Models\User;
use App\Services\Admin\AdminEvaluationReportService;
use Illuminate\Http\JsonResponse;

/**
 * Admin evaluation reporting (Stage B / B3).
 *
 * Four read-only aggregates that had no endpoint: the course overview
 * histogram, the per-template question distribution, the learner-scores list,
 * and one learner's submission.
 */
class AdminEvaluationReportController extends ApiController
{
    public function __construct(private readonly AdminEvaluationReportService $service) {}

    /** GET admin/courses/{course}/evaluation-summary — Figma 2266:128869. */
    public function courseSummary(Course $course): JsonResponse
    {
        return $this->success(__('messages.retrieved'), $this->service->courseSummary($course));
    }

    /** GET admin/evaluations/{template}/results — Figma 2169:108198. */
    public function templateResults(EvaluationCategory $template): JsonResponse
    {
        return $this->success(__('messages.retrieved'), $this->service->templateResults($template));
    }

    /** GET admin/evaluations/scores — Figma 2017:52260. */
    public function scores(AdminEvaluationScoresRequest $request): JsonResponse
    {
        $page = $this->service->scores(
            perPage:    $request->perPage(),
            courseId:   $request->integer('course_id') ?: null,
            templateId: $request->integer('template_id') ?: null,
            search:     $request->string('search')->toString() ?: null,
        );

        return $this->paginated(__('messages.retrieved'), AdminEvaluationScoreRowResource::collection($page));
    }

    /**
     * GET admin/evaluations/scores/{learner}/{course} — Figma 2169:108801 / 2169:109264.
     *
     * Addressed by the natural key: user_course_evaluations has no submission
     * id, a submission being the rows sharing (user, course, template).
     */
    public function submission(User $learner, Course $course, AdminEvaluationScoresRequest $request): JsonResponse
    {
        $submission = $this->service->submission(
            $learner->id,
            $course->id,
            $request->integer('template_id') ?: null,
        );

        abort_if($submission === null, 404);

        return $this->success(__('messages.retrieved'), $submission);
    }
}
