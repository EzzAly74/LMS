<?php

namespace App\Http\Controllers\apis\Admin;

use App\Http\Controllers\apis\ApiController;
use App\Jobs\GetAllEmployeesFromHRSystemJob;
use App\Services\HrEmployeeSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * "Update Job titles" / "Update Learners" (Figma 2078:102691, 1986:74701,
 * NEW2B-6184, D-088): pull new employees and job titles from HR now instead of
 * waiting for the daily schedule.
 *
 * The pull runs after the response (it takes longer than a request may),
 * one at a time; GET returns the last run so the page can show when it
 * finished and what changed.
 */
class AdminHrSyncController extends ApiController
{
    public function __construct(private readonly HrEmployeeSyncService $sync) {}

    public function store(Request $request): JsonResponse
    {
        // One run at a time. A run that never reported back (a crash) stops
        // blocking after 15 minutes; the job's own lock still prevents overlap.
        $status = $this->sync->status();
        if (($status['state'] ?? null) === 'running'
            && isset($status['started_at'])
            && \Illuminate\Support\Carbon::parse($status['started_at'])->gt(now()->subMinutes(15))) {
            return $this->success(__('messages.hr_sync_running'), $status, 202);
        }

        $running = [
            'state' => 'running', 'started_at' => now()->toIso8601String(), 'requested_by' => $request->user()?->getKey(),
        ];
        Cache::put(HrEmployeeSyncService::STATUS_KEY, $running, now()->addDays(7));

        GetAllEmployeesFromHRSystemJob::dispatchAfterResponse($request->user()?->getKey());

        return $this->success(__('messages.hr_sync_started'), $running, 202);
    }

    public function show(): JsonResponse
    {
        return $this->success(__('messages.retrieved'), $this->sync->status());
    }
}
