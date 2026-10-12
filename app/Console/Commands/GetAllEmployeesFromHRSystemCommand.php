<?php

namespace App\Console\Commands;

use App\Services\HrEmployeeSyncService;
use Illuminate\Console\Command;

/**
 * Daily HR pull (routes/console.php). The work is HrEmployeeSyncService, the
 * same code the Dashboard "Update Job titles" / "Update Learners" buttons run
 * (D-088).
 */
class GetAllEmployeesFromHRSystemCommand extends Command
{
    protected $signature = 'sync:employees';

    protected $description = 'Get All Employees From HR System';

    public function handle(HrEmployeeSyncService $sync): int
    {
        $report = $sync->sync();

        if ($report['employees'] === 0) {
            $this->info('no employees');
        }

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
