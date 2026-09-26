<?php

namespace App\Http\Controllers\apis\Admin;

use App\Http\Controllers\apis\ApiController;
use App\Http\Requests\Api\Admin\AdminQualificationAssigneesRequest;
use App\Http\Requests\Api\Admin\AdminQualificationListRequest;
use App\Http\Requests\Api\Admin\AdminQualificationRequest;
use App\Http\Resources\AdminQualificationRowResource;
use App\Models\Admin;
use App\Models\QualificationSkill;
use App\Services\Admin\AdminQualificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Dashboard Qualifications page (Figma 2066:100159) and its New / Edit
 * modal (2066:100876). Every route is behind auth.user + role:Admin +
 * permission:view-qualifications (routes/apis/qualification_skills.php).
 *
 * These replace the Dashboard's use of the generic qualification-skills
 * write routes, which took no assignments to learners and allowed duplicate
 * names; those routes are retired (D-056).
 */
class AdminQualificationController extends ApiController
{
    public function __construct(private readonly AdminQualificationService $service) {}

    /** GET admin/qualification-skills */
    public function index(AdminQualificationListRequest $request): JsonResponse
    {
        $page = $this->service->list($request->search(), $request->perPage());

        return $this->paginated(__('messages.retrieved'), AdminQualificationRowResource::collection($page));
    }

    /** GET admin/qualification-skills/assignees?search=&type=all|job_titles|learners */
    public function assignees(AdminQualificationAssigneesRequest $request): JsonResponse
    {
        return $this->success(__('messages.retrieved'), $this->service->assignees($request->search(), $request->type()));
    }

    /** GET admin/qualification-skills/{qualification_skill} */
    public function show(QualificationSkill $qualification_skill): JsonResponse
    {
        return $this->success(__('messages.retrieved'), $this->service->show($qualification_skill));
    }

    /** POST admin/qualification-skills */
    public function store(AdminQualificationRequest $request): JsonResponse
    {
        $skill = $this->service->create($request->payload(), $this->actor($request));

        return $this->created(__('messages.created'), $this->service->show($skill));
    }

    /** PUT admin/qualification-skills/{qualification_skill} */
    public function update(AdminQualificationRequest $request, QualificationSkill $qualification_skill): JsonResponse
    {
        $skill = $this->service->update($qualification_skill, $request->payload(), $this->actor($request));

        return $this->success(__('messages.updated'), $this->service->show($skill));
    }

    /**
     * DELETE admin/qualification-skills/{qualification_skill}
     *
     * The links to courses and job titles and the direct grants go with it
     * (foreign keys cascade); courses, enrolments and certificates stay.
     */
    public function destroy(QualificationSkill $qualification_skill): JsonResponse
    {
        $qualification_skill->delete();

        return $this->deleted();
    }

    private function actor(Request $request): ?Admin
    {
        $user = $request->user();

        return $user instanceof Admin ? $user : null;
    }
}
