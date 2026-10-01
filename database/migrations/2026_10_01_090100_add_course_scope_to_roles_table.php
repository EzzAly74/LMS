<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Course scope per role (D-074): `all` sees every course, `assigned` sees only
 * the courses the signed-in person teaches (courses_instructors). An admin is
 * scoped only when none of their roles is `all`, so adding a broader role
 * always widens access and never narrows it.
 *
 * The `instructor` system role becomes `assigned`: instructors see their own
 * courses, their learners and their analytics. Every other role keeps `all`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('roles', 'course_scope')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->string('course_scope', 16)->default('all')->after('color');
            });
        }

        DB::table('roles')->where('guard_name', 'admin')->where('name', 'instructor')
            ->update(['course_scope' => 'assigned']);

        app()['cache']->forget('spatie.permission.cache');
    }

    public function down(): void
    {
        if (Schema::hasColumn('roles', 'course_scope')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->dropColumn('course_scope');
            });
        }

        app()['cache']->forget('spatie.permission.cache');
    }
};
