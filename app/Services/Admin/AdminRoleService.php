<?php

namespace App\Services\Admin;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Support\Permissions\AdminSections;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * The Roles screen (D-073): bilingual identity, badge colour, course scope
 * and the permission matrix, view / create / edit / delete per section, from
 * the AdminSections catalogue.
 *
 * Every change goes through RoleAuthority (no self-escalation, no granting
 * what you do not hold, super-admin roles untouchable), flushes Spatie's
 * permission cache so it applies on the next request, and leaves an audit
 * row naming who changed which permissions.
 */
class AdminRoleService
{
    /** @var array<int,string> Allowed badge colors. */
    public const COLORS = ['teal', 'green', 'orange', 'red', 'blue'];

    /** Course scope values (D-074). */
    public const SCOPES = ['all', 'assigned'];

    public function __construct(private readonly RoleAuthority $authority) {}

    /* ------------------------------------------------------------------ *
     |  CATALOGUE                                                         |
     * ------------------------------------------------------------------ */

    /**
     * The matrix the Roles form draws: the action columns, then the sections
     * of each sidebar group with the actions each one supports.
     *
     * @return array{
     *   total:int, sections_total:int,
     *   actions:list<array{key:string,label:string}>,
     *   groups:list<array{key:string,label:string,items:list<array{key:string,label:string,actions:list<string>}>}>
     * }
     */
    public function sectionCatalog(): array
    {
        $locale = app()->getLocale();

        $groups = [];
        foreach (AdminSections::GROUPS as $group) {
            $items = [];
            foreach (AdminSections::SECTIONS as $key => $row) {
                if ($row['group'] !== $group || ! $row['catalog']) {
                    continue;
                }
                $items[] = [
                    'key'      => $key,
                    'label'    => AdminSections::label($key, $locale),
                    'actions'  => $row['actions'],
                    // Not available to roles limited to their own courses (D-074).
                    'org_wide' => ! AdminSections::availableToScoped($key),
                ];
            }
            $groups[] = [
                'key'   => strtolower(str_replace(' ', '_', $group)),
                'label' => AdminSections::groupLabel($group, $locale),
                'items' => $items,
            ];
        }

        return [
            'total'          => count(AdminSections::permissionNames(catalogOnly: true)),
            'sections_total' => array_sum(array_map(static fn ($g) => count($g['items']), $groups)),
            'actions'        => array_map(
                static fn (string $a) => ['key' => $a, 'label' => AdminSections::actionLabel($a, $locale)],
                AdminSections::ACTIONS,
            ),
            'groups'         => $groups,
        ];
    }

    /* ------------------------------------------------------------------ *
     |  READ                                                              |
     * ------------------------------------------------------------------ */

    /**
     * Every admin-guard role (not paginated: the screen is a card grid over a
     * small, bounded set).
     *
     * @return array{total_views:int,total_permissions:int,roles:list<array<string,mixed>>}
     */
    public function list(Admin $actor, ?string $search = null): array
    {
        $query = DB::table('roles')->where('guard_name', 'admin');

        if ($search) {
            $needle = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search).'%';
            $query->where(function ($w) use ($needle) {
                foreach (['name', 'name_en', 'name_ar', 'description_en', 'description_ar'] as $column) {
                    $w->orWhere($column, 'LIKE', $needle);
                }
            });
        }

        $rows = $query->orderByDesc('is_system')->orderBy('name_en')->get();
        $ids = $rows->pluck('id')->map(fn ($v) => (int) $v)->all();
        $permissions = $this->loadPermissionsByRole($ids);
        $users = $this->loadUserCounts($ids);
        $held = $actor->roles()->pluck('roles.id')->map(fn ($v) => (int) $v)->all();

