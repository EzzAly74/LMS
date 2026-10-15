<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-108: every HR pull (the automatic one every 24 hours and the Dashboard
 * buttons) leaves a row. The cache status is wiped by every deploy
 * (optimize:clear), so "when did HR last update us" and "is the automatic
 * sync due" are read from here. Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_sync_runs')) {
            return;
        }

        Schema::create('hr_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->string('source', 16);            // schedule | manual
            $table->string('state', 16);             // done | failed
            $table->string('reason', 32)->nullable(); // not_configured | no_data | error
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedInteger('employees')->default(0);
            $table->unsignedInteger('created')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['state', 'finished_at'], 'hr_sync_runs_state_finished_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_sync_runs');
    }
};
