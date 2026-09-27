<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D6 / D-058: a course may follow its own certificate rule instead of the
 * Platform Config one ("Use the general certificate rule for this course?" =
 * No, Figma 2401:126596). The rule itself reuses the existing
 * `certificate_mode` / `certificate_*_threshold` columns; this flag says
 * whether they apply.
 *
 * Additive and false for every existing row, so every course keeps the
 * general rule it follows today until an admin edits it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->boolean('certificate_custom_rule')->default(false)->after('certificate');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('certificate_custom_rule');
        });
    }
};
