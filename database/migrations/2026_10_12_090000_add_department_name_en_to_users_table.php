<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The employee's department in English (NEW2B-6227): HR sends it in the
 * English employee feed, which the sync already fetches for names. The
 * existing `department_name` keeps the Arabic (default) value. Nullable and
 * additive: rows are untouched until the next HR sync fills it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'department_name_en')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('department_name_en')->nullable()->after('department_name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'department_name_en')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('department_name_en');
            });
        }
    }
};
