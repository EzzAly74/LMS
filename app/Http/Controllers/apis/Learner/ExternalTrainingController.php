<?php

namespace App\Http\Controllers\apis\Learner;

use App\Http\Controllers\apis\ApiController;
use App\Http\Requests\Api\Learner\ExternalTrainingRequestForm;
use App\Http\Resources\ExternalTrainingResource;
use App\Models\ExternalTrainingRequest;
use App\Models\User;
use App\Services\ExternalTrainingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The learner's own External Training requests (Website, Figma 2201:83481,
 * 2201:84534). Every action is scoped to the signed-in learner: another
 * learner's request is a 404, never a 403, so ids cannot be probed.
 */
class ExternalTrainingController extends ApiController
{
    public function __construct(private readonly ExternalTrainingService $service) {}

    /** GET learner/external-training */
    public function index(Request $request): JsonResponse
    {
        return $this->success(
            __('messages.retrieved'),
            ExternalTrainingResource::collection($this->service->mine($this->learner($request))),
        );
    }

    /** GET learner/external-training/{externalTraining} */
    public function show(Request $request, ExternalTrainingRequest $externalTraining): JsonResponse
    {
        $this->own($request, $externalTraining);

        return $this->success(__('messages.retrieved'), new ExternalTrainingResource($externalTraining->load(['qualification', 'course'])));
    }

    /** POST learner/external-training */
    public function store(ExternalTrainingRequestForm $request): JsonResponse
    {
        $created = $this->service->create($this->learner($request), $request->fields(), $request->file('certificate'));

        return $this->created(__('messages.created'), new ExternalTrainingResource($created));
    }

    /** POST learner/external-training/{externalTraining} - multipart edit of a pending request. */
    public function update(ExternalTrainingRequestForm $request, ExternalTrainingRequest $externalTraining): JsonResponse
    {
        $this->own($request, $externalTraining);
        $updated = $this->service->update($externalTraining, $request->fields(), $request->file('certificate'));

        return $this->success(__('messages.updated'), new ExternalTrainingResource($updated->load(['qualification', 'course'])));
    }

    /** DELETE learner/external-training/{externalTraining} - withdraw a pending request. */
    public function destroy(Request $request, ExternalTrainingRequest $externalTraining): JsonResponse
    {
        $this->own($request, $externalTraining);
        $this->service->withdraw($externalTraining);

        return $this->deleted();
    }

    /** GET learner/external-training/{externalTraining}/certificate - the learner's own upload. */
    public function certificate(Request $request, ExternalTrainingRequest $externalTraining): StreamedResponse
    {
        $this->own($request, $externalTraining);
        abort_unless(Storage::disk('private')->exists($externalTraining->certificate_path), 404);

        return Storage::disk('private')->download($externalTraining->certificate_path, $externalTraining->certificate_name);
    }

    private function learner(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /** Owner only; a withdrawn request no longer exists for anyone. */
    private function own(Request $request, ExternalTrainingRequest $row): void
    {
        abort_unless(
            (int) $row->user_id === (int) $this->learner($request)->id
                && $row->status !== ExternalTrainingRequest::WITHDRAWN,
            404,
        );
    }
}
