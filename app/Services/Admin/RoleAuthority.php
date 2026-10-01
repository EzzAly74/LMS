<?php

namespace App\Services\Admin;

use App\Models\Admin;
use App\Support\Permissions\AdminSections;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Who may change which roles and accounts (D-073). One place, used by the
 * Roles screen, the Users screen and the legacy role/admin endpoints, so the
 * rules cannot drift apart:
 *
 *  - Only a super admin changes, assigns or removes a super-admin role, or
 *    edits a super-admin account.
 *  - Nobody but a super admin changes a role they hold themselves (no
 *    self-escalation, no locking yourself out of Roles).
 *  - Nobody grants a permission they do not hold. Permissions outside the
 *    actor's own set are left exactly as they were on the role: the actor can
 *    neither add nor remove them.
 *  - Assigning a role needs every permission that role carries.
 *  - Nobody changes their own role or deactivates themselves.
 *
 * Violations are 403s with a translated message; malformed input is a 422.
 */
class RoleAuthority
{
    /** A role row (stdClass from the roles table, or a Spatie Role). */
    public function assertCanManageRole(Admin $actor, object $role): void
    {
        if ($actor->isSuperAdmin()) {
            return;
        }

        if (AdminSections::isSuperAdminRole((string) $role->name)) {
            abort(403, __('messages.role_super_admin_locked'));
        }

        if ($actor->roles()->whereKey($role->id)->exists()) {
            abort(403, __('messages.role_own_locked'));
        }
    }

    /**
     * The permission set to store on a role: the request, validated and
     * normalised (create/edit/delete imply view), limited to what the actor
     * may grant, with the role's other permissions kept as they were.
     *
     * @param  list<string>  $requested
     * @param  list<string>  $current   the role's matrix permissions today
     * @return list<string>
     */
    public function resolvePermissions(Admin $actor, array $requested, array $current = []): array
    {
        $catalogue = AdminSections::permissionNames(catalogOnly: true);
        $wanted = [];

        foreach ($requested as $name) {
            $name = (string) $name;
            $parsed = AdminSections::parse($name);
            if ($parsed === null || ! in_array($name, $catalogue, true)) {
                throw ValidationException::withMessages([
                    'permissions' => [__('messages.role_unknown_permission', ['name' => $name])],
                ]);
            }
            $wanted[$name] = true;
            $wanted[AdminSections::permission($parsed[0], AdminSections::VIEW)] = true;
        }

        $wanted = array_keys($wanted);

        if ($actor->isSuperAdmin()) {
            // Non-catalogue sections (legacy, D-009) are not on the form, so
            // they are kept, never silently stripped.
            return array_values(array_unique(array_merge($wanted, array_diff($current, $catalogue))));
        }

        $grantable = $actor->matrixPermissions();

        if (array_diff($wanted, $grantable, $current) !== []) {
            abort(403, __('messages.role_escalation'));
        }

        // Within the actor's reach: exactly what was asked. Outside it: as it was.
        $mine = array_intersect($wanted, $grantable);
        $kept = array_diff($current, $grantable);
        // A permission the actor lacks but the role already had and the form
        // sent back unchanged is in $kept, not a new grant.

        return array_values(array_unique(array_merge($mine, $kept)));
    }

    /** Assigning `$roleName` to someone. */
    public function assertCanAssignRole(Admin $actor, string $roleName): void
    {
        if ($actor->isSuperAdmin()) {
            return;
        }

        if (AdminSections::isSuperAdminRole($roleName)) {
            abort(403, __('messages.role_super_admin_locked'));
        }

        $carried = DB::table('roles as r')
            ->join('role_has_permissions as rhp', 'rhp.role_id', '=', 'r.id')
            ->join('permissions as p', 'p.id', '=', 'rhp.permission_id')
            ->where('r.name', $roleName)->where('r.guard_name', 'admin')
            ->whereIn('p.name', AdminSections::permissionNames())
            ->pluck('p.name')
            ->all();

        if (array_diff($carried, $actor->matrixPermissions()) !== []) {
            abort(403, __('messages.role_assign_escalation'));
        }
    }

    /**
     * Changing another Dashboard account (role, status, details). `$changesAccess`
     * covers role changes and deactivation, which nobody may do to themselves.
     */
    public function assertCanManageAccount(Admin $actor, Admin $target, bool $changesAccess): void
    {
        if ($changesAccess && $actor->is($target)) {
            abort(403, __('messages.account_self_locked'));
        }

        if (! $actor->isSuperAdmin() && $target->isSuperAdmin()) {
            abort(403, __('messages.account_super_admin_locked'));
        }
    }
}
