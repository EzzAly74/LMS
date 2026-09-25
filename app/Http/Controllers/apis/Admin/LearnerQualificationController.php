<?php

namespace App\Http\Controllers\apis\Admin;

use App\Http\Controllers\apis\ApiController;
use App\Http\Requests\Api\Admin\BulkGrantQualificationRequest;
use App\Http\Requests\Api\Admin\GrantLearnerQualificationRequest;
use App\Models\Admin;
use App\Models\QualificationSkill;
use App\Models\User;
use App\Services\Admin\LearnerQualificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Direct learner→qualification grants (D-045).
 *
 * Two entry points in the design, one service:
 *   - Figma 2066:100876 — the New Qualification modal's learner picker
 *   - Q-038 — "Assign Qualification" as a bulk action on the learners list
 */
class LearnerQualificationController extends ApiController
{
    public function __construct(private readonly LearnerQualificationService $service) {}

    /**
     * The admin performing the grant, for the audit trail.
     *
     * AuthenticationMiddleware populates the request's user resolver rather
     * than an Auth guard, so the principal is read from the request. It is
     * null-checked because a grant must still record correctly even if the
     * principal cannot be resolved — an unattributed grant is better than a
     * failed one, and the row's timestamps still bound when it happened.
     */
    private function actor(Request $request): ?Admin
    {
        $user = $request->user();

        return $user instanceof Admin ? $user : null;
    }

    /** GET admin/learners/{learner}/qualifications */
    public function index(User $learner): JsonResponse
    {
        return $this->success(__('messages.retrieved'), [
            'granted' => $this->service->heldBy($learner)->all(),
        ]);
    }

    /** POST admin/learners/{learner}/qualifications */
    public function store(User $learner, GrantLearnerQualificationRequest $request): JsonResponse
    {
        $result = $this->service->grant(
            $learner,
            $request->skillIds(),
            $this->actor($request),
            $request->note(),
        );

        return $this->created(__('messages.created'), $result);
    }

    /** DELETE admin/learners/{learner}/qualifications/{qualificationSkill} */
    public function destroy(User $learner, QualificationSkill $qualificationSkill): JsonResponse
    {
        $removed = $this->service->revoke($learner, $qualificationSkill);

        if (! $removed) {
            return $this->notFound(__('messages.not_found'));
        }

        return $this->deleted(__('messages.deleted'));
    }

    /** POST admin/qualification-skills/{qualificationSkill}/learners — bulk assign. */
    public function bulkStore(QualificationSkill $qualificationSkill, BulkGrantQualificationRequest $request): JsonResponse
    {
        $result = $this->service->grantToMany(
            $qualificationSkill,
            $request->userIds(),
            $this->actor($request),
            $request->note(),
        );

        return $this->created(__('messages.created'), $result);
    }
}
