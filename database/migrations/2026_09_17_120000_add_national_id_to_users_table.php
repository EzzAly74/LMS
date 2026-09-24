<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store the HR national id alongside each synced employee.
 *
 * HR already treats the national id as the learner's password
 * (machine code = username, national id = secret), but its own
 * `Auth/login` endpoint additionally gates on a control-panel
 * permission and answers
 * `ليس لديك صلاحية الوصول إلى لوحة التحكم` for employees who lack it.
 * Those learners can present perfectly valid credentials and still
 * never obtain a token, which locks them out of the academy entirely.
 *
 * Mirroring the value locally lets {@see \App\Services\UserAuthService}
 * fall back to the same secret HR would have checked, but only for
 * accounts that have no dashboard-set password of their own.
 *
 *   - Nullable: rows predating the sync, and manually created users,
 *     legitimately have no national id.
 *   - Plain string, not unique: HR is the system of record and its
 *     data is not guaranteed clean; a unique index here would make a
 *     duplicate upstream break the whole employee sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'national_id')) {
                $table->string('national_id', 32)
                    ->nullable()
                    ->after('machine_code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'national_id')) {
                $table->dropColumn('national_id');
            }
        });
    }
};
