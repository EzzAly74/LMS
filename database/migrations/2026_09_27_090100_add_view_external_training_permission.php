<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add the `view-external-training` permission (D-057) - the Dashboard's
 * External Courses section: reviewing, approving and rejecting requests.
 *
 * Filed under "Learning Operation", where Figma puts External Courses, and
 * granted to `superAdmin` and `admin` like the other view-* keys, so turning
 * the gate on locks nobody out. Narrowing it is a role change in the UI.
 */
return new class extends Migration
{
    private const PERMISSION = 'view-external-training';

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
                'table_name' => 'Learning Operation',
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
