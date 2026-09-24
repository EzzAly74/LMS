<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add the `view-evaluations` permission.
 *
 * Stage B / B3 introduces four admin evaluation reporting endpoints, which need
 * a permission to gate on. None existed: the Dashboard's evaluations route is
 * annotated "legacy, un-gated" and carries no `viewKey`, which is exactly
 * finding DB-06 — the legacy routes (instructors, attendance, evaluations,
 * exams, articles, forms) are hidden from the nav but reachable by URL.
 *
 * `view-ratings` was deliberately NOT reused. Course ratings (a star score on a
 * course) and evaluation templates (a questionnaire with scale and text
 * questions) are separate domains, and the 2026 redesign gives Evaluation its
 * own nav section. Conflating them would permanently tie two unrelated screens
 * to one grant.
 *
 * Granted to `superAdmin` and `admin` on the same basis as the 17 keys seeded
 * by 2026_09_24_220000: it reproduces the access admins have today, so turning
 * the gate on cannot lock anyone out. Narrowing remains the human's decision
 * (the A3 role matrix) and is a data change, not a code change.
 */
return new class extends Migration
{
    private const PERMISSION = 'view-evaluations';

    private const GUARD = 'admin';

    private const FULL_ACCESS_ROLES = ['superAdmin', 'admin'];

    public function up(): void
    {
        $exists = DB::table('permissions')
            ->where('name', self::PERMISSION)
            ->where('guard_name', self::GUARD)
            ->exists();

        if (! $exists) {
            DB::table('permissions')->insert([
                'name'       => self::PERMISSION,
                'guard_name' => self::GUARD,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $permissionId = DB::table('permissions')
            ->where('name', self::PERMISSION)
            ->where('guard_name', self::GUARD)
            ->value('id');

        $roleIds = DB::table('roles')
            ->whereIn('name', self::FULL_ACCESS_ROLES)
            ->where('guard_name', self::GUARD)
            ->pluck('id');

        foreach ($roleIds as $roleId) {
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

        app()['cache']->forget('spatie.permission.cache');
    }

    public function down(): void
    {
        $id = DB::table('permissions')
            ->where('name', self::PERMISSION)
            ->where('guard_name', self::GUARD)
            ->value('id');

        if ($id !== null) {
            DB::table('role_has_permissions')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }

        app()['cache']->forget('spatie.permission.cache');
    }
};
