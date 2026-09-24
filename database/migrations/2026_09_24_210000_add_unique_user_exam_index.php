<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One submission per learner per exam, enforced by the database.
 *
 * B-09 (High): UserExamController checks `hasAlreadySubmitted()` and returns
 * 409, but that is a check-then-insert with no lock, so two concurrent submits
 * both pass the check and both insert. Only a unique index actually prevents
 * the second row — the application check stays as the friendly 409 path.
 *
 * Follows the de-duplication procedure agreed in 04-decisions.md D-038:
 * survivors are chosen deterministically (earliest id), extras are copied to a
 * backup table rather than dropped, and the copy-then-delete is atomic.
 *
 * MySQL/MariaDB implicitly commits DDL, so CREATE TABLE / ALTER TABLE cannot
 * run inside DB::transaction() — it fails with "There is no active
 * transaction". The backup table is therefore created first, the DML de-dup
 * runs in a transaction, and the index is added last, outside it.
 *
 * Dev at the time of writing: 1 row, 0 duplicate groups. Production counts are
 * unknown, hence the backup.
 */
return new class extends Migration
{
    private const BACKUP_TABLE = 'user_exams_duplicates_backup';

    private const INDEX = 'user_exams_user_id_exam_id_unique';

    public function up(): void
    {
        $duplicates = DB::table('user_exams')
            ->select('user_id', 'exam_id', DB::raw('MIN(id) as keep_id'))
            ->groupBy('user_id', 'exam_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            // DDL first, outside any transaction.
            if (! Schema::hasTable(self::BACKUP_TABLE)) {
                DB::statement('CREATE TABLE '.self::BACKUP_TABLE.' LIKE user_exams');
            }

            DB::transaction(function () use ($duplicates) {
                foreach ($duplicates as $group) {
                    $extraIds = DB::table('user_exams')
                        ->where('user_id', $group->user_id)
                        ->where('exam_id', $group->exam_id)
                        ->where('id', '!=', $group->keep_id)
                        ->pluck('id');

                    if ($extraIds->isEmpty()) {
                        continue;
                    }

                    DB::statement(
                        'INSERT INTO '.self::BACKUP_TABLE
                        .' SELECT * FROM user_exams WHERE id IN ('.$extraIds->implode(',').')'
                    );

                    DB::table('user_exams')->whereIn('id', $extraIds)->delete();
                }
            });
        }

        // Idempotent: because the ALTER is auto-committed independently of the
        // de-dup transaction, a failed earlier run can leave the index in place
        // without the migration being recorded. Re-running must then succeed
        // rather than die on "Duplicate key name".
        if (! $this->indexExists()) {
            Schema::table('user_exams', function (Blueprint $table) {
                $table->unique(['user_id', 'exam_id'], self::INDEX);
            });
        }
    }

    private function indexExists(): bool
    {
        return collect(DB::select('SHOW INDEX FROM user_exams'))
            ->contains(fn ($row) => $row->Key_name === self::INDEX);
    }

    public function down(): void
    {
        Schema::table('user_exams', function (Blueprint $table) {
            $table->dropUnique(self::INDEX);
        });

        // The backup table is intentionally left in place: dropping it would
        // destroy the only copy of the rows this migration removed.
    }
};
