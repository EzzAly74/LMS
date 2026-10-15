<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * B-197 (D-038, human approved 2026-10-15): a learner is present at a session
 * once. Before the unique index, rows that repeat (user_id, session_id) are
 * merged into the earliest one: their audit logs move to it, the copies are
 * deleted. Rows without a session (legacy, admin manual marks) are untouched;
 * MySQL allows repeated NULLs in a unique index. Idempotent.
 */
return new class extends Migration
{
    private const NAME = 'attendances_user_session_unique';

    public function up(): void
    {
        if (! Schema::hasTable('attendances') || $this->exists()) {
            return;
        }

        DB::transaction(function () {
            $groups = DB::table('attendances')
                ->whereNotNull('session_id')
                ->select('user_id', 'session_id', DB::raw('MIN(id) AS keep_id'))
                ->groupBy('user_id', 'session_id')
                ->havingRaw('COUNT(*) > 1')
                ->get();

            $removed = 0;
            foreach ($groups as $g) {
                $copies = DB::table('attendances')
                    ->where('user_id', $g->user_id)
                    ->where('session_id', $g->session_id)
                    ->where('id', '<>', $g->keep_id)
                    ->pluck('id')
                    ->all();
                DB::table('attendance_logs')->whereIn('attendance_id', $copies)->update(['attendance_id' => $g->keep_id]);
                $removed += DB::table('attendances')->whereIn('id', $copies)->delete();
            }

            if ($removed > 0) {
                Log::info('B-197: duplicate attendance rows merged', ['groups' => $groups->count(), 'rows_removed' => $removed]);
            }
        });

        DB::statement('ALTER TABLE attendances ADD UNIQUE INDEX '.self::NAME.' (user_id, session_id)');
    }

    public function down(): void
    {
        if (Schema::hasTable('attendances') && $this->exists()) {
            DB::statement('ALTER TABLE attendances DROP INDEX '.self::NAME);
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
