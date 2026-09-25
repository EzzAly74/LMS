<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Direct learner→qualification assignment (D-045).
 *
 * Figma 2066:100876 (the New Qualification modal) and the "Assign
 * Qualification" bulk action on the learners list both let an admin grant a
 * qualification to a *person*. Until now the only route to holding a
 * qualification was indirect: a job title requires it, and the learner earns
 * it by completing every course that grants it.
 *
 * ── Why a new table rather than reusing job_title_qualification_skill ───────
 * That table answers "which qualifications does this ROLE require?". This one
 * answers "which qualifications does this PERSON hold?". Same-shaped rows,
 * different questions — merging them would make the compliance denominator
 * meaningless, because a requirement and an award would be indistinguishable.
 *
 * ── Semantics (the human's answer, 7.1 / 1.7 in 06-open-questions.md) ───────
 * ADDITIVE. A learner holds a qualification if EITHER:
 *   (a) it is recorded here, or
 *   (b) they completed every course granting it among their job title's
 *       requirements (the existing derivation).
 *
 * (a) is the manual override: an externally-obtained certificate, a legacy
 * award, a recognition of prior learning. It does NOT enrol anyone in anything
 * and does not fabricate course completions — the courses table stays honest,
 * and only the qualification is granted.
 *
 * ── Audit trail ────────────────────────────────────────────────────────────
 * `assigned_by` and `assigned_at` are recorded because this is a manual grant
 * that bypasses the normal evidence path. Without them, a qualification could
 * appear on a compliance report with no way to ask who granted it or why.
 * `note` carries the reason.
 *
 * `assigned_by` is nullOnDelete rather than cascade: removing an admin account
 * must not silently delete the qualifications they granted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_qualification_skill', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('qualification_skill_id')
                ->constrained('qualification_skills')
                ->cascadeOnDelete();

            // Who granted it, and why. Nullable so a system-driven grant, or
            // one whose granting admin has since been removed, is still valid.
            $table->foreignId('assigned_by')
                ->nullable()
                ->constrained('admins')
                ->nullOnDelete();

            $table->timestamp('assigned_at')->nullable();
            $table->string('note', 500)->nullable();

            $table->timestamps();

            // One grant per learner per qualification. Makes the assignment
            // endpoint idempotent and stops a double-submit creating two rows
            // that would then both have to be revoked.
            $table->unique(['user_id', 'qualification_skill_id'], 'user_qualification_unique');

            // Reading "which qualifications does this person hold" is the hot
            // path (learner detail, compliance aggregates), and the unique
            // index above already leads on user_id, so no extra index is
            // added. The reverse lookup is indexed below.
            $table->index('qualification_skill_id', 'user_qualification_skill_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_qualification_skill');
    }
};
