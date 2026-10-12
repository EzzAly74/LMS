<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The employee photo HR keeps (NEW2B-6219): the feed's `imageUrl`, a path
 * under HR's media folder such as "Employee/<file>.jpg". The full address is
 * built when read (App\Support\HrPhoto), so a change of HR host needs only
 * config. Nullable and additive: filled by the next HR sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'hr_image_path')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('hr_image_path')->nullable()->after('image');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'hr_image_path')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('hr_image_path');
            });
        }
    }
};
