<?php

namespace App\Http\Controllers\apis\Admin;

use App\Http\Controllers\apis\ApiController;
use App\Http\Requests\Api\Admin\AdminExternalTrainingApproveRequest;
use App\Http\Requests\Api\Admin\AdminExternalTrainingListRequest;
use App\Http\Requests\Api\Admin\AdminExternalTrainingRejectRequest;
use App\Http\Resources\AdminExternalTrainingResource;
use App\Models\Admin;
use App\Models\ExternalTrainingRequest;
use App\Services\ExternalTrainingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Dashboard External Training review (Figma 2181:116177 list, 2181:116391
 * review, 2209:90462 rejection reason). Every route is behind auth.user +
 * role:Admin + permission:view-external-training; reopening a decision also
 * needs the superAdmin role (D-057).
 */
class AdminExternalTrainingController extends ApiController
{
    public function __construct(private readonly ExternalTrainingService $service) {}

    /** GET admin/external-training - the list, with the three tiles in meta. */
    public function index(AdminExternalTrainingListRequest $request): JsonResponse
    {
        $page = $this->service->list($request->filters(), $request->perPage());

        return $this->paginated(
            __('messages.retrieved'),
            AdminExternalTrainingResource::collection($page),
            ['stats' => $this->service->stats()],
        );
    }

    /** GET admin/external-training/stats - the tiles alone, for the review page. */
    public function stats(): JsonResponse
    {
        return $this->success(__('messages.retrieved'), $this->service->stats());
    }

    /** GET admin/external-training/{externalTraining} */
    public function show(ExternalTrainingRequest $externalTraining): JsonResponse
    {
        $this->visible($externalTraining);

        return $this->success(__('messages.retrieved'), $this->detail($externalTraining));
    }

    /** POST admin/external-training/{externalTraining}/approve */
    public function approve(AdminExternalTrainingApproveRequest $request, ExternalTrainingRequest $externalTraining): JsonResponse
    {
        $this->visible($externalTraining);
        $decided = $this->service->approve($externalTraining, $this->actor($request), $request->qualificationId(), $request->courseId());

        return $this->success(__('messages.updated'), $this->detail($decided));
    }

    /** POST admin/external-training/{externalTraining}/reject */
    public function reject(AdminExternalTrainingRejectRequest $request, ExternalTrainingRequest $externalTraining): JsonResponse
    {
        $this->visible($externalTraining);
        $decided = $this->service->reject($externalTraining, $this->actor($request), $request->reason());

        return $this->success(__('messages.updated'), $this->detail($decided));
    }

    /** POST admin/external-training/{externalTraining}/reopen - super admin only. */
    public function reopen(Request $request, ExternalTrainingRequest $externalTraining): JsonResponse
    {
        $this->visible($externalTraining);
        $admin = $this->actor($request);
        abort_unless($admin !== null && $admin->hasRole('superAdmin'), 403);

        return $this->success(__('messages.updated'), $this->detail($this->service->reopen($externalTraining)));
    }

    /** GET admin/external-training/{externalTraining}/certificate */
    public function certificate(ExternalTrainingRequest $externalTraining): StreamedResponse
    {
        $this->visible($externalTraining);
        abort_unless(Storage::disk('private')->exists($externalTraining->certificate_path), 404);

        return Storage::disk('private')->download($externalTraining->certificate_path, $externalTraining->certificate_name);
    }

    private function detail(ExternalTrainingRequest $row): AdminExternalTrainingResource
    {
        return new AdminExternalTrainingResource($row->fresh(['user', 'qualification', 'course', 'decider']));
    }

    /** A withdrawn request is gone. */
    private function visible(ExternalTrainingRequest $row): void
    {
        abort_if($row->status === ExternalTrainingRequest::WITHDRAWN, 404);
    }

    private function actor(Request $request): ?Admin
    {
        $user = $request->user();

        return $user instanceof Admin ? $user : null;
    }
}
