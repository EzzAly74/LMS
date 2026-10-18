<?php

namespace App\Services;

use App\Models\JobTitle;
use App\Models\User;
use App\Support\HrPhoto;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

    /** The automatic pull runs when the last success is this old (D-108). */
    public const AUTO_EVERY_HOURS = 24;

    /** Hourly retries allowed before the Dashboard calls the sync overdue. */
    public const OVERDUE_GRACE_HOURS = 3;

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
                // Report fields (D-112): hire date, work location, status.
                'hire_date'          => self::hrDate($employee->hiringDate ?? null)
                    ?? self::hrDate($employee->hirDate ?? null)
                    ?? self::hrDate($employee->joininDate ?? null),
                'location_name'      => self::locations($employee->locationNames ?? null),
                'location_name_en'   => $en !== null ? self::locations($en->locationNames ?? null) : null,
                'hr_employee_status' => is_numeric($employee->employeeStatus ?? null) ? (int) $employee->employeeStatus : null,
                'resignation_date'   => self::hrDate($employee->resignationDate ?? null),
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
     * Run under the lock and record the outcome for the Dashboard and in
     * `hr_sync_runs` (D-108). Returns false when another run holds the lock.
     *
     * @param  'manual'|'schedule'  $source
     */
    public function runAndRecord(?int $requestedBy = null, string $source = 'manual'): bool
    {
        $lock = Cache::lock(self::LOCK_KEY, 900);
        if (! $lock->get()) {
            return false;
        }

        $startedAt = now();
        Cache::put(self::STATUS_KEY, [
            'state' => 'running', 'started_at' => $startedAt->toIso8601String(), 'requested_by' => $requestedBy,
        ], now()->addDays(7));

        try {
            $status = $this->pull($requestedBy);
            Cache::put(self::STATUS_KEY, $status, now()->addDays(7));
            $this->remember($status, $source, $requestedBy, $startedAt);
        } finally {
            $lock->release();
        }

        return true;
    }

    /** @return array<string, mixed> the finished status */
    private function pull(?int $requestedBy): array
    {
        $finished = fn (array $s) => $s + ['finished_at' => now()->toIso8601String(), 'requested_by' => $requestedBy];

        // Without the HR address and account nothing can be pulled: say so
        // instead of a bare failure (the Dashboard explains it).
        if (blank(config('hr.base_url')) || blank(config('hr.admin_email')) || blank(config('hr.admin_password'))) {
            return $finished(['state' => 'failed', 'reason' => 'not_configured']);
        }

        try {
            $report = $this->sync();

            return $finished([
                'state' => $report['employees'] > 0 ? 'done' : 'failed',
                // HR answered nothing: unreachable or refused the account.
                'reason' => $report['employees'] > 0 ? null : 'no_data',
                'report' => $report,
            ]);
        } catch (\Throwable $e) {
            // HR errors are logged by HRSystemService; never the credentials.
            Log::error('HR sync failed', ['error' => $e->getMessage()]);

            return $finished(['state' => 'failed', 'reason' => 'error']);
        }
    }

    /** @param  array<string, mixed>  $status */
    private function remember(array $status, string $source, ?int $requestedBy, \Carbon\CarbonInterface $startedAt): void
    {
        try {
            DB::table('hr_sync_runs')->insert([
                'source' => $source,
                'state' => $status['state'],
                'reason' => $status['reason'] ?? null,
                'requested_by' => $requestedBy,
                'employees' => (int) ($status['report']['employees'] ?? 0),
                'created' => (int) ($status['report']['created'] ?? 0),
                'updated' => (int) ($status['report']['updated'] ?? 0),
                'started_at' => $startedAt,
                'finished_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // The pull itself succeeded or failed already; never lose it to the log row.
            report($e);
        }
    }

    /**
     * An HR date as Y-m-d. HR sends "0001-01-01T00:00:00" for "no date"
     * (.NET's DateTime.MinValue), which is not a date.
     */
    private static function hrDate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $date = Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }

        return $date->year <= 1900 ? null : $date->toDateString();
    }

    /** HR's work locations (a list) as one comma-separated value. */
    private static function locations(mixed $value): ?string
    {
        $names = collect(is_array($value) ? $value : [$value])
            ->filter(fn ($name) => is_string($name) && trim($name) !== '')
            ->map(fn (string $name) => trim($name))
            ->unique()
            ->implode(', ');

        return $names === '' ? null : mb_substr($names, 0, 255);
    }

    /** When HR last updated the learners successfully (any source). */
    public function lastSuccessAt(): ?CarbonInterface
    {
        $at = DB::table('hr_sync_runs')->where('state', 'done')->max('finished_at');

        return $at === null ? null : Carbon::parse($at);
    }

    /**
     * The automatic sync is due when no pull succeeded in the last
     * AUTO_EVERY_HOURS. Checked every hour, so a missed or failed run is
     * retried within the hour instead of the next day.
     */
    public function isDue(): bool
    {
        $last = $this->lastSuccessAt();

        return $last === null || $last->lte(now()->subHours(self::AUTO_EVERY_HOURS));
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $status = Cache::get(self::STATUS_KEY);
        $status = is_array($status) ? $status : ['state' => 'idle'];
        $last = $this->lastSuccessAt();

        return $status + [
            'last_success_at' => $last?->toIso8601String(),
            'auto_every_hours' => self::AUTO_EVERY_HOURS,
            // No success for longer than a day and a few hourly retries: the
            // scheduler is not running or HR keeps failing.
            'overdue' => $last === null || $last->lte(now()->subHours(self::AUTO_EVERY_HOURS + self::OVERDUE_GRACE_HOURS)),
        ];
    }
}
