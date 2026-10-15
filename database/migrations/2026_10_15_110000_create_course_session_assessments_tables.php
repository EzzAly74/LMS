<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quizzes and assignments act as modules (NEW2B-6358, D-109): a cohort's
 * schedule sheet lists them in a session's "content" column (Q<id>, A<id>)
 * next to the module IDs, so the learner meets them in that session instead
 * of in a trailing "Assessments" group. `position` shares one sequence with
 * course_session_lectures.position, so the session keeps the sheet's order
 * across the three kinds. Both foreign keys cascade, like the module links.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('course_session_exams')) {
            Schema::create('course_session_exams', function (Blueprint $table): void {
                $table->unsignedBigInteger('session_id');
                $table->unsignedBigInteger('exam_id');
                $table->unsignedSmallInteger('position')->default(0);

                $table->primary(['session_id', 'exam_id']);
                $table->index('exam_id');
                $table->foreign('session_id')->references('id')->on('course_sessions')->cascadeOnDelete();
                $table->foreign('exam_id')->references('id')->on('course_exams')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('course_session_assignments')) {
            Schema::create('course_session_assignments', function (Blueprint $table): void {
                $table->unsignedBigInteger('session_id');
                $table->unsignedBigInteger('assignment_id');
                $table->unsignedSmallInteger('position')->default(0);

                $table->primary(['session_id', 'assignment_id']);
                $table->index('assignment_id');
                $table->foreign('session_id')->references('id')->on('course_sessions')->cascadeOnDelete();
                $table->foreign('assignment_id')->references('id')->on('course_assignments')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('course_session_assignments');
        Schema::dropIfExists('course_session_exams');
    }
};
