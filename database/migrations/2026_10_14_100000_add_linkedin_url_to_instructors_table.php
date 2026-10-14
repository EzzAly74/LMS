<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The instructor's LinkedIn profile, set on the Dashboard account (Add New
 * User, Figma 1532:63886) and shown on the profile (715:57123). Additive and
 * idempotent; nullable, so existing rows are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('instructors') && ! Schema::hasColumn('instructors', 'linkedin_url')) {
            Schema::table('instructors', fn (Blueprint $t) => $t->string('linkedin_url', 255)->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('instructors') && Schema::hasColumn('instructors', 'linkedin_url')) {
            Schema::table('instructors', fn (Blueprint $t) => $t->dropColumn('linkedin_url'));
        }
    }
};
