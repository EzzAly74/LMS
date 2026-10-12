<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An English version of an article module's text and of a document module's
 * file. The existing `content` / `video` / `file_name` stay the Arabic (main)
 * version, which every module already has; the English one is optional and
 * learners reading in English fall back to the Arabic when it is missing.
 * Nullable and additive: existing rows are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_lectures', function (Blueprint $table) {
            if (! Schema::hasColumn('course_lectures', 'content_en')) {
                $table->longText('content_en')->nullable()->after('content');
            }
            if (! Schema::hasColumn('course_lectures', 'video_en')) {
                $table->text('video_en')->nullable()->after('video');
            }
            if (! Schema::hasColumn('course_lectures', 'file_name_en')) {
                $table->string('file_name_en')->nullable()->after('file_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('course_lectures', function (Blueprint $table) {
            foreach (['content_en', 'video_en', 'file_name_en'] as $column) {
                if (Schema::hasColumn('course_lectures', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
