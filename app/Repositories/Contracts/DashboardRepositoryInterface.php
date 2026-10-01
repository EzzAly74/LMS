<?php

namespace App\Repositories\Contracts;

use Illuminate\Support\Collection;

interface DashboardRepositoryInterface
{
    /** @param list<int>|null $courseIds null = every course (D-074) */
    public function getStatistics(?array $courseIds = null): array;
    public function getTopCourses(int $limit): Collection;
    public function getEnrollmentTrend(int $days, ?array $courseIds = null): array;

    /**
     * Range-aware enrollment trend used by the 2026 dashboard chart.
     *
     * @param  'week'|'month'|'quarter'|'year'  $range
     * @return array<int, array{date: string, label: string, enrollments: int, completions: int}>
     */
    public function getEnrollmentTrendByRange(string $range, ?array $courseIds = null): array;
}
