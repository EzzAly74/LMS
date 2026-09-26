<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * External Training requests (D-035, D-057): training a learner completed
 * outside the LMS, submitted with a certificate and decided by L&D admins.
 *
 * Additive - a new table, nothing existing changes. The certificate lives on
 * the private disk and is served only through authorized routes (D-031).
 *
 * `grant_created` records whether approving THIS request created the learner's
 * direct qualification grant, so a super admin's reopen removes only a grant
 * this request made - never one an admin gave by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_training_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('title', 191);
            $table->string('provider', 191);
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('hours', 6, 1);
            $table->decimal('cost', 12, 2)->nullable();
            $table->char('currency', 3)->default('EGP');

            $table->string('certificate_path');
            $table->string('certificate_name', 191);
            $table->string('certificate_mime', 100);
            $table->unsignedInteger('certificate_size');

            $table->string('status', 16)->default('pending');
            $table->string('rejection_reason', 1000)->nullable();
            $table->foreignId('qualification_skill_id')->nullable()->constrained('qualification_skills')->nullOnDelete();
            $table->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete();
            $table->boolean('grant_created')->default(false);
            $table->foreignId('decided_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();

            $table->timestamps();

            // The admin list filters by status and orders by submission; the
            // learner list reads one person's requests.
            $table->index(['status', 'created_at'], 'etr_status_created_idx');
            $table->index(['user_id', 'status'], 'etr_user_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_training_requests');
    }
};
