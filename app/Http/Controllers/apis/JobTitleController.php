<?php

namespace App\Http\Controllers\apis;

use App\Http\Resources\JobTitleResource;
use App\Http\Requests\Api\Admin\JobTitleLearnersRequest;
use App\Http\Resources\Admin\JobTitleLearnerResource;
use App\Models\JobTitle;
use App\Services\JobTitleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JobTitleController extends ApiController
{
    public function __construct(private readonly JobTitleService $service) {}

    /**
     * Paginated list of job titles (admin/user view).
     */
    public function index(Request $request): JsonResponse
    {
        $jobTitles = $this->service->list(
            perPage: (int) $request->get('per_page', 15),
            search:  $request->get('search'),
        );

        return $this->paginated(
            __('messages.retrieved'),
            JobTitleResource::collection($jobTitles),
        );
    }

    /**
     * All job titles for select dropdowns (public).
     */
    public function activeList(): JsonResponse
    {
        return $this->success(
            __('messages.retrieved'),
            JobTitleResource::collection($this->service->allForSelect()),
        );
    }

    /**
     * Show a single job title with its qualification skills.
     */
    public function show(JobTitle $job_title): JsonResponse
    {
        return $this->success(
            __('messages.retrieved'),
            new JobTitleResource(
                $job_title->loadCount('qualificationSkills')->load('qualificationSkills'),
            ),
        );
    }

    /**
     * Sync qualification skills assigned to a job title (admin only).
     */
    public function syncQualifications(Request $request, JobTitle $job_title): JsonResponse
    {
        $request->validate([
            'qualification_skill_ids'   => ['required', 'array'],
            'qualification_skill_ids.*' => ['integer', 'exists:qualification_skills,id'],
        ]);

        $jobTitle = $this->service->syncQualifications(
            $job_title,
            $request->input('qualification_skill_ids', []),
        );

        return $this->success(
            __('messages.updated'),
            new JobTitleResource($jobTitle->loadCount('qualificationSkills')),
        );
    }

    /**
     * GET admin/job-titles/{job_title}/learners
     *
     * Learners holding this job title with their progress toward its required
     * qualifications. Powers the job-title detail table (Figma 2325:117118),
     * which had no endpoint at all.
     */
    public function learners(JobTitle $job_title, JobTitleLearnersRequest $request): JsonResponse
    {
        $learners = $this->service->learnersFor(
            jobTitle: $job_title,
            perPage:  $request->perPage(),
            search:   $request->input('search'),
            sort:     $request->sort(),
            dir:      $request->direction(),
        );

        return $this->paginated(
            __('messages.retrieved'),
            JobTitleLearnerResource::collection($learners),
        );
    }
}
