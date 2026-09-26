<?php

namespace App\Http\Controllers\apis;

use App\Http\Resources\QualificationSkillResource;
use App\Models\QualificationSkill;
use App\Services\QualificationSkillService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Annotations as OA;

class QualificationSkillController extends ApiController
{
    public function __construct(private readonly QualificationSkillService $service) {}

    /**
     * @OA\Get(
     *     path="/qualification-skills",
     *     tags={"Qualification Skills"},
     *     summary="List qualification skills (paginated).",
     *     security={{"BearerAuth": {}}},
     *     @OA\Parameter(ref="#/components/parameters/AcceptLanguage"),
     *     @OA\Parameter(ref="#/components/parameters/Page"),
     *     @OA\Parameter(ref="#/components/parameters/PerPage"),
     *     @OA\Parameter(ref="#/components/parameters/Search"),
     *     @OA\Response(
     *         response=200,
     *         description="Paginated qualification skills",
     *         @OA\JsonContent(
     *             allOf={
     *                 @OA\Schema(ref="#/components/schemas/SuccessResponse"),
     *                 @OA\Schema(@OA\Property(
     *                     property="result",
     *                     type="array",
     *                     @OA\Items(ref="#/components/schemas/QualificationSkill")
     *                 ))
     *             }
     *         )
     *     ),
     *     @OA\Response(response=401, ref="#/components/responses/Unauthorized")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $skills = $this->service->list(
            perPage: (int) $request->get('per_page', 15),
            search:  $request->get('search'),
        );

        return $this->paginated(__('messages.retrieved'), QualificationSkillResource::collection($skills));
    }

    /**
     * @OA\Get(
     *     path="/qualification-skills/active",
     *     tags={"Qualification Skills"},
     *     summary="List ALL qualification skills (no pagination). For select dropdowns.",
     *     @OA\Parameter(ref="#/components/parameters/AcceptLanguage"),
     *     @OA\Response(
     *         response=200,
     *         description="All qualification skills",
     *         @OA\JsonContent(
     *             allOf={
     *                 @OA\Schema(ref="#/components/schemas/SuccessResponse"),
     *                 @OA\Schema(@OA\Property(
     *                     property="result",
     *                     type="array",
     *                     @OA\Items(ref="#/components/schemas/QualificationSkill")
     *                 ))
     *             }
     *         )
     *     )
     * )
     */
    public function activeList(): JsonResponse
    {
        return $this->success(
            __('messages.retrieved'),
            QualificationSkillResource::collection($this->service->allForSelect()),
        );
    }

    /**
     * @OA\Get(
     *     path="/qualification-skills/{qualification_skill}",
     *     tags={"Qualification Skills"},
     *     summary="Show a qualification skill (with courses_count).",
     *     security={{"BearerAuth": {}}},
     *     @OA\Parameter(ref="#/components/parameters/AcceptLanguage"),
     *     @OA\Parameter(name="qualification_skill", in="path", required=true, @OA\Schema(type="integer", minimum=1)),
     *     @OA\Response(
     *         response=200,
     *         description="Qualification skill",
     *         @OA\JsonContent(
     *             allOf={
     *                 @OA\Schema(ref="#/components/schemas/SuccessResponse"),
     *                 @OA\Schema(@OA\Property(property="result", ref="#/components/schemas/QualificationSkill"))
     *             }
     *         )
     *     ),
     *     @OA\Response(response=401, ref="#/components/responses/Unauthorized"),
     *     @OA\Response(response=404, ref="#/components/responses/NotFound")
     * )
     */
    public function show(QualificationSkill $qualification_skill): JsonResponse
    {
        return $this->success(
            __('messages.retrieved'),
            new QualificationSkillResource($qualification_skill->loadCount('courses')),
        );
    }
}
