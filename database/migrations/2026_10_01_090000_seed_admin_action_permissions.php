<?php

use App\Support\Permissions\AdminSections;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The per-action permission matrix (D-073).
 *
 * Until now `view-x` granted every operation in section x (Q-009). The server
 * now checks `create-x`, `edit-x` and `delete-x` separately, so this seeds
 * them and keeps every existing role exactly as capable as it was:
 *
 *  - a role holding `view-x` also gets x's create / edit / delete;
 *  - `view-learners` is new (Learners split from Users), so it goes to the
 *    roles that hold `view-users`, which is what the Learners page needed;
 *  - super-admin roles get everything.
 *
 * Only rows are added: no permission, role or grant is removed. Narrowing a
 * role is now a change in the Roles screen.
 */
return new class extends Migration
{
    private const GUARD = 'admin';

    public function up(): void
    {
        $now = now();

        foreach (AdminSections::SECTIONS as $section => $row) {
            foreach ($row['actions'] as $action) {
                $name = AdminSections::permission($section, $action);

                $exists = DB::table('permissions')->where('name', $name)->where('guard_name', self::GUARD)->exists();
                if (! $exists) {
                    DB::table('permissions')->insert([
                        'name'       => $name,
                        'guard_name' => self::GUARD,
                        'table_name' => $row['group'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                } else {
                    // The older view-* rows were seeded without a group.
                    DB::table('permissions')->where('name', $name)->where('guard_name', self::GUARD)
                        ->where(fn ($q) => $q->whereNull('table_name')->orWhere('table_name', ''))
                        ->update(['table_name' => $row['group']]);
                }
            }
        }

        $ids = DB::table('permissions')->where('guard_name', self::GUARD)
            ->whereIn('name', AdminSections::permissionNames())
            ->pluck('id', 'name');

        $roles = DB::table('roles')->where('guard_name', self::GUARD)->get(['id', 'name']);

        foreach ($roles as $role) {
            $held = DB::table('role_has_permissions as rhp')
                ->join('permissions as p', 'p.id', '=', 'rhp.permission_id')
                ->where('rhp.role_id', $role->id)
                ->pluck('p.name')
                ->all();

            $grant = [];

            if (AdminSections::isSuperAdminRole((string) $role->name)) {
                $grant = AdminSections::permissionNames();
            } else {
                foreach (AdminSections::SECTIONS as $section => $row) {
                    $source = $section === 'learners' ? 'view-users' : AdminSections::permission($section, 'view');
                    if (in_array($source, $held, true)) {
                        foreach ($row['actions'] as $action) {
                            $grant[] = AdminSections::permission($section, $action);
                        }
                    }
                }
            }

            foreach (array_diff(array_unique($grant), $held) as $name) {
                if (isset($ids[$name])) {
                    DB::table('role_has_permissions')->insert(['role_id' => $role->id, 'permission_id' => $ids[$name]]);
                }
            }
        }

        app()['cache']->forget('spatie.permission.cache');
    }

    public function down(): void
    {
        // Remove only what this migration introduced: the non-view actions
        // and view-learners. The older view-* rows stay.
        $names = array_values(array_filter(
            AdminSections::permissionNames(),
            static fn (string $n) => ! str_starts_with($n, 'view-') || $n === 'view-learners',
        ));

        $ids = DB::table('permissions')->where('guard_name', self::GUARD)->whereIn('name', $names)->pluck('id');

        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        app()['cache']->forget('spatie.permission.cache');
    }
};
