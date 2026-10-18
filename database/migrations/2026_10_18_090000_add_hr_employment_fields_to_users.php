<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR fields the NAS reports need (D-112): hire date, work location and
 * employment status. HR returns them on every employee (`hiringDate`,
 * `locationNames`, `employeeStatus`, `resignationDate`); the sync stores them
 * from now on. Nullable and additive: existing rows fill on the next HR sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->date('hire_date')->nullable()->after('department_name_en');
            $table->string('location_name', 255)->nullable()->after('hire_date');
            $table->string('location_name_en', 255)->nullable()->after('location_name');
            $table->smallInteger('hr_employee_status')->nullable()->after('location_name_en');
            $table->date('resignation_date')->nullable()->after('hr_employee_status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['hire_date', 'location_name', 'location_name_en', 'hr_employee_status', 'resignation_date']);
        });
    }
};