        return [
            'total_views'       => $this->sectionCount(),
            'total_permissions' => count(AdminSections::permissionNames(catalogOnly: true)),
            'roles'             => $rows->map(fn ($r) => $this->shape(
                $r, $permissions[$r->id] ?? [], (int) ($users[$r->id] ?? 0), $actor, $held,
            ))->all(),
        ];
    }

    public function show(Admin $actor, int $id): array
    {
        $row = $this->find($id);
        $held = $actor->roles()->pluck('roles.id')->map(fn ($v) => (int) $v)->all();

        return $this->shape(
            $row,
            $this->loadPermissionsByRole([$id])[$id] ?? [],
            (int) ($this->loadUserCounts([$id])[$id] ?? 0),
            $actor,
            $held,
        );
    }

    /* ------------------------------------------------------------------ *
     |  WRITE                                                             |
     * ------------------------------------------------------------------ */

    /** @param array<string,mixed> $data validated AdminRoleStoreRequest */
    public function create(Admin $actor, array $data): array
    {
        $permissions = $this->authority->resolvePermissions($actor, $this->requested($data));

        $id = DB::transaction(function () use ($data, $permissions) {
            $now = now();
            $id = (int) DB::table('roles')->insertGetId(array_merge([
                'name'           => $this->machineNameFor((string) $data['name_en']),
                'guard_name'     => 'admin',
                'name_en'        => trim((string) $data['name_en']),
                'name_ar'        => trim((string) $data['name_ar']),
                'description_en' => $data['description_en'] ?? null,
                'description_ar' => $data['description_ar'] ?? null,
                'color'          => $this->normaliseColor($data['color'] ?? null),
                'is_system'      => false,
                'created_at'     => $now,
                'updated_at'     => $now,
            ], $this->scopeColumn($data['course_scope'] ?? 'all')));

            $this->syncPermissions($id, $permissions);

            return $id;
        });

        $this->flush();
        $this->audit($actor, 'created', $id, (string) $data['name_en'], [], $permissions);

        return $this->show($actor, $id);
    }

    /** @param array<string,mixed> $data validated AdminRoleUpdateRequest */
    public function update(Admin $actor, int $id, array $data): array
    {
        $row = $this->find($id);
        $this->authority->assertCanManageRole($actor, $row);

        $before = $this->loadPermissionsByRole([$id])[$id] ?? [];
        $after = null;

        if (! AdminSections::isSuperAdminRole((string) $row->name)
            && (array_key_exists('permissions', $data) || array_key_exists('view_keys', $data))) {
            $after = $this->authority->resolvePermissions($actor, $this->requested($data), $before);
        }

        DB::transaction(function () use ($row, $data, $after) {
            $payload = ['updated_at' => now()];

            foreach (['name_en', 'name_ar'] as $column) {
                if (array_key_exists($column, $data) && trim((string) $data[$column]) !== '') {
                    $payload[$column] = trim((string) $data[$column]);
                }
            }
            foreach (['description_en', 'description_ar'] as $column) {
                if (array_key_exists($column, $data)) {
                    $payload[$column] = $data[$column] ?: null;
                }
            }
            if (array_key_exists('color', $data) && $data['color'] !== null) {
                $payload['color'] = $this->normaliseColor((string) $data['color']);
            }
            // A super-admin role always sees every course.
            if (array_key_exists('course_scope', $data) && ! AdminSections::isSuperAdminRole((string) $row->name)) {
                $payload += $this->scopeColumn((string) $data['course_scope']);
            }

            DB::table('roles')->where('id', $row->id)->update($payload);

            if ($after !== null) {
                $this->syncPermissions((int) $row->id, $after);
            }
        });

        $this->flush();
        if ($after !== null && $after != $before) {
            $this->audit($actor, 'permissions_changed', $id, (string) ($row->name_en ?: $row->name), $before, $after);
        }

        return $this->show($actor, $id);
    }

    public function delete(Admin $actor, int $id): void
    {
        $row = $this->find($id);
        $this->authority->assertCanManageRole($actor, $row);

        if ($row->is_system) {
            abort(422, __('messages.role_system_delete'));
        }

        $users = DB::table('model_has_roles')->where('role_id', $row->id)->count();
        if ($users > 0) {
            abort(422, __('messages.role_in_use', ['count' => $users]));
        }

        $before = $this->loadPermissionsByRole([$id])[$id] ?? [];

        DB::transaction(function () use ($row) {
            DB::table('role_has_permissions')->where('role_id', $row->id)->delete();
            DB::table('roles')->where('id', $row->id)->delete();
        });

        $this->flush();
        $this->audit($actor, 'deleted', $id, (string) ($row->name_en ?: $row->name), $before, []);
    }

    /* ------------------------------------------------------------------ *
     |  INTERNALS                                                         |
     * ------------------------------------------------------------------ */

    private function find(int $id): object
    {
        $row = DB::table('roles')->where('id', $id)->where('guard_name', 'admin')->first();
        if (! $row) {
            throw (new ModelNotFoundException())->setModel(\Spatie\Permission\Models\Role::class, [$id]);
        }

        return $row;
    }

    /**
     * The requested matrix: `permissions`, or the older `view_keys` (view
     * only) from a client that predates the matrix.
     *
     * @return list<string>
     */
    private function requested(array $data): array
    {
        $list = $data['permissions'] ?? $data['view_keys'] ?? [];

        return array_values(array_map('strval', is_array($list) ? $list : []));
    }

    /**
     * Replace the role's matrix permissions with `$names`. Permissions outside
     * the matrix (the legacy Blade `courses-create` style rows) are untouched.
     *
     * @param list<string> $names
     */
    private function syncPermissions(int $roleId, array $names): void
    {
        $ids = DB::table('permissions')->where('guard_name', 'admin')
            ->whereIn('name', AdminSections::permissionNames())
            ->pluck('id', 'name');

        $desired = array_values(array_filter(array_map(fn ($n) => isset($ids[$n]) ? (int) $ids[$n] : null, $names)));
        $current = DB::table('role_has_permissions')->where('role_id', $roleId)
            ->whereIn('permission_id', $ids->values()->all())
            ->pluck('permission_id')->map(fn ($v) => (int) $v)->all();

        $add = array_diff($desired, $current);
        $remove = array_diff($current, $desired);

        if ($add !== []) {
            DB::table('role_has_permissions')->insert(
                array_map(fn ($pid) => ['role_id' => $roleId, 'permission_id' => $pid], array_values($add)),
            );
        }
        if ($remove !== []) {
            DB::table('role_has_permissions')->where('role_id', $roleId)->whereIn('permission_id', $remove)->delete();
        }
    }

    /**
     * @param list<int> $roleIds
     * @return array<int,list<string>> role_id => matrix permission names
     */
    private function loadPermissionsByRole(array $roleIds): array
    {
        if ($roleIds === []) {
            return [];
        }

        return DB::table('role_has_permissions as rhp')
            ->join('permissions as p', 'p.id', '=', 'rhp.permission_id')
            ->whereIn('rhp.role_id', $roleIds)
            ->where('p.guard_name', 'admin')
            ->whereIn('p.name', AdminSections::permissionNames())
            ->get(['rhp.role_id', 'p.name'])
            ->groupBy('role_id')
            ->map(fn ($g) => $g->pluck('name')->map(fn ($v) => (string) $v)->values()->all())
            ->all();
    }

    /**
     * @param list<int> $roleIds
     * @return array<int,int>
     */
    private function loadUserCounts(array $roleIds): array
    {
        if ($roleIds === []) {
            return [];
        }

        return DB::table('model_has_roles')->whereIn('role_id', $roleIds)
            ->select('role_id', DB::raw('COUNT(*) AS c'))->groupBy('role_id')
            ->pluck('c', 'role_id')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * @param list<string> $permissions
     * @param list<int>    $actorRoleIds
     */
    private function shape(object $r, array $permissions, int $userCount, Admin $actor, array $actorRoleIds): array
    {
        $locale = app()->getLocale();
        $isSuper = AdminSections::isSuperAdminRole((string) $r->name);
        if ($isSuper) {
            $permissions = AdminSections::permissionNames();
        }

        $catalogue = AdminSections::permissionNames(catalogOnly: true);
        $onForm = array_values(array_intersect($permissions, $catalogue));
        $viewKeys = array_values(array_filter($onForm, static fn ($n) => str_starts_with($n, 'view-')));

        $nameEn = $r->name_en ?: ucwords(str_replace(['_', '-'], ' ', (string) $r->name));
        $nameAr = $r->name_ar ?: null;
        $display = $locale === 'ar' ? ($nameAr ?: $nameEn) : $nameEn;
        $descEn = $r->description_en ?: null;
        $descAr = $r->description_ar ?: null;

        $manageable = $actor->isSuperAdmin() || (! $isSuper && ! in_array((int) $r->id, $actorRoleIds, true));
        $sections = $this->sectionCount();
        $total = count($catalogue);

        return [
            'id'                 => (int) $r->id,
            'machine_name'       => (string) $r->name,
            'guard_name'         => (string) $r->guard_name,
            'name'               => (string) $display,
            'name_en'            => $nameEn ?: null,
            'name_ar'            => $nameAr,
            'description'        => $locale === 'ar' ? ($descAr ?: $descEn) : ($descEn ?: $descAr),
            'description_en'     => $descEn,
            'description_ar'     => $descAr,
            'color'              => (string) ($r->color ?? 'teal'),
            'course_scope'       => $isSuper ? 'all' : (string) ($r->course_scope ?? 'all'),
            'is_system'          => (bool) $r->is_system,
            'is_super_admin'     => $isSuper,
            'can_manage'         => $manageable,
            'can_delete'         => $manageable && ! $r->is_system && $userCount === 0,
            'user_count'         => $userCount,
            'permissions'        => $onForm,
            'permission_count'   => count($onForm),
            'permission_total'   => $total,
            'view_keys'          => $viewKeys,
            'view_count'         => count($viewKeys),
            'view_total'         => $sections,
            'view_percentage'    => $total > 0 ? (int) round(count($onForm) / $total * 100) : 0,
            'avatar_initial'     => mb_strtoupper(mb_substr(trim($display ?: 'R'), 0, 1)) ?: 'R',
            'created_at'         => isset($r->created_at) ? (string) $r->created_at : null,
        ];
    }

    private function sectionCount(): int
    {
        return count(array_filter(AdminSections::SECTIONS, static fn ($s) => $s['catalog']));
    }

    /** @return array<string,string> */
    private function scopeColumn(string $scope): array
    {
        if (! Schema::hasColumn('roles', 'course_scope')) {
            return [];
        }

        return ['course_scope' => in_array($scope, self::SCOPES, true) ? $scope : 'all'];
    }

    private function normaliseColor(?string $color): string
    {
        $color = strtolower(trim((string) $color));

        return in_array($color, self::COLORS, true) ? $color : 'teal';
    }

    private function machineNameFor(string $nameEn): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', trim($nameEn)), '-')) ?: 'role';

        // A custom role may never take a super-admin name.
        if (AdminSections::isSuperAdminRole($slug)) {
            $slug .= '-role';
        }

        $candidate = $slug;
        for ($i = 2; DB::table('roles')->where('name', $candidate)->where('guard_name', 'admin')->exists(); $i++) {
            $candidate = "{$slug}-{$i}";
        }

        return $candidate;
    }

    private function flush(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @param list<string> $before
     * @param list<string> $after
     */
    private function audit(Admin $actor, string $verb, int $roleId, string $name, array $before, array $after): void
    {
        $added = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));
        $parts = [$name];
        if ($added !== []) {
            $parts[] = '+'.implode(', +', $added);
        }
        if ($removed !== []) {
            $parts[] = '-'.implode(', -', $removed);
        }

        try {
            (new AuditLog())->forceFill([
                'user_type'   => 'admin',
                'user_id'     => $actor->getKey(),
                'user_name'   => $actor->name,
                'actor_role'  => 'admin',
                'action'      => $verb,
                'model_type'  => \Spatie\Permission\Models\Role::class,
                'model_id'    => $roleId,
                'description' => mb_substr(implode(' ', $parts), 0, 1000),
                'ip_address'  => request()->ip(),
            ])->save();
        } catch (Throwable) {
            // Auditing never breaks the change itself.
        }
    }
}
