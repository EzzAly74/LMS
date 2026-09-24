<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stage A / A3 — the five permissions needed to finish gating the admin API.
 *
 * 2026_09_24_220000 created the 17 `view-*` keys the Dashboard nav already
 * uses, and 2026_09_25_090000 added `view-evaluations`. Between them they cover
 * 41 of the 215 admin routes. The remaining 174 were reachable by any principal
 * holding the `Admin` role, regardless of which sections their role granted —
 * which is the open half of DB-01.
 *
 * Most of those 174 map onto an existing key (34 `courses/*` routes to
 * `view-courses`, 12 `admin/quizzes/*` to `view-quizzes`, and so on). Five
 * domains had no key at all because the 2026 Dashboard nav does not cover them:
 *
 *   view-content        site content: about, testimonials, blogs, articles
 *   view-attendance     the attendance register (kept and gated per D-009)
 *   view-instructors    instructor management (kept and gated per D-009)
 *   view-forms          the legacy forms builder (retire per Q-010 if unused)
 *   view-notifications  admin-authored notifications
 *
 * `view-content` is deliberately one key across four small content surfaces
 * rather than four keys. They are edited by the same people, none has its own
 * Dashboard section, and a key per endpoint group would make the role matrix
 * unreadable without making it more precise.
 *
 * Two admin route groups are deliberately left un-gated:
 *
 *   auth/admin/{me,profile,logout}  every authenticated admin needs their own
 *                                   identity and a way to sign out; gating
 *                                   these could lock an admin out of logging
 *                                   out, which is a security control itself.
 *   assignments.php (shared group)  role:Admin,User,Instructor — a learner
 *                                   surface, not an admin one. An admin
 *                                   permission has no meaning on it.
 *
 * Granted to `superAdmin` and `admin` on the same basis as the earlier two
 * migrations: it reproduces the access those roles have today, so turning the
 * gate on cannot lock anyone out. Narrowing is a data change the human owns.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'view-content',
        'view-attendance',
        'view-instructors',
        'view-forms',
        'view-notifications',
    ];

    private const GUARD = 'admin';

    private const FULL_ACCESS_ROLES = ['superAdmin', 'admin'];

    public function up(): void
    {
        $roleIds = DB::table('roles')
            ->whereIn('name', self::FULL_ACCESS_ROLES)
            ->where('guard_name', self::GUARD)
            ->pluck('id');

        foreach (self::PERMISSIONS as $name) {
            $exists = DB::table('permissions')
                ->where('name', $name)
                ->where('guard_name', self::GUARD)
                ->exists();

            if (! $exists) {
                DB::table('permissions')->insert([
                    'name'       => $name,
                    'guard_name' => self::GUARD,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $permissionId = DB::table('permissions')
                ->where('name', $name)
                ->where('guard_name', self::GUARD)
                ->value('id');

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
        }

        app()['cache']->forget('spatie.permission.cache');
    }

    public function down(): void
    {
        $ids = DB::table('permissions')
            ->whereIn('name', self::PERMISSIONS)
            ->where('guard_name', self::GUARD)
            ->pluck('id');

        if ($ids->isNotEmpty()) {
            DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
        }

        app()['cache']->forget('spatie.permission.cache');
    }
};
