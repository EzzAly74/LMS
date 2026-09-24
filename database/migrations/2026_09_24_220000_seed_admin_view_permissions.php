<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Create the `view-*` admin permissions and wire them to existing roles.
 *
 * DB-01 (Critical): the admin permission matrix was enforced only in the
 * Angular guard and sidebar. Server-side, RoleMiddleware checks nothing but
 * `$user instanceof Admin`, so any admin could call every admin endpoint
 * directly — create roles and admins, change platform settings, issue session
 * passcodes.
 *
 * The enforcement gap turned out to be total. Phase 2 measured 154 routes
 * carrying Spatie's PermissionMiddleware, but every one of those was a Blade
 * route; once the Blade surface was removed in Stage A the count dropped to
 * zero. The API has never had a permission check on any of its 260 role:Admin
 * routes.
 *
 * Worse, the two halves speak different vocabularies. The Dashboard asks for
 * `view-users`, `view-courses`, … (AdminResource builds `view_keys` by
 * filtering permissions that start with `view-`), while the database holds 102
 * legacy Blade-era names like `users-index` and `testimonials-create`. Not one
 * `view-*` permission existed, so `view_keys` was empty for every admin and the
 * Angular matrix was inert — it only ever passed because super admins bypass it.
 *
 * This migration creates the 17 `view-*` permissions the Dashboard actually
 * asks for, and grants ALL of them to both `superAdmin` and `admin`.
 *
 * Granting everything to `admin` is deliberate: it reproduces exactly the
 * access admins have today, so enforcing the middleware cannot lock anyone out
 * of a working system. Narrowing the matrix — deciding which of these an
 * `admin`, an `instructor` or a `reports-viewer` should actually hold — is a
 * business decision about who may access what, and is left to the product
 * owner. Once decided it is a data change (revoking grants), not a code change.
 *
 * Note `reports-viewer` and `instructor` are deliberately NOT granted anything
 * here: they hold no `view-*` permission, so the middleware will now refuse
 * them on gated routes. That is the intended restrictive default for roles that
 * are clearly meant to be narrow.
 */
return new class extends Migration
{
    /**
     * The keys the Dashboard's route guard asks for, taken from the
     * `viewKey` / `viewKeyAny` route data in the Angular app.
     */
    private const VIEW_PERMISSIONS = [
        'view-dashboard',
        'view-users',
        'view-courses',
        'view-categories',
        'view-assignments',
        'view-quizzes',
        'view-certificates',
        'view-qualifications',
        'view-job-titles',
        'view-ratings',
        'view-reports',
        'view-resources',
        'view-roles',
        'view-controllers',
        'view-platform-config',
        'view-audit-log',
        'view-inbox',
    ];

    private const GUARD = 'admin';

    /** Roles that keep full access, so nobody is locked out by the new checks. */
    private const FULL_ACCESS_ROLES = ['superAdmin', 'admin'];

    public function up(): void
    {
        $now = now();

        foreach (self::VIEW_PERMISSIONS as $name) {
            $exists = DB::table('permissions')
                ->where('name', $name)->where('guard_name', self::GUARD)->exists();

            if (! $exists) {
                DB::table('permissions')->insert([
                    'name'       => $name,
                    'guard_name' => self::GUARD,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('name', self::VIEW_PERMISSIONS)
            ->where('guard_name', self::GUARD)
            ->pluck('id');

        $roleIds = DB::table('roles')
            ->whereIn('name', self::FULL_ACCESS_ROLES)
            ->where('guard_name', self::GUARD)
            ->pluck('id');

        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                $linked = DB::table('role_has_permissions')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $permissionId)
                    ->exists();

                if (! $linked) {
                    DB::table('role_has_permissions')->insert([
                        'role_id'       => $roleId,
                        'permission_id' => $permissionId,
                    ]);
                }
            }
        }

        app()['cache']->forget('spatie.permission.cache');
    }

    public function down(): void
    {
        $ids = DB::table('permissions')
            ->whereIn('name', self::VIEW_PERMISSIONS)
            ->where('guard_name', self::GUARD)
            ->pluck('id');

        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        app()['cache']->forget('spatie.permission.cache');
    }
};
