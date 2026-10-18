<?php

use App\Support\Permissions\AdminSections;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The `ai-assessments` section (D-113): view / create / edit / delete.
 *
 * Least privilege: only super-admin roles get it here. Any other role is
 * given it in the Roles screen (H2: "a permission given through Roles").
 */
return new class extends Migration
{
    private const GUARD = 'admin';

    private const SECTION = 'ai-assessments';

    public function up(): void
    {
        $now = now();
        $row = AdminSections::SECTIONS[self::SECTION];

        $ids = [];
        foreach ($row['actions'] as $action) {
            $name = AdminSections::permission(self::SECTION, $action);
            $id = DB::table('permissions')->where('name', $name)->where('guard_name', self::GUARD)->value('id');
            $ids[] = $id ?? DB::table('permissions')->insertGetId([
                'name'       => $name,
                'guard_name' => self::GUARD,
                'table_name' => $row['group'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $roles = DB::table('roles')->where('guard_name', self::GUARD)->get(['id', 'name'])
            ->filter(fn ($role) => AdminSections::isSuperAdminRole((string) $role->name));

        foreach ($roles as $role) {
            foreach ($ids as $permissionId) {
                $held = DB::table('role_has_permissions')
                    ->where('role_id', $role->id)->where('permission_id', $permissionId)->exists();
                if (! $held) {
                    DB::table('role_has_permissions')->insert(['role_id' => $role->id, 'permission_id' => $permissionId]);
                }
            }
        }

        app()['cache']->forget('spatie.permission.cache');
    }

    public function down(): void
    {
        $names = array_map(
            fn (string $action) => AdminSections::permission(self::SECTION, $action),
            [AdminSections::VIEW, AdminSections::CREATE, AdminSections::EDIT, AdminSections::DELETE],
        );
        $ids = DB::table('permissions')->where('guard_name', self::GUARD)->whereIn('name', $names)->pluck('id');

        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        app()['cache']->forget('spatie.permission.cache');
    }
};
