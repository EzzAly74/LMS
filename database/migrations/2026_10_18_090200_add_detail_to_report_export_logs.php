<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each export contained (D-112): the filters applied, the row count and
 * the language, next to who exported which report and when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_export_logs', function (Blueprint $table) {
            $table->json('filters')->nullable()->after('format');
            $table->unsignedInteger('row_count')->nullable()->after('filters');
            $table->string('locale', 5)->nullable()->after('row_count');
        });
    }

    public function down(): void
    {
        Schema::table('report_export_logs', function (Blueprint $table) {
            $table->dropColumn(['filters', 'row_count', 'locale']);
        });
    }
};
