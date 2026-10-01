<?php

namespace App\Http\Controllers\apis;

use App\Http\Requests\Api\RoleRequest;
use App\Http\Resources\RoleResource;
use App\Services\Admin\RoleAuthority;
use App\Services\RoleService;
use App\Support\Permissions\AdminSections;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Annotations as OA;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends ApiController
{
    public function __construct(
        private readonly RoleService $service,
        private readonly RoleAuthority $authority,
    ) {}

    /**
     * The legacy endpoints follow the Roles screen's rules (D-073): a role's
     * matrix permissions go through RoleAuthority, and only a super admin
     * may set the legacy non-matrix names.
     *
     * @param list<string> $requested
     * @param list<string> $current
     * @return list<string>
     */
    private function permittedPermissions(Request $request, array $requested, array $current = []): array
    {
        $actor = $request->user();
        $matrix = AdminSections::permissionNames();
        $legacy = array_values(array_diff($requested, $matrix));

        if ($legacy !== [] && ! $actor->isSuperAdmin()) {
            abort(403, __('messages.role_escalation'));
        }

        $resolved = $this->authority->resolvePermissions(
            $actor,
            array_values(array_intersect($requested, $matrix)),
            array_values(array_intersect($current, $matrix)),
        );

        return array_values(array_unique(array_merge($resolved, $legacy)));
    }

    /**
     * @OA\Get(
     *     path="/roles",
     *     tags={"Roles"},
     *     summary="List roles (paginated).",
     *     security={{"BearerAuth": {}}},
     *     @OA\Parameter(ref="#/components/parameters/AcceptLanguage"),
     *     @OA\Parameter(ref="#/components/parameters/Page"),
     *     @OA\Parameter(ref="#/components/parameters/PerPage"),
     *     @OA\Parameter(ref="#/components/parameters/Search"),
     *     @OA\Response(
     *         response=200,
     *         description="Paginated roles",
     *         @OA\JsonContent(
     *             allOf={
     *                 @OA\Schema(ref="#/components/schemas/SuccessResponse"),
     *                 @OA\Schema(@OA\Property(
     *                     property="result",
     *                     type="array",
     *                     @OA\Items(ref="#/components/schemas/Role")
     *                 ))
     *             }
     *         )
     *     ),
     *     @OA\Response(response=401, ref="#/components/responses/Unauthorized"),
     *     @OA\Response(response=403, ref="#/components/responses/Forbidden")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $roles = $this->service->paginate(
            (int) $request->get('per_page', 20),
            $request->get('search')
        );
        return $this->paginated(__('messages.retrieved'), RoleResource::collection($roles));
    }

    /**
     * @OA\Get(
     *     path="/roles/all",
     *     tags={"Roles"},
     *     summary="List ALL roles (no pagination). For select dropdowns.",
     *     security={{"BearerAuth": {}}},
     *     @OA\Parameter(ref="#/components/parameters/AcceptLanguage"),
     *     @OA\Response(
     *         response=200,
     *         description="All roles",
     *         @OA\JsonContent(
     *             allOf={
     *                 @OA\Schema(ref="#/components/schemas/SuccessResponse"),
     *                 @OA\Schema(@OA\Property(
     *                     property="result",
     *                     type="array",
     *                     @OA\Items(ref="#/components/schemas/Role")
     *                 ))
     *             }
     *         )
     *     ),
     *     @OA\Response(response=401, ref="#/components/responses/Unauthorized"),
     *     @OA\Response(response=403, ref="#/components/responses/Forbidden")
     * )
     */
    public function all(): JsonResponse
    {
        return $this->success(__('messages.retrieved'),
            RoleResource::collection($this->service->all())
        );
    }

    /**
     * @OA\Get(
     *     path="/permissions",
     *     tags={"Roles"},
     *     summary="List every permission available in the system, grouped by table.",
     *     security={{"BearerAuth": {}}},
     *     @OA\Response(
     *         response=200,
     *         description="All permissions grouped by their table",
     *         @OA\JsonContent(
     *             allOf={
     *                 @OA\Schema(ref="#/components/schemas/SuccessResponse"),
     *                 @OA\Schema(@OA\Property(
     *                     property="result",
     *                     type="array",
     *                     @OA\Items(
     *                         @OA\Property(property="table",       type="string"),
     *                         @OA\Property(property="permissions", type="array", @OA\Items(type="string"))
     *                     )
     *                 ))
     *             }
     *         )
     *     ),
     *     @OA\Response(response=401, ref="#/components/responses/Unauthorized"),
     *     @OA\Response(response=403, ref="#/components/responses/Forbidden")
     * )
     */
    public function permissions(Request $request): JsonResponse
    {
        $guard = $request->query('guard');

        $grouped = Permission::query()
            ->when($guard, fn ($q) => $q->where('guard_name', $guard))
            ->orderBy('table_name')
            ->orderBy('name')
            ->get(['id', 'name', 'table_name', 'guard_name'])
            ->groupBy('table_name')
            ->map(fn ($items, $table) => [
                'table'       => (string) $table,
                'permissions' => $items->pluck('name')->values()->all(),
            ])
            ->values()
            ->all();

        return $this->success(__('messages.retrieved'), $grouped);
    }

    /**
     * @OA\Get(
     *     path="/roles/{role}",
     *     tags={"Roles"},
     *     summary="Show a role (with permissions).",
     *     security={{"BearerAuth": {}}},
     *     @OA\Parameter(ref="#/components/parameters/AcceptLanguage"),
     *     @OA\Parameter(name="role", in="path", required=true, @OA\Schema(type="integer", minimum=1)),
     *     @OA\Response(
     *         response=200,
     *         description="Role detail",
     *         @OA\JsonContent(
     *             allOf={
     *                 @OA\Schema(ref="#/components/schemas/SuccessResponse"),
     *                 @OA\Schema(@OA\Property(property="result", ref="#/components/schemas/Role"))
     *             }
     *         )
     *     ),
     *     @OA\Response(response=401, ref="#/components/responses/Unauthorized"),
     *     @OA\Response(response=403, ref="#/components/responses/Forbidden"),
     *     @OA\Response(response=404, ref="#/components/responses/NotFound")
     * )
     */
    public function show(Role $role): JsonResponse
    {
        $role = $this->service->find($role->id);
        return $this->success(__('messages.retrieved'), new RoleResource($role));
    }

    /**
     * @OA\Post(
     *     path="/roles",
     *     tags={"Roles"},
     *     summary="Create a role with permissions.",
     *     security={{"BearerAuth": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name"},
     *             @OA\Property(property="name",        type="string", maxLength=255),
     *             @OA\Property(
     *                 property="permissions",
     *                 type="array",
     *                 nullable=true,
     *                 @OA\Items(type="string", description="Existing permission name.")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Created",
     *         @OA\JsonContent(
     *             allOf={
     *                 @OA\Schema(ref="#/components/schemas/SuccessResponse"),
     *                 @OA\Schema(@OA\Property(property="result", ref="#/components/schemas/Role"))
     *             }
     *         )
     *     ),
     *     @OA\Response(response=401, ref="#/components/responses/Unauthorized"),
     *     @OA\Response(response=403, ref="#/components/responses/Forbidden"),
     *     @OA\Response(response=422, ref="#/components/responses/ValidationError")
     * )
     */
    public function store(RoleRequest $request): JsonResponse
    {
        $data = $request->validated();
        if (AdminSections::isSuperAdminRole((string) $data['name']) && ! $request->user()->isSuperAdmin()) {
            abort(403, __('messages.role_super_admin_locked'));
        }
        $role = $this->service->create(
            $data['name'],
            $this->permittedPermissions($request, $data['permissions'] ?? []),
            $data['guard_name'] ?? 'admin',
        );
        return $this->created(__('messages.created'), new RoleResource($role));
    }

    /**
     * @OA\Put(
     *     path="/roles/{role}",
     *     tags={"Roles"},
     *     summary="Update a role and its permissions.",
     *     security={{"BearerAuth": {}}},
     *     @OA\Parameter(name="role", in="path", required=true, @OA\Schema(type="integer", minimum=1)),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name"},
     *             @OA\Property(property="name",        type="string", maxLength=255),
     *             @OA\Property(
     *                 property="permissions",
     *                 type="array",
     *                 nullable=true,
     *                 @OA\Items(type="string", description="Existing permission name.")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Updated",
     *         @OA\JsonContent(
     *             allOf={
     *                 @OA\Schema(ref="#/components/schemas/SuccessResponse"),
     *                 @OA\Schema(@OA\Property(property="result", ref="#/components/schemas/Role"))
     *             }
     *         )
     *     ),
     *     @OA\Response(response=401, ref="#/components/responses/Unauthorized"),
     *     @OA\Response(response=403, ref="#/components/responses/Forbidden"),
     *     @OA\Response(response=404, ref="#/components/responses/NotFound"),
     *     @OA\Response(response=422, ref="#/components/responses/ValidationError")
     * )
     */
    public function update(Role $role, RoleRequest $request): JsonResponse
    {
        $data = $request->validated();
        $this->authority->assertCanManageRole($request->user(), $role);
        $current = $role->permissions()->pluck('name')->all();
        $role = $this->service->update($role, $data['name'], $this->permittedPermissions($request, $data['permissions'] ?? [], $current));
        return $this->success(__('messages.updated'), new RoleResource($role));
    }

    /**
     * @OA\Delete(
     *     path="/roles/{role}",
     *     tags={"Roles"},
     *     summary="Delete a role.",
     *     security={{"BearerAuth": {}}},
     *     @OA\Parameter(name="role", in="path", required=true, @OA\Schema(type="integer", minimum=1)),
     *     @OA\Response(response=200, description="Deleted", @OA\JsonContent(ref="#/components/schemas/EmptyResponse")),
     *     @OA\Response(response=401, ref="#/components/responses/Unauthorized"),
     *     @OA\Response(response=403, ref="#/components/responses/Forbidden"),
     *     @OA\Response(response=404, ref="#/components/responses/NotFound")
     * )
     */
    public function destroy(Request $request, Role $role): JsonResponse
    {
        $this->authority->assertCanManageRole($request->user(), $role);
        if ($role->is_system) {
            abort(422, __('messages.role_system_delete'));
        }
        $users = DB::table('model_has_roles')->where('role_id', $role->id)->count();
        if ($users > 0) {
            abort(422, __('messages.role_in_use', ['count' => $users]));
        }
        $this->service->delete($role);
        return $this->deleted(__('messages.deleted'));
    }
}
