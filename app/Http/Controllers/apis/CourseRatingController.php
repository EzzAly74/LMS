<?php

namespace App\Http\Controllers\apis;

use App\Http\Requests\Api\SubmitCourseRatingRequest;
use App\Http\Resources\CourseRatingResource;
use App\Models\Course;
use App\Services\CourseRatingService;
use Illuminate\Http\JsonResponse;
use OpenApi\Annotations as OA;

class CourseRatingController extends ApiController
{
    public function __construct(private readonly CourseRatingService $ratingService) {}

    /**
     * @OA\Post(
     *     path="/courses/{course}/ratings",
     *     tags={"Course Ratings"},
     *     summary="Submit or update the authenticated user's rating for a course.",
     *     security={{"BearerAuth": {}}},
     *     @OA\Parameter(
     *         name="course", in="path", required=true,
     *         description="Course id",
     *         @OA\Schema(type="integer", minimum=1)
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"rating"},
     *             @OA\Property(property="rating", type="integer", minimum=1, maximum=5),
     *             @OA\Property(property="review", type="string", maxLength=1000, nullable=true)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Rating saved",
     *         @OA\JsonContent(
     *             allOf={
     *                 @OA\Schema(ref="#/components/schemas/SuccessResponse"),
     *                 @OA\Schema(@OA\Property(property="result", ref="#/components/schemas/CourseRating"))
     *             }
     *         )
     *     ),
     *     @OA\Response(response=401, ref="#/components/responses/Unauthorized"),
     *     @OA\Response(response=403, ref="#/components/responses/Forbidden"),
     *     @OA\Response(response=404, ref="#/components/responses/NotFound"),
     *     @OA\Response(response=422, ref="#/components/responses/ValidationError")
     * )
     */
    public function store(SubmitCourseRatingRequest $request, Course $course): JsonResponse
    {
        $rating = $this->ratingService->submitRating($course, $request->user()->id, $request->validated());

        return $this->success(__('messages.created'), new CourseRatingResource($rating->load('user')));
    }

}
