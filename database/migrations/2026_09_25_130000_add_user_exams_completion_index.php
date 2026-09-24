<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Index supporting the unified completion check (B-104).
 *
 * `App\Support\CourseCompletion` resolves "has this learner completed this
 * course?" with a correlated EXISTS on `user_exams (user_id, course_id,
 * status)`. That predicate now runs inside the job-title compliance
 * aggregates, the dashboard top-courses widget and the three completion trend
 * queries, so it is evaluated per candidate row on the busiest admin screens.
 *
 * The existing indexes do not cover it:
 *   - `user_exams_user_id_exam_id_unique` leads on user_id but its second
 *     column is exam_id, so course_id is not reachable from it.
 *   - `user_exams_course_id_foreign` leads on course_id alone.
 * Either one forces a row lookup per candidate to read course_id or status.
 *
 * (user_id, course_id, status) is a covering index for the EXISTS: the
 * optimizer can satisfy the whole predicate from the index without touching
 * the table.
 *
 * ── On measurement, honestly ─────────────────────────────────────────────────
 * CLAUDE.md requires a before/after measurement for every added index. That
 * could NOT be done here: the local dev database holds 1 `user_exams` row and
 * 11 `users_courses` rows, so every plan is a single-row lookup and any timing
 * would be noise. No timing figure is claimed.
 *
 * What WAS verified locally: `EXPLAIN` selects this index for the EXISTS
 * predicate once it exists, where it previously fell back to
 * `user_exams_course_id_foreign`. See 03-findings.md.
 *
 * A real before/after on production-scale data is still owed, and is listed as
 * outstanding rather than presented as done.
 *
 * The write cost is one more index on a table appended to on exam submission
 * and updated on review — both low-frequency compared with the admin reads
 * this serves.
 *
 * Idempotent, because a failed run leaving the index behind should not block
 * a retry.
 */
return new class extends Migration
{
    private const INDEX = 'user_exams_completion_idx';

    public function up(): void
    {
        if ($this->indexExists()) {
            return;
        }

        Schema::table('user_exams', function ($table) {
            $table->index(['user_id', 'course_id', 'status'], self::INDEX);
        });
    }

    public function down(): void
    {
        if (! $this->indexExists()) {
            return;
        }

        Schema::table('user_exams', function ($table) {
            $table->dropIndex(self::INDEX);
        });
    }

    private function indexExists(): bool
    {
        return collect(DB::select('SHOW INDEX FROM user_exams'))
            ->contains(fn ($row) => $row->Key_name === self::INDEX);
    }
};
