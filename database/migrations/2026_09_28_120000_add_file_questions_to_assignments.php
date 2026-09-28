<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * File-upload assignment questions (D-033, D-064; Figma 1983:44040 /
 * 2393:120281).
 *
 * - `course_assignment_questions.type` gains `file`.
 * - A question may carry an instructor attachment (the "Assignment file").
 * - An answer may carry the learner's uploaded file (the "Learner answer").
 *
 * Purely additive: no existing row changes. Paths are private-disk keys
 * (D-031), never URLs; names and sizes are kept for display because the
 * stored filename is server-generated.
 */
return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE course_assignment_questions MODIFY type ENUM('mcq','yes_no','open','reorder','file') NOT NULL");
        }

        Schema::table('course_assignment_questions', function (Blueprint $table) {
            $table->string('attachment_path')->nullable()->after('explanation_ar');
            $table->string('attachment_name', 191)->nullable()->after('attachment_path');
            $table->unsignedInteger('attachment_size')->nullable()->after('attachment_name');
            $table->timestamp('attachment_uploaded_at')->nullable()->after('attachment_size');
        });

        Schema::table('user_course_assignment_answers', function (Blueprint $table) {
            $table->string('file_path')->nullable()->after('answer');
            $table->string('file_name', 191)->nullable()->after('file_path');
            $table->unsignedInteger('file_size')->nullable()->after('file_name');
            $table->timestamp('file_uploaded_at')->nullable()->after('file_size');
        });
    }

    public function down(): void
    {
        Schema::table('user_course_assignment_answers', function (Blueprint $table) {
            $table->dropColumn(['file_path', 'file_name', 'file_size', 'file_uploaded_at']);
        });

        Schema::table('course_assignment_questions', function (Blueprint $table) {
            $table->dropColumn(['attachment_path', 'attachment_name', 'attachment_size', 'attachment_uploaded_at']);
        });

        // Narrowing the enum would fail (or silently blank rows) while file
        // questions exist, so it is only reverted when there are none.
        if (DB::getDriverName() === 'mysql' && ! DB::table('course_assignment_questions')->where('type', 'file')->exists()) {
            DB::statement("ALTER TABLE course_assignment_questions MODIFY type ENUM('mcq','yes_no','open','reorder') NOT NULL");
        }
    }
};
