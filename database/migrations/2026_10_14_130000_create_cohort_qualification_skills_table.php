<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cohorts keep the qualifications they started with (D-106, NEW2B-6049).
 * Rows are written only when an admin changes a course's qualifications
 * (the cohorts then under way are frozen); existing data is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cohort_qualification_skills')) {
            Schema::create('cohort_qualification_skills', function (Blueprint $t) {
                $t->foreignId('section_id')->constrained('course_sections')->cascadeOnDelete();
                $t->foreignId('qualification_skill_id')->constrained('qualification_skills')->cascadeOnDelete();
                $t->primary(['section_id', 'qualification_skill_id']);
                $t->index('qualification_skill_id');
            });
        }
        if (! Schema::hasColumn('course_sections', 'qualifications_frozen_at')) {
            Schema::table('course_sections', fn (Blueprint $t) => $t->timestamp('qualifications_frozen_at')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('course_sections', 'qualifications_frozen_at')) {
            Schema::table('course_sections', fn (Blueprint $t) => $t->dropColumn('qualifications_frozen_at'));
        }
        Schema::dropIfExists('cohort_qualification_skills');
    }
};
