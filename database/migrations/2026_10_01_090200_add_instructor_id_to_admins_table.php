<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Link a Dashboard account to the instructor it signs in for (D-021, D-074).
 *
 * Instructors reach the Dashboard through an `admins` row with the same
 * email (AdminUserService::ensureDashboardLogin). Course ownership was then
 * resolved by matching emails at request time, in one controller only. An
 * explicit, unique foreign key replaces that: it is what course scoping
 * checks, and changing an email can no longer move someone's courses.
 *
 * `name_ar` is added too: Dashboard accounts are bilingual like every other
 * person on the platform (the Users form asks for both names).
 *
 * Backfill: each admin whose email matches exactly one instructor (trimmed,
 * case-insensitive) is linked to it. Ambiguous or missing matches stay null,
 * which scopes that account to no courses until it is linked.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('admins', 'instructor_id')) {
            Schema::table('admins', function (Blueprint $table) {
                $table->unsignedBigInteger('instructor_id')->nullable()->after('id');
                $table->unique('instructor_id');
                $table->foreign('instructor_id')->references('id')->on('instructors')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('admins', 'name_ar')) {
            Schema::table('admins', function (Blueprint $table) {
                $table->string('name_ar')->nullable()->after('name');
            });
        }

        $instructors = DB::table('instructors')
            ->whereNotNull('email')->where('email', '!=', '')
            ->get(['id', 'email'])
            ->groupBy(fn ($row) => strtolower(trim((string) $row->email)));

        $taken = DB::table('admins')->whereNotNull('instructor_id')->pluck('instructor_id')->all();

        foreach (DB::table('admins')->whereNull('instructor_id')->get(['id', 'email']) as $admin) {
            $matches = $instructors->get(strtolower(trim((string) $admin->email)));
            if ($matches === null || $matches->count() !== 1) {
                continue;
            }
            $instructorId = (int) $matches->first()->id;
            if (in_array($instructorId, $taken, true)) {
                continue;
            }
            DB::table('admins')->where('id', $admin->id)->update(['instructor_id' => $instructorId]);

            // The instructor's Arabic name, for a linked account that has none.
            $name = json_decode((string) DB::table('instructors')->where('id', $instructorId)->value('name'), true);
            if (is_array($name) && ! empty($name['ar'])) {
                DB::table('admins')->where('id', $admin->id)->whereNull('name_ar')->update(['name_ar' => $name['ar']]);
            }
            $taken[] = $instructorId;
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('admins', 'instructor_id')) {
            Schema::table('admins', function (Blueprint $table) {
                $table->dropForeign(['instructor_id']);
                $table->dropUnique(['instructor_id']);
                $table->dropColumn('instructor_id');
            });
        }
        if (Schema::hasColumn('admins', 'name_ar')) {
            Schema::table('admins', function (Blueprint $table) {
                $table->dropColumn('name_ar');
            });
        }
    }
};
