<?php

namespace App\Console\Commands;

use App\Services\HrEmployeeSyncService;
use Illuminate\Console\Command;

/**
 * The HR pull (routes/console.php, D-108). The work is HrEmployeeSyncService,
 * the same code the Dashboard "Update Job titles" / "Update Learners" buttons
 * run (D-088), under the same lock and recorded the same way.
 *
 * `--if-due` (the schedule, every hour): pulls only when no pull succeeded in
 * the last 24 hours, so a missed or failed run is retried within the hour.
 */
class GetAllEmployeesFromHRSystemCommand extends Command
{
    protected $signature = 'sync:employees {--if-due : Only when the last successful pull is 24 hours old}';

    protected $description = 'Get All Employees From HR System';

    public function handle(HrEmployeeSyncService $sync): int
    {
        if ($this->option('if-due') && ! $sync->isDue()) {
            $this->info('HR sync not due: last success '.$sync->lastSuccessAt()?->toDateTimeString().'.');

            return self::SUCCESS;
        }

        if (! $sync->runAndRecord(null, 'schedule')) {
            $this->info('Another HR sync is running.');

            return self::SUCCESS;
        }

        $status = $sync->status();
        if (($status['state'] ?? null) !== 'done') {
            $this->error('HR sync failed: '.($status['reason'] ?? 'error').'.');

            return self::FAILURE;
        }

        $report = $status['report'];
        $this->info(sprintf('Employees: %d (%d created, %d updated).', $report['employees'], $report['created'], $report['updated']));
        $jobs = $report['job_titles'];
        $this->info(sprintf(
            'Job-titles catalogue: %d HR jobs, %d eligible (employees > 0), %d created, %d unchanged, %d orphaned.',
            $jobs['source_rows'] ?? 0,
            $jobs['eligible'] ?? 0,
            $jobs['created'] ?? 0,
            $jobs['unchanged'] ?? 0,
            $jobs['orphaned'] ?? 0,
        ));

        return self::SUCCESS;
    }
}
