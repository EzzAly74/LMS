<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance passcodes start a session from the cohort schedule (D-105):
 *
 *  - started_at: when the instructor opened attendance for the session.
 *  - planned_*: the schedule-sheet slot of a session that was started
 *    early, late or on another day, so the sheet still recognises it.
 *  - passcode_previous*: the code a rotation just replaced, accepted for a
 *    few seconds so a learner typing it is not refused.
 *
 * Additive and nullable; existing rows are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_sessions', function (Blueprint $t) {
            if (! Schema::hasColumn('course_sessions', 'started_at')) {
                $t->timestamp('started_at')->nullable();
            }
            if (! Schema::hasColumn('course_sessions', 'planned_date')) {
                $t->date('planned_date')->nullable();
                $t->time('planned_time_from')->nullable();
                $t->time('planned_time_to')->nullable();
            }
            if (! Schema::hasColumn('course_sessions', 'passcode_previous')) {
                $t->string('passcode_previous', 10)->nullable();
                $t->timestamp('passcode_previous_until')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('course_sessions', function (Blueprint $t) {
            foreach (['started_at', 'planned_date', 'planned_time_from', 'planned_time_to', 'passcode_previous', 'passcode_previous_until'] as $c) {
                if (Schema::hasColumn('course_sessions', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
