<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bulk loads end-users from a snapshot of the HR roster.
 *
 * ---------------------------------------------------------------------------
 * B-106 (Critical) — this seeder used to destroy production data on deploy.
 *
 * `run()` does `DB::table('users')->delete()` and then replays a July 2026
 * snapshot. `deploy.sh` ran `php artisan db:seed --force` on every deploy, and
 * DatabaseSeeder calls this class. Every deploy therefore deleted every user
 * row and restored the stale snapshot, destroying every account created since
 * — along with the enrolments, exam answers, attendance and certificates keyed
 * to those ids.
 *
 * Two changes close it:
 *   1. `db:seed --force` is gone from `deploy.sh` (D-004) and must not return.
 *   2. The guard below refuses to run destructively against a database that
 *      already holds users, unless the operator opts in explicitly. Defence in
 *      depth: the seeder is safe even if someone runs `db:seed --force` by
 *      hand on a populated database.
 *
 * To reload the fixture deliberately on a dev machine:
 *   SEED_REPLACE_USERS=true php artisan db:seed --class=UserSeeder
 * ---------------------------------------------------------------------------
 *
 * The fixture (`data/users.sql`) contains real employee PII — names, corporate
 * emails, mobile numbers, departments and password hashes (F-003). It is still
 * tracked in git and still present in history. Untracking it here would not
 * remove it from history, and would break local seeding, so the real fix is the
 * history purge plus a synthetic replacement fixture (Q-002), which is a human
 * action. Do not add further real data to it.
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $path = __DIR__.'/data/users.sql';

        if (! file_exists($path)) {
            $this->command?->warn("UserSeeder: data fixture missing at {$path}; skipping.");

            return;
        }

        if (! Schema::hasTable('users')) {
            $this->command?->warn('UserSeeder: users table does not exist; skipping.');

            return;
        }

        $existing = DB::table('users')->count();
        $optedIn  = filter_var(env('SEED_REPLACE_USERS', false), FILTER_VALIDATE_BOOLEAN);

        /*
         * Replaying the fixture is a delete-and-restore, not an upsert. On a
         * database that already holds users that is data loss, so it needs an
         * explicit opt-in regardless of environment — production, staging or a
         * colleague's dev box that has real work in it.
         */
        if ($existing > 0 && ! $optedIn) {
            $this->command?->warn(
                "UserSeeder: skipped - the users table already holds {$existing} rows and this "
                .'seeder replaces the table wholesale. Re-run with SEED_REPLACE_USERS=true only '
                .'if you intend to discard them (see B-106).'
            );

            return;
        }

        if (app()->environment('production')) {
            $this->command?->error(
                'UserSeeder: refusing to run in production. It deletes every user row. '
                .'If a roster reload is genuinely required, do it as a reviewed migration.'
            );

            return;
        }

        $sql = file_get_contents($path);

        if ($sql === false || trim($sql) === '') {
            return;
        }

        DB::connection()->getPdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            DB::table('users')->delete();
            DB::unprepared($sql);
        } finally {
            DB::connection()->getPdo()->exec('SET FOREIGN_KEY_CHECKS=1');
        }

        $count = DB::table('users')->count();
        $this->command?->info("UserSeeder: loaded {$count} users from fixture.");
    }
}
