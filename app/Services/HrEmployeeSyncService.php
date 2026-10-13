<?php

namespace App\Services;

use App\Models\JobTitle;
use App\Models\User;
use App\Support\HrPhoto;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Pulls employees and job titles from the HR system (D-088).
 *
 * One implementation for the daily `sync:employees` schedule, the queued job
 * and the Dashboard "Update Job titles" / "Update Learners" buttons
 * (Figma 2078:102691, 1986:74701, NEW2B-6184). The command and the job used
 * to carry two copies that had drifted apart (the job lost the English
 * names and the national id).
 *
 * Employees are matched on HR's id (`users.system_id`); names and the
 * department come in Arabic and English (NEW2B-6227). A blank HR value never
 * overwrites a stored national id.
 */
final class HrEmployeeSyncService
{
    /** The last run's report, read by GET admin/hr-sync. */
    public const STATUS_KEY = 'hr-sync:status';

    /** Held while a sync runs, so two runs never overlap. */
    public const LOCK_KEY = 'hr-sync:lock';

    public function __construct(
        private readonly HRSystemService $hr,
        private readonly JobTitleSyncService $jobTitles,
    ) {}

    /**
     * @return array{employees: int, created: int, updated: int, job_titles: array<string, int>}
     */
    public function sync(): array
    {
        $employeesAr = $this->hr->getAllEmployees('ar');
        $employeesEn = $this->hr->getAllEmployees('en');

        $english = $employeesEn
            ->filter(fn ($e) => is_object($e) && isset($e->id))
            ->keyBy(fn ($e) => (string) $e->id);

        $jobTitleCache = [];
        $resolveTitleId = static function (?string $jobName) use (&$jobTitleCache): ?int {
            $jobName = $jobName !== null ? trim($jobName) : '';
            if ($jobName === '') {
                return null;
            }

            return $jobTitleCache[$jobName] ??= JobTitle::firstOrCreate(['name' => $jobName])->id;
        };

        $created = 0;
        $updated = 0;

        foreach ($employeesAr as $employee) {
            if (! is_object($employee) || ! isset($employee->id)) {
                continue;
            }

            $en     = $english->get((string) $employee->id);
            $nameAr = (string) ($employee->name ?? '');
            $nameEn = $en !== null ? (string) ($en->name ?? '') : '';

            $values = [
                'name'               => $nameAr,
                'name_ar'            => $nameAr ?: null,
                'name_en'            => $nameEn ?: null,
                'email'              => $employee->email ?? null,
                'phone'              => $employee->phone ?? null,
                'machine_code'       => $employee->machineCode ?? null,
                'department_name'    => $employee->departmentName ?? null,
                'department_name_en' => $en !== null && ($en->departmentName ?? '') !== '' ? $en->departmentName : null,
                'job_title_id'       => $resolveTitleId($employee->jobName ?? null),
                // HR's photo (NEW2B-6219); cleared when HR drops it.
                'hr_image_path'      => HrPhoto::path($employee->imageUrl ?? null),
            ];
            // HR uses the national id as the learner's password; mirrored
            // locally (see UserAuthService). Never overwrite it with a blank.
            if (! empty($employee->nationalId)) {
                $values['national_id'] = $employee->nationalId;
            }

            $user = User::updateOrCreate(['system_id' => $employee->id], $values);
            $user->wasRecentlyCreated ? $created++ : $updated++;
        }

        $report = [
            'employees'  => $employeesAr->count(),
            'created'    => $created,
            'updated'    => $updated,
            'job_titles' => $this->jobTitles->syncFromHr(),
        ];

        Log::info('HR sync finished', ['employees' => $report['employees'], 'created' => $created, 'updated' => $updated]);

        return $report;
    }

    /**
     * Run under the lock and record the outcome for the Dashboard. Returns
     * false when another run holds the lock.
     */
    public function runAndRecord(?int $requestedBy = null): bool
    {
        $lock = Cache::lock(self::LOCK_KEY, 900);
        if (! $lock->get()) {
            return false;
        }

        Cache::put(self::STATUS_KEY, [
            'state' => 'running', 'started_at' => now()->toIso8601String(), 'requested_by' => $requestedBy,
        ], now()->addDays(7));

        // Without the HR address and account nothing can be pulled: say so
        // instead of a bare failure (the Dashboard explains it).
        if (blank(config('hr.base_url')) || blank(config('hr.admin_email')) || blank(config('hr.admin_password'))) {
            Cache::put(self::STATUS_KEY, [
                'state' => 'failed', 'reason' => 'not_configured',
                'finished_at' => now()->toIso8601String(), 'requested_by' => $requestedBy,
            ], now()->addDays(7));
            $lock->release();

            return true;
        }

        try {
            $report = $this->sync();
            Cache::put(self::STATUS_KEY, [
                'state' => $report['employees'] > 0 ? 'done' : 'failed',
                // HR answered nothing: unreachable or refused the account.
                'reason' => $report['employees'] > 0 ? null : 'no_data',
                'finished_at' => now()->toIso8601String(),
                'requested_by' => $requestedBy,
                'report' => $report,
            ], now()->addDays(7));
        } catch (\Throwable $e) {
            // HR errors are logged by HRSystemService; never the credentials.
            Log::error('HR sync failed', ['error' => $e->getMessage()]);
            Cache::put(self::STATUS_KEY, [
                'state' => 'failed', 'reason' => 'error', 'finished_at' => now()->toIso8601String(), 'requested_by' => $requestedBy,
            ], now()->addDays(7));
        } finally {
            $lock->release();
        }

        return true;
    }

    /** @return array<string, mixed>|null */
    public function status(): ?array
    {
        $status = Cache::get(self::STATUS_KEY);

        return is_array($status) ? $status : null;
    }
}
