<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pre / Mid / Post type on quizzes and assignments (D-065, Q-053; Figma
 * 1983:42584, 1981:41345, 1983:44040). One optional value each.
 *
 * On quizzes, Post IS the final exam: `course_exams.is_final` (which the
 * certificate rules read) stays authoritative and in sync (CourseExam::saving).
 * Existing final exams become `post` - approved by the human on 2026-09-28
 * ("Existing quizzes marked final become Post, the rest stay unset"). No other
 * row changes.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('course_exams', function (Blueprint $table) {
            $table->string('type', 8)->nullable()->after('is_final');
            $table->index('type');
        });

        Schema::table('course_assignments', function (Blueprint $table) {
            $table->string('type', 8)->nullable()->after('status');
            $table->index('type');
        });

        DB::table('course_exams')->where('is_final', true)->update(['type' => 'post']);
    }

    public function down(): void
    {
        Schema::table('course_assignments', function (Blueprint $table) {
            $table->dropIndex(['type']);
            $table->dropColumn('type');
        });

        // is_final was never changed by up(), so dropping the column loses nothing.
        Schema::table('course_exams', function (Blueprint $table) {
            $table->dropIndex(['type']);
            $table->dropColumn('type');
        });
    }
};
