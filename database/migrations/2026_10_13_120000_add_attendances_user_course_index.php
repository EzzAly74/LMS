<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B-177: every progress / certificate / attendance reader asks "which
 * sessions did THIS learner attend in THIS course" (`user_id` + `course_id`,
 * COUNT(DISTINCT session_id)). The only composite index started with
 * course_id, so those lookups scanned a course's whole attendance. This
 * covering index serves them from the index alone. Additive and idempotent.
 */
return new class extends Migration
{
    private const NAME = 'attendances_user_course_session_idx';

    public function up(): void
    {
        if (! Schema::hasTable('attendances') || $this->exists()) {
            return;
        }

        Schema::table('attendances', fn (Blueprint $t) => $t->index(['user_id', 'course_id', 'session_id'], self::NAME));
    }

    public function down(): void
    {
        if (Schema::hasTable('attendances') && $this->exists()) {
            Schema::table('attendances', fn (Blueprint $t) => $t->dropIndex(self::NAME));
        }
    }

    private function exists(): bool
    {
        return DB::selectOne(
            'SELECT 1 AS x FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
            [DB::connection()->getDatabaseName(), 'attendances', self::NAME],
        ) !== null;
    }
};
