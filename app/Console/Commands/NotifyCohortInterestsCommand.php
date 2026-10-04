<?php

namespace App\Console\Commands;

use App\Services\Learner\CohortInterestNotifier;
use Illuminate\Console\Command;

/**
 * Daily: a scheduled cohort can become joinable without being saved (its
 * visibility window opens, or a seat frees up), so the waiting learners are
 * checked once a day too (NEW2B-5780).
 */
class NotifyCohortInterestsCommand extends Command
{
    protected $signature = 'cohorts:notify-interests';

    protected $description = 'Tell learners who asked to be notified that a cohort they can join is open';

    public function handle(CohortInterestNotifier $notifier): int
    {
        $this->info('Notified '.$notifier->notifyAll().' learner(s).');

        return self::SUCCESS;
    }
}
