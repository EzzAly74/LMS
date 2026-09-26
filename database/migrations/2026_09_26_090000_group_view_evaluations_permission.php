<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Put `view-evaluations` in the Roles editor's "Learning Operation" group.
 *
 * 2026_09_25_090000 created the permission without a `table_name`, so the
 * Roles catalogue (AdminRoleService::sectionCatalog, which groups by that
 * column) filed it under "System" - and until 2026-09-26 did not list it at
 * all, so no role could be granted the Evaluation section from the UI.
 * Touches only that one row, and only while it is still ungrouped.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')
            ->where('name', 'view-evaluations')
            ->where('guard_name', 'admin')
            ->whereNull('table_name')
            ->update(['table_name' => 'Learning Operation', 'updated_at' => now()]);

        app()['cache']->forget('spatie.permission.cache');
    }

    public function down(): void
    {
        DB::table('permissions')
            ->where('name', 'view-evaluations')
            ->where('guard_name', 'admin')
            ->where('table_name', 'Learning Operation')
            ->update(['table_name' => null, 'updated_at' => now()]);

        app()['cache']->forget('spatie.permission.cache');
    }
};
