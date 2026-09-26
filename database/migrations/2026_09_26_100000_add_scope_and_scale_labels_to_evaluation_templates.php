<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evaluation template builder (Figma 2409:132793 / 2409:133222; D-054).
 *
 * Extends the existing template tables, as the human chose on 2026-09-26,
 * rather than adding a parallel set (the original D-032), so the reports built
 * on these tables keep covering every template.
 *
 * Additive only. No existing value is rewritten: every existing template gets
 * course_id = NULL and section_id = NULL, which means "all evaluable courses,
 * all cohorts" - exactly how the legacy form already presents them - and every
 * existing question gets NULL scale labels.
 *
 * Scope columns cascade on delete: when a course or cohort goes, a template
 * written for it must not silently widen to every course, which is what
 * nullOnDelete would do.
 *
 * user_course_evaluations had no index but its primary key, and every report
 * groups or filters it by template, course or learner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluation_categories', function (Blueprint $table) {
            $table->foreignId('course_id')->nullable()->after('name')
                ->constrained('courses')->cascadeOnDelete();
            $table->foreignId('section_id')->nullable()->after('course_id')
                ->constrained('course_sections')->cascadeOnDelete();
        });

        Schema::table('evaluations', function (Blueprint $table) {
            // Translatable {"en","ar"}: the words under "1" and "5" on a scale question.
            $table->json('scale_label_min')->nullable()->after('title');
            $table->json('scale_label_max')->nullable()->after('scale_label_min');
        });

        Schema::table('user_course_evaluations', function (Blueprint $table) {
            $table->index('evaluation_category_id', 'uce_template_idx');
            $table->index('course_id', 'uce_course_idx');
            $table->index(['user_id', 'course_id'], 'uce_user_course_idx');
        });
    }

    public function down(): void
    {
        Schema::table('user_course_evaluations', function (Blueprint $table) {
            $table->dropIndex('uce_template_idx');
            $table->dropIndex('uce_course_idx');
            $table->dropIndex('uce_user_course_idx');
        });

        Schema::table('evaluations', function (Blueprint $table) {
            $table->dropColumn(['scale_label_min', 'scale_label_max']);
        });

        Schema::table('evaluation_categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('section_id');
            $table->dropConstrainedForeignId('course_id');
        });
    }
};
