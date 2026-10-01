<?php

namespace App\Http\Controllers\apis\Admin;

use App\Http\Controllers\apis\ApiController;
use App\Http\Requests\Api\Admin\DashboardAccountStoreRequest;
use App\Http\Requests\Api\Admin\DashboardAccountUpdateRequest;
use App\Http\Resources\Admin\AdminUserDetailResource;
use App\Http\Resources\Admin\AdminUserListResource;
use App\Services\Admin\DashboardAccountService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Users screen (D-075): Dashboard accounts only. Website learners have
 * their own screen and endpoints (AdminLearnerController, section learners)
 * and sign in through HR.
 *
 * The item routes keep their `{source}/{id}` shape; `source` is always
 * `admin`, the only kind of account that signs in to the Dashboard.
 */
class AdminUserController extends ApiController
{
    public function __construct(private readonly DashboardAccountService $accounts) {}

    /** GET /api/v1/admin/users */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'role'     => ['sometimes', 'nullable', 'string', 'max:191'],
            'status'   => ['sometimes', 'nullable', 'in:active,inactive,deactivated'],
            'search'   => ['sometimes', 'nullable', 'string', 'max:100'],
            'page'     => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $role = isset($data['role']) && ! in_array($data['role'], ['', 'all'], true) ? $data['role'] : null;

        $page = $this->accounts->paginate(
            role:    $role,
            status:  $data['status'] ?? null,
            search:  $data['search'] ?? null,
            perPage: (int) ($data['per_page'] ?? 15),
        );

        return $this->paginated(__('messages.retrieved'), AdminUserListResource::collection($page));
    }

    /** GET /api/v1/admin/users/summary */
    public function summary(): JsonResponse
    {
        return $this->success(__('messages.retrieved'), $this->accounts->summary());
    }

    /** GET /api/v1/admin/users/filter-options */
    public function filterOptions(Request $request): JsonResponse
    {
        return $this->success(__('messages.retrieved'), $this->accounts->filterOptions($request->user()));
    }

    /** GET /api/v1/admin/users/admin/{id} */
    public function show(string $source, int $id): JsonResponse
    {
        try {
            return $this->success(__('messages.retrieved'), new AdminUserDetailResource($this->accounts->show($id)));
        } catch (ModelNotFoundException) {
            return $this->notFound();
        }
    }

    /** POST /api/v1/admin/users */
    public function store(DashboardAccountStoreRequest $request): JsonResponse
    {
        $row = $this->accounts->create($request->user(), $request->validated());

        return $this->created(__('messages.created'), new AdminUserDetailResource($row));
    }

    /** PUT|POST /api/v1/admin/users/admin/{id} */
    public function update(DashboardAccountUpdateRequest $request, string $source, int $id): JsonResponse
    {
        try {
            $row = $this->accounts->update($request->user(), $id, $request->validated());
        } catch (ModelNotFoundException) {
            return $this->notFound();
        }

        return $this->success(__('messages.updated'), new AdminUserDetailResource($row));
    }

    /** DELETE /api/v1/admin/users/admin/{id}: deactivate, never delete. */
    public function destroy(Request $request, string $source, int $id): JsonResponse
    {
        try {
            $row = $this->accounts->deactivate($request->user(), $id);
        } catch (ModelNotFoundException) {
            return $this->notFound();
        }

        return $this->success(__('messages.updated'), new AdminUserDetailResource($row));
    }

    /** PATCH /api/v1/admin/users/admin/{id}/reactivate */
    public function reactivate(Request $request, string $source, int $id): JsonResponse
    {
        try {
            $row = $this->accounts->reactivate($request->user(), $id);
        } catch (ModelNotFoundException) {
            return $this->notFound();
        }

        return $this->success(__('messages.updated'), new AdminUserDetailResource($row));
    }
}
