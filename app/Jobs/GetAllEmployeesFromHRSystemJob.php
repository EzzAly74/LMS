<?php

namespace App\Jobs;

use App\Services\HrEmployeeSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The HR pull as a job: the Dashboard "Update Job titles" / "Update Learners"
 * buttons dispatch it after the response (D-088). It used to carry its own
 * copy of the sync that had lost the English names and the national id.
 */
class GetAllEmployeesFromHRSystemJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public function __construct(public readonly ?int $requestedBy = null)
    {
    }

    public function handle(HrEmployeeSyncService $sync): void
    {
        // About a thousand employees plus three HR calls: more than a web
        // request's 30 seconds when the queue runs inline.
        @set_time_limit($this->timeout);

        $sync->runAndRecord($this->requestedBy);
    }
}
