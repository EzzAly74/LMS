<?php

namespace App\Http\Controllers\apis\Admin;

use App\Http\Controllers\apis\ApiController;
use App\Http\Requests\Api\Admin\AdminEvaluationLearnerOptionsRequest;
use App\Http\Requests\Api\Admin\AdminEvaluationScoresRequest;
use App\Http\Requests\Api\Admin\AdminEvaluationTemplatesRequest;
use App\Http\Resources\Admin\AdminEvaluationScoreRowResource;
use App\Http\Resources\Admin\AdminEvaluationTemplateRowResource;
use App\Models\Course;
use App\Models\EvaluationCategory;
use App\Models\User;
use App\Services\Admin\AdminEvaluationReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin evaluation reporting (Stage B / B3, extended for D4).
 *
 * Read-only aggregates: the templates list, the course overview histogram, the
 * per-template question distribution, the learner-scores list, one learner's
 * submission, and the choices behind the list filters.
 */
class AdminEvaluationReportController extends ApiController
{
    public function __construct(private readonly AdminEvaluationReportService $service) {}

    /** GET admin/evaluations/templates - Figma 2009:88432. */
    public function templates(AdminEvaluationTemplatesRequest $request): JsonResponse
    {
        $page = $this->service->templates($request->filters(), $request->perPage());

        return $this->paginated(__('messages.retrieved'), AdminEvaluationTemplateRowResource::collection($page));
    }

    /** GET admin/evaluations/filter-options - the Instructors and Courses chips. */
    public function filterOptions(): JsonResponse
    {
        return $this->success(__('messages.retrieved'), $this->service->filterOptions());
    }

    /** GET admin/evaluations/learner-options - the Learners chip on 2017:52260. */
    public function learnerOptions(AdminEvaluationLearnerOptionsRequest $request): JsonResponse
    {
        $page = $this->service->learnerOptions($request->string('search')->toString() ?: null, $request->perPage());
        $page->getCollection()->transform(fn ($u) => [
            'id'          => (int) $u->id,
            'name'        => $u->name,
            'employee_id' => $u->machine_code,
        ]);

        return $this->paginated(__('messages.retrieved'), JsonResource::collection($page));
    }

    /** GET admin/courses/{course}/evaluation-summary - Figma 2266:128869. */
    public function courseSummary(Course $course): JsonResponse
    {
        return $this->success(__('messages.retrieved'), $this->service->courseSummary($course));
    }

    /** GET admin/evaluations/{template}/results - Figma 2169:108198. */
    public function templateResults(EvaluationCategory $template): JsonResponse
    {
        return $this->success(__('messages.retrieved'), $this->service->templateResults($template));
    }

    /** GET admin/evaluations/scores - Figma 2017:52260. */
    public function scores(AdminEvaluationScoresRequest $request): JsonResponse
    {
        $page = $this->service->scores($request->filters(), $request->perPage());

        return $this->paginated(__('messages.retrieved'), AdminEvaluationScoreRowResource::collection($page));
    }

    /**
     * GET admin/evaluations/scores/{learner}/{course} - Figma 2169:108801 / 2169:109264.
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
