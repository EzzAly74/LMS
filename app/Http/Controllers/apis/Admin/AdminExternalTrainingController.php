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
use Illuminate\Support\Facades\URL;
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

    /** GET admin/external-training/options - qualifications and courses for the review pickers. */
    public function options(): JsonResponse
    {
        return $this->success(__('messages.retrieved'), $this->service->options());
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

    /** How long a certificate download link works. */
    private const LINK_MINUTES = 5;

    /**
     * GET admin/external-training/{externalTraining}/certificate-link - a
     * signed link to the file, valid for a few minutes (D-071).
     *
     * The Dashboard opens it as a plain browser download instead of fetching
     * the file with the bearer token: download managers (IDM) take over PDF
     * responses and abort the page's own request, which then failed with no
     * file. A link needs no token, so whoever handles the download succeeds.
     */
    public function certificateLink(ExternalTrainingRequest $externalTraining): JsonResponse
    {
        $this->visible($externalTraining);
        $expires = now()->addMinutes(self::LINK_MINUTES);

        return $this->success(__('messages.retrieved'), [
            'url'        => URL::temporarySignedRoute('admin.external-training.certificate.file', $expires, ['externalTraining' => $externalTraining->id]),
            'expires_at' => $expires->toIso8601String(),
        ]);
    }

    /** GET external-training-files/{externalTraining}?expires&signature - the signed link's file. */
    public function certificateFile(ExternalTrainingRequest $externalTraining): StreamedResponse
    {
        return $this->certificate($externalTraining);
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
