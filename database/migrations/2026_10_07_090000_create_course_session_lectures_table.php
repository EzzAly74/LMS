<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * New curriculum flow (2026-10-07, D-079): a module (course_lectures row)
 * belongs to the course, and a cohort's schedule says which modules each
 * session covers through the "content" column of the schedule sheet.
 *
 * Replaces the module's own "Learner scope" / cohort (`learner_scope`,
 * `session_id`) and "Related to session number" (`session_number`) fields.
 * Those columns are no longer read or written; they are left in place
 * (nullable / defaulted) so no existing data is dropped by this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_session_lectures', function (Blueprint $table): void {
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('lecture_id');
            // Order of the module within the session, as listed in the sheet.
            $table->unsignedSmallInteger('position')->default(0);

            $table->primary(['session_id', 'lecture_id']);
            $table->index('lecture_id');
            $table->foreign('session_id')->references('id')->on('course_sessions')->cascadeOnDelete();
            $table->foreign('lecture_id')->references('id')->on('course_lectures')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_session_lectures');
    }
};
