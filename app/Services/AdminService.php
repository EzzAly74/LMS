<?php

namespace App\Services;

use App\Models\Admin;
use App\Services\Admin\RoleAuthority;
use App\Repositories\Contracts\AdminRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Hash;

class AdminService
{
    public function __construct(
        private readonly AdminRepositoryInterface $repo
    ) {}

    public function paginate(int $perPage, ?string $search): LengthAwarePaginator
    {
        return $this->repo->paginateWithSearch($perPage, $search);
    }

    public function find(int $id): Admin
    {
        /** @var Admin */
        return $this->repo->findOrFail($id);
    }

    public function findWithRoles(int $id): Admin
    {
        return $this->repo->findWithRoles($id);
    }

    /**
     * Role assignment and account changes follow RoleAuthority (D-073): no
     * assigning a role with more access than the actor has, no super-admin
     * changes by non-super admins, no changing your own role or account access.
     */
    public function create(Admin $actor, array $data): Admin
    {
        $role = $data['role'];
        app(RoleAuthority::class)->assertCanAssignRole($actor, (string) $role);
        unset($data['role'], $data['password_confirmation']);
        $data['password'] = Hash::make($data['password']);

        /** @var Admin $admin */
        $admin = $this->repo->create($data);
        $admin->assignRole($role);

        return $this->repo->findWithRoles($admin->id);
    }

    public function update(Admin $actor, Admin $admin, array $data): Admin
    {
        $role = $data['role'];
        $authority = app(RoleAuthority::class);
        $changesRole = ! $admin->hasRole((string) $role) || $admin->roles()->count() !== 1;
        $authority->assertCanManageAccount($actor, $admin, $changesRole);
        if ($changesRole) {
            $authority->assertCanAssignRole($actor, (string) $role);
        }
        unset($data['role'], $data['password_confirmation']);

        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        /** @var Admin $admin */
        $admin = $this->repo->update($admin, $data);
        $admin->syncRoles([$role]);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->repo->findWithRoles($admin->id);
    }

    public function delete(Admin $actor, Admin $admin): void
    {
        app(RoleAuthority::class)->assertCanManageAccount($actor, $admin, true);
        $this->repo->delete($admin);
    }
}
