<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI question generation for quizzes and assignments (D-113).
 *
 * One row per "Generate with AI" in the Add / Edit Quiz or Assignment form:
 * the settings, the source text while it is being used, the generated
 * questions (in the course_exam_questions / course_assignment_questions
 * shape) until the form takes them, and the cost (model, tokens). The daily
 * cap counts these rows.
 *
 * It also removes the standalone AI Assessment builder that preceded it on
 * the same unpushed branch (the human's decision, 2026-10-18): its four tables
 * and `*-ai-assessments` permissions exist only on databases that ran those
 * earlier migrations (the dev DB). Everywhere else these steps do nothing.
 */
return new class extends Migration
{
    private const OLD_TABLES = ['ai_generation_runs', 'ai_assessment_questions', 'ai_assessment_sources', 'ai_assessments'];

    private const OLD_MIGRATIONS = [
        '2026_10_18_120000_create_ai_assessment_tables',
        '2026_10_18_120100_add_ai_assessment_permissions',
    ];

    public function up(): void
    {
        foreach (self::OLD_TABLES as $table) {
            Schema::dropIfExists($table);
        }
        $old = DB::table('permissions')->where('guard_name', 'admin')->where('name', 'like', '%-ai-assessments')->pluck('id');
        if ($old->isNotEmpty()) {
            DB::table('role_has_permissions')->whereIn('permission_id', $old)->delete();
            DB::table('model_has_permissions')->whereIn('permission_id', $old)->delete();
            DB::table('permissions')->whereIn('id', $old)->delete();
            app()['cache']->forget('spatie.permission.cache');
        }
        DB::table('migrations')->whereIn('migration', self::OLD_MIGRATIONS)->delete();

        Schema::create('ai_generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('target', 16);                 // quiz | assignment
            $table->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete();
            $table->string('status', 16);                 // queued | running | succeeded | failed
            $table->json('settings');                     // counts, difficulty, bloom, objectives, type, score
            $table->json('source_names');                 // [{name, kind}] for display and audit
            $table->longText('source_text')->nullable();  // cleared when the run ends
            $table->json('source_files')->nullable();     // private-disk PDFs, deleted when the run ends
            $table->json('questions')->nullable();        // the result, in the system question shape
            $table->string('error', 64)->nullable();      // a key under ai_questions.errors
            $table->string('model', 80);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            // The daily cap and "one running generation per admin" both read by admin + time.
            $table->index(['admin_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_generations');
    }
};
