<?php

namespace App\Http\Controllers\apis\Admin;

use App\Http\Controllers\apis\ApiController;
use App\Http\Requests\Api\Admin\AdminLearnerTableRequest;
use App\Http\Resources\Admin\AdminLearnerCourseRowResource;
use App\Http\Resources\Admin\AdminLearnerPerformanceRowResource;
use App\Models\User;
use App\Services\Admin\AdminLearnerProfileService;
use Illuminate\Http\JsonResponse;

/**
 * Admin learner detail (Figma 2181:115043).
 *
 * Split into three endpoints rather than one fat payload because the design
 * shows the two tables with independent pagers — "1–4 of 6" under each. A
 * single endpoint would need two page parameters and would re-send the profile
 * and tiles every time either table paged.
 */
class AdminLearnerProfileController extends ApiController
{
    public function __construct(private readonly AdminLearnerProfileService $service) {}

    /** GET admin/learners/{learner} — profile card + the five summary tiles. */
    public function show(User $learner): JsonResponse
    {
        return $this->success(__('messages.retrieved'), $this->service->profile($learner));
    }

    /** GET admin/learners/{learner}/courses — "Active & Completed Courses". */
    public function courses(User $learner, AdminLearnerTableRequest $request): JsonResponse
    {
        return $this->paginated(
            __('messages.retrieved'),
            AdminLearnerCourseRowResource::collection(
                $this->service->courses($learner, $request->perPage()),
            ),
        );
    }

    /** GET admin/learners/{learner}/performance — "Quizzes / Assignments Performance". */
    public function performance(User $learner, AdminLearnerTableRequest $request): JsonResponse
    {
        return $this->paginated(
            __('messages.retrieved'),
            AdminLearnerPerformanceRowResource::collection(
                $this->service->performance($learner, $request->perPage()),
            ),
        );
    }
}
