<?php

namespace App\Services;

/**
 * One resolved certificate rule: which metrics a learner must meet, and at
 * what threshold. Pure - it judges percentages it is handed and never
 * measures anything itself.
 *
 * {@see CertificatePolicy::forCourse()} picks the rule a course follows: the
 * Platform Config rule, or the course's own when the Add / Edit Course modal
 * set one ("Use the general certificate rule for this course?" = No).
 */
final class CertificateRule
{
    public function __construct(
        public readonly string $basis,
        public readonly int $minAttendance,
        public readonly int $minScore,
    ) {}

    /**
     * Metrics this rule requires.
     *
     * @return array<int, string>
     */
    public function requiredMetrics(): array
    {
        return match ($this->basis) {
            CertificatePolicy::BASIS_ATTENDANCE => [CertificatePolicy::METRIC_ATTENDANCE],
            CertificatePolicy::BASIS_SCORE      => [CertificatePolicy::METRIC_SCORE],
            default                             => [CertificatePolicy::METRIC_ATTENDANCE, CertificatePolicy::METRIC_SCORE],
        };
    }

    public function requires(string $metric): bool
    {
        return in_array($metric, $this->requiredMetrics(), true);
    }

    public function threshold(string $metric): int
    {
        return $metric === CertificatePolicy::METRIC_ATTENDANCE ? $this->minAttendance : $this->minScore;
    }

    /**
     * Verdict per required metric: true = met, false = missed,
     * null = not measurable for this course (skipped).
     *
     * @return array<string, bool|null>
     */
    public function checks(?int $attendancePercent, ?int $scorePercent): array
    {
        $measured = [
            CertificatePolicy::METRIC_ATTENDANCE => $attendancePercent,
            CertificatePolicy::METRIC_SCORE      => $scorePercent,
        ];

        $checks = [];
        foreach ($this->requiredMetrics() as $metric) {
            $percent = $measured[$metric];
            $checks[$metric] = $percent === null ? null : $percent >= $this->threshold($metric);
        }

        return $checks;
    }

    /** True when no required metric is actively failing; unmeasurable ones are skipped. */
    public function isSatisfiedBy(?int $attendancePercent, ?int $scorePercent): bool
    {
        return !in_array(false, $this->checks($attendancePercent, $scorePercent), true);
    }

    /** True when at least one required metric could actually be measured. */
    public function hasEvidence(?int $attendancePercent, ?int $scorePercent): bool
    {
        foreach ($this->checks($attendancePercent, $scorePercent) as $verdict) {
            if ($verdict !== null) {
                return true;
            }
        }

        return false;
    }
}
