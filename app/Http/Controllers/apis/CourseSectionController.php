<?php

namespace App\Http\Controllers\apis;

use App\Exports\CohortScheduleTemplateExport;
use App\Http\Requests\Api\CohortScheduleUpdateRequest;
use App\Http\Requests\Api\CohortWithScheduleRequest;
use App\Http\Requests\Api\CourseSectionSyncRequest;
use App\Http\Resources\CourseSectionResource;
use App\Models\Course;
use App\Models\CourseSection;
use App\Services\CohortScheduleImportService;
use App\Services\CourseSectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use OpenApi\Annotations as OA;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CourseSectionController extends ApiController
{
    public function __construct(private readonly CourseSectionService $service) {}

    /**
     * @OA\Get(
     *     path="/courses/{course}/sections",
     *     tags={"Course Sections"},
     *     summary="List sections for a course (ordered).",
     *     security={{"BearerAuth": {}}},
     *     @OA\Parameter(ref="#/components/parameters/AcceptLanguage"),
     *     @OA\Parameter(
     *         name="course", in="path", required=true,
     *         description="Course id",
     *         @OA\Schema(type="integer", minimum=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Course sections",
     *         @OA\JsonContent(
     *             allOf={
     *                 @OA\Schema(ref="#/components/schemas/SuccessResponse"),
     *                 @OA\Schema(@OA\Property(
     *                     property="result",
     *                     type="array",
     *                     @OA\Items(ref="#/components/schemas/CourseSection")
     *                 ))
     *             }
     *         )
     *     ),
     *     @OA\Response(response=401, ref="#/components/responses/Unauthorized"),
     *     @OA\Response(response=404, ref="#/components/responses/NotFound")
     * )
     */
    public function index(Course $course): JsonResponse
    {
        $sections = $this->service->listForCourse($course);
        return $this->success(__('messages.retrieved'), CourseSectionResource::collection($sections));
    }

    /**
     * @OA\Post(
     *     path="/courses/{course}/sections",
     *     tags={"Course Sections"},
     *     summary="Create a section under a course (admin only).",
     *     security={{"BearerAuth": {}}},
     *     @OA\Parameter(
     *         name="course", in="path", required=true,
     *         description="Course id",
     *         @OA\Schema(type="integer", minimum=1)
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name"},
     *             @OA\Property(property="name",       ref="#/components/schemas/TranslatedString"),
     *             @OA\Property(property="start_date", type="string",  format="date", nullable=true),
     *             @OA\Property(property="end_date",   type="string",  format="date", nullable=true),
     *             @OA\Property(property="capacity",   type="integer", minimum=1, maximum=10000, nullable=true),
     *             @OA\Property(property="status",    type="string",  enum={"scheduled","open_for_enrollment","active","completed","inactive"}, nullable=true),
     *             @OA\Property(property="avg_session_time", type="number", format="float", minimum=0.25, maximum=24, nullable=true, description="Average session length in hours.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Created",
     *         @OA\JsonContent(
     *             allOf={
     *                 @OA\Schema(ref="#/components/schemas/SuccessResponse"),
     *                 @OA\Schema(@OA\Property(property="result", ref="#/components/schemas/CourseSection"))
     *             }
     *         )
     *     ),
     *     @OA\Response(response=401, ref="#/components/responses/Unauthorized"),
     *     @OA\Response(response=403, ref="#/components/responses/Forbidden"),
     *     @OA\Response(response=404, ref="#/components/responses/NotFound"),
     *     @OA\Response(response=422, ref="#/components/responses/ValidationError")
     * )
     */
    public function store(Course $course, Request $request): JsonResponse
    {
        $section = $this->service->create($course, $this->cohortRules($request, $course));
        return $this->created(__('messages.created'), new CourseSectionResource($section));
    }

    /**
     * GET courses/{course}/sections/schedule-template - "Download Schedule
     * Template" (Figma 2393:123167): one numbered row per planned session,
     * plus the course's modules for the "content" column (D-079).
     */
    public function scheduleTemplate(Course $course, CohortScheduleImportService $import): BinaryFileResponse
    {
        return Excel::download(
            new CohortScheduleTemplateExport((int) $course->number_of_sessions, [], $import->moduleRows($course)),
            'cohort-schedule-template.xlsx',
            ExcelFormat::XLSX,
        );
    }

    /**
     * POST courses/{course}/sections/scheduled - New Cohort with its uploaded
     * schedule (Figma 2393:122292). All-or-nothing: any bad row returns 422
     * with every problem under `report.errors` and creates nothing.
     */
    public function storeWithSchedule(Course $course, CohortWithScheduleRequest $request, CohortScheduleImportService $import): JsonResponse
    {
        $out = $import->create($course, $request->cohort(), $request->file('schedule'));

        if ($out['section'] === null) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.import_rejected'),
                'errors'  => ['schedule' => [__('messages.import_rejected')]],
                'report'  => ['errors' => $out['errors']],
            ], 422);
        }

        return $this->created(__('messages.created'), new CourseSectionResource($out['section']));
    }

    /**
     * GET courses/{course}/sections/{section}/schedule-template - the Edit
     * Cohort "Download Schedule Template": the cohort's sessions as they are,
     * then blank numbered rows for new ones.
     */
    public function sectionScheduleTemplate(Course $course, CourseSection $section, CohortScheduleImportService $import): BinaryFileResponse
    {
        abort_if($section->course_id !== $course->id, 404);

        return Excel::download(
            new CohortScheduleTemplateExport(
                (int) ($section->number_of_sessions ?? $course->number_of_sessions),
                $import->scheduleRows($section),
                $import->moduleRows($course),
            ),
            'cohort-schedule.xlsx',
            ExcelFormat::XLSX,
        );
    }

    /**
     * POST courses/{course}/sections/{section}/scheduled - Edit Cohort in the
     * New Cohort dialog: names, capacity and optionally the schedule again,
     * of which only the new sessions are added (CohortScheduleImportService::update).
     * All-or-nothing, with every problem under `report.errors`.
     */
    public function updateWithSchedule(Course $course, CourseSection $section, CohortScheduleUpdateRequest $request, CohortScheduleImportService $import): JsonResponse
    {
        abort_if($section->course_id !== $course->id, 404);

        $out = $import->update($course, $section, $request->cohort(), $request->file('schedule'));

        if ($out['section'] === null) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.import_rejected'),
                'errors'  => ['schedule' => [__('messages.import_rejected')]],
                'report'  => ['errors' => $out['errors']],
            ], 422);
        }

        return $this->success(__('messages.updated'), [
            'section'          => new CourseSectionResource($out['section']),
            'sessions_added'   => $out['added'],
            'sessions_updated' => $out['updated'],
        ]);
    }

    /**
     * @OA\Post(
     *     path="/courses/{course}/sections/sync",
     *     tags={"Course Sections"},
     *     summary="Bulk replace sections for a course (admin only). Pass full list; existing sections matched by id are kept, others are deleted.",
     *     security={{"BearerAuth": {}}},
     *     @OA\Parameter(
     *         name="course", in="path", required=true,
     *         description="Course id",
     *         @OA\Schema(type="integer", minimum=1)
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"sections"},
     *             @OA\Property(
     *                 property="sections",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     required={"name"},
     *                     @OA\Property(property="id",   type="integer", nullable=true, description="Existing section id to update; omit to create."),
     *                     @OA\Property(property="name", ref="#/components/schemas/TranslatedString")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Synced",
     *         @OA\JsonContent(
     *             allOf={
     *                 @OA\Schema(ref="#/components/schemas/SuccessResponse"),
     *                 @OA\Schema(@OA\Property(
     *                     property="result",
     *                     type="array",
     *                     @OA\Items(ref="#/components/schemas/CourseSection")
     *                 ))
     *             }
     *         )
     *     ),
     *     @OA\Response(response=401, ref="#/components/responses/Unauthorized"),
     *     @OA\Response(response=403, ref="#/components/responses/Forbidden"),
     *     @OA\Response(response=404, ref="#/components/responses/NotFound"),
     *     @OA\Response(response=422, ref="#/components/responses/ValidationError")
     * )
     */
    public function sync(Course $course, CourseSectionSyncRequest $request): JsonResponse
    {
        $sections = $this->service->sync($course, $request->validated()['sections']);
        return $this->success(__('messages.updated'), CourseSectionResource::collection($sections));
    }

    /**
     * @OA\Put(
     *     path="/courses/{course}/sections/{section}",
     *     tags={"Course Sections"},
     *     summary="Update a section (admin only).",
     *     security={{"BearerAuth": {}}},
     *     @OA\Parameter(
     *         name="course", in="path", required=true,
     *         description="Course id",
     *         @OA\Schema(type="integer", minimum=1)
     *     ),
     *     @OA\Parameter(
     *         name="section", in="path", required=true,
     *         description="Section id",
     *         @OA\Schema(type="integer", minimum=1)
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name"},
     *             @OA\Property(property="name",       ref="#/components/schemas/TranslatedString"),
     *             @OA\Property(property="start_date", type="string",  format="date", nullable=true),
     *             @OA\Property(property="end_date",   type="string",  format="date", nullable=true),
     *             @OA\Property(property="capacity",   type="integer", minimum=1, maximum=10000, nullable=true),
     *             @OA\Property(property="status",    type="string",  enum={"scheduled","open_for_enrollment","active","completed","inactive"}, nullable=true),
     *             @OA\Property(property="avg_session_time", type="number", format="float", minimum=0.25, maximum=24, nullable=true, description="Average session length in hours.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Updated",
     *         @OA\JsonContent(
     *             allOf={
     *                 @OA\Schema(ref="#/components/schemas/SuccessResponse"),
     *                 @OA\Schema(@OA\Property(property="result", ref="#/components/schemas/CourseSection"))
     *             }
     *         )
     *     ),
     *     @OA\Response(response=401, ref="#/components/responses/Unauthorized"),
     *     @OA\Response(response=403, ref="#/components/responses/Forbidden"),
     *     @OA\Response(response=404, ref="#/components/responses/NotFound"),
     *     @OA\Response(response=422, ref="#/components/responses/ValidationError")
     * )
     */
    public function update(Course $course, CourseSection $section, Request $request): JsonResponse
    {
        abort_if($section->course_id !== $course->id, 404);
        $updated = $this->service->update($section, $this->cohortRules($request));
        return $this->success(__('messages.updated'), new CourseSectionResource($updated));
    }

    /**
     * Validate the shared store/update payload for a cohort (= course
     * section). Centralised so the rules don't drift between endpoints.
     *
     * The legacy clients only sent `name`; the new Figma cohort dialog
     * adds start/end dates, capacity and a status enum. Every new field
     * is `nullable` so older callers don't 422.
     */
    private function cohortRules(Request $request, ?Course $creatingFor = null): array
    {
        return $request->validate([
            'name'        => 'required|array',
            'name.ar'     => 'required|string|max:255',
            'name.en'     => 'nullable|string|max:255',
            'start_date'  => 'nullable|date',
            // end_date may equal start_date for a one-shot cohort, hence
            // `after_or_equal` rather than `after`.
            'end_date'    => 'nullable|date|after_or_equal:start_date',
            'capacity'    => 'nullable|integer|min:1|max:10000',
            // Admins only ever set the two enrolment-window choices; the
            // calendar drives `active`/`completed`. We still accept the
            // derived values so a round-tripped edit doesn't 422.
            'status'      => 'nullable|string|in:scheduled,open_for_enrollment,active,completed,inactive',
            // Planned session count for this cohort. Defaults from the
            // parent course on create; editable per cohort. Drives
            // session-based completion (see Course::deriveCohortStatus).
            // Courses made from the D6 modal carry no plan of their own, so
            // their cohorts must state one - without it attendance can never
            // be measured.
            'number_of_sessions' => ($creatingFor && ! $creatingFor->number_of_sessions ? 'required' : 'nullable')
                .'|integer|min:1|max:1000',
            // Average session length in hours (e.g. 1.5). Drives the live
            // attendance-window length for this cohort's sessions.
            'avg_session_time' => 'nullable|numeric|min:0.25|max:24',
        ]);
    }

    /**
     * @OA\Delete(
     *     path="/courses/{course}/sections/{section}",
     *     tags={"Course Sections"},
     *     summary="Delete a section (admin only).",
     *     security={{"BearerAuth": {}}},
     *     @OA\Parameter(
     *         name="course", in="path", required=true,
     *         description="Course id",
     *         @OA\Schema(type="integer", minimum=1)
     *     ),
     *     @OA\Parameter(
     *         name="section", in="path", required=true,
     *         description="Section id",
     *         @OA\Schema(type="integer", minimum=1)
     *     ),
     *     @OA\Response(response=200, description="Deleted", @OA\JsonContent(ref="#/components/schemas/EmptyResponse")),
     *     @OA\Response(response=401, ref="#/components/responses/Unauthorized"),
     *     @OA\Response(response=403, ref="#/components/responses/Forbidden"),
     *     @OA\Response(response=404, ref="#/components/responses/NotFound")
     * )
     */
    public function destroy(Course $course, CourseSection $section): JsonResponse
    {
        abort_if($section->course_id !== $course->id, 404);
        $this->service->delete($section);
        return $this->deleted(__('messages.deleted'));
    }
}
