<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI Assessment builder (D-113): a trainer's personal library of generated
 * Pre / Post assessments and quizzes. Separate from the course quiz tables on
 * purpose (H3: builder + export only); the question shape keeps a clean
 * mapping to course_exam_questions for a later "copy into a quiz" (D-050).
 *
 * Only new tables: nothing existing is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_assessments', function (Blueprint $table) {
            $table->id();
            // The author. Kept (null) if the admin account is removed, so a
            // super admin can still reach the work.
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete();
            $table->foreignId('duplicated_from_id')->nullable()->constrained('ai_assessments')->nullOnDelete();
            $table->string('title', 200);
            $table->string('type', 16);              // pre | post | quiz
            $table->string('content_language', 2);   // en | ar (independent of the UI language)
            $table->string('difficulty', 8);         // easy | medium | hard | mixed
            $table->string('audience', 150)->nullable();
            $table->json('bloom_levels')->nullable();
            $table->text('objectives')->nullable();
            $table->json('question_counts')->nullable(); // {type: count}
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 16)->default('draft'); // draft | generating | ready
            $table->foreignId('current_run_id')->nullable();
            $table->timestamp('generation_started_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();

            $table->index(['admin_id', 'updated_at']);
        });

        Schema::create('ai_assessment_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_assessment_id')->constrained('ai_assessments')->cascadeOnDelete();
            $table->string('kind', 8);               // pdf | docx | pptx | text
            $table->string('name', 255);             // original file name, or the paste's label
            $table->string('path')->nullable();      // private disk; null for pasted text
            $table->unsignedBigInteger('size')->default(0);
            $table->longText('extracted_text')->nullable(); // with [Page n] / [Slide n] markers; null for PDF (sent natively)
            $table->unsignedInteger('units')->nullable();   // pages or slides found
            $table->string('provider_file_uri')->nullable(); // Gemini Files API copy of a PDF
            $table->timestamp('provider_file_expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_assessment_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_assessment_id')->constrained('ai_assessments')->cascadeOnDelete();
            $table->foreignId('run_id')->nullable();
            $table->unsignedInteger('position');
            $table->string('type', 16);
            $table->text('context')->nullable();     // the scenario of a scenario-based question
            $table->text('prompt');
            $table->json('options')->nullable();
            $table->json('answer')->nullable();
            $table->text('explanation')->nullable();
            $table->string('source_ref', 255)->nullable();
            $table->string('bloom', 16)->nullable();
            $table->string('difficulty', 8)->nullable();
            $table->string('status', 16)->default('pending'); // pending | approved | flagged
            $table->timestamps();

            $table->index(['ai_assessment_id', 'position']);
        });

        // Every call to the AI provider: the daily cap (H4) counts these, and
        // they record what each run cost.
        Schema::create('ai_generation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('ai_assessment_id')->nullable()->constrained('ai_assessments')->nullOnDelete();
            $table->string('kind', 16);              // generate | regenerate
            $table->string('status', 16);            // running | succeeded | failed
            $table->string('model', 80);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->string('error', 500)->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['admin_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_generation_runs');
        Schema::dropIfExists('ai_assessment_questions');
        Schema::dropIfExists('ai_assessment_sources');
        Schema::dropIfExists('ai_assessments');
    }
};
