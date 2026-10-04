<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The instructor's job title on the Website course details Instructor tab
 * (NEW2B-5926, Figma 818:40243), bilingual like `name` and `bio`. Nullable
 * and additive: existing rows are untouched and show no title until one is set.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('instructors', 'title')) {
            Schema::table('instructors', function (Blueprint $table) {
                $table->json('title')->nullable()->after('name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('instructors', 'title')) {
            Schema::table('instructors', function (Blueprint $table) {
                $table->dropColumn('title');
            });
        }
    }
};
