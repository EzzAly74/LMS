<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Setting;

/**
 * The single source of truth for "how does a learner earn a certificate?".
 *
 * The rule lives in Platform Config (Angular `/admin/settings` → Grading &
 * Certificates), persisted as three `settings` rows:
 *
 *   certificate_award_basis  attendance | score | both
 *   min_passing_attendance   % of cohort sessions the learner must attend
 *   min_passing_score        % of the final exam the learner must reach
 *
 * Before this class those rows were write-only: the admin screen saved them
 * and nothing on the backend ever read them back. Eligibility was instead
 * decided from per-course columns (`courses.certificate_mode`,
 * `certificate_attendance_threshold`, `certificate_score_threshold`) that no
 * API could even set — so the configured rule and the enforced rule were two
 * different things. Everything now reads the rule from here.
 *
 * A course may opt out of the general rule (D6, Figma 2401:126596: "Use the
 * general certificate rule for this course?" = No). Only then do those three
 * course columns apply, and only when `certificate_custom_rule` is true -
 * every course that predates the flag keeps the general rule. Callers that
 * judge a learner in a course ask {@see forCourse()}; the basis / min* methods
 * below answer for the general rule.
 *
 * Division of labour, so the rule is never expressed twice:
 *   - This class owns the RULE (which metrics matter, and at what threshold).
 *   - {@see CertificateProjectionService} owns the MEASUREMENT (turning a
 *     learner+course into an attendance % and a score %), and hands the
 *     numbers back here to be judged.
 *
 * Unmeasurable metrics are SKIPPED, not failed. A course with no session
 * plan cannot produce an attendance percentage, and a course with no final
 * exam cannot produce a score; treating those as failures would mean that
 * flipping the platform to "Attendance only" silently stops every online
 * course from ever certifying. Callers that have no completion trigger of
 * their own (attendance-driven issuance) must additionally require
 * {@see hasEvidence()} so "nothing is measurable" can never mint a
 * certificate on its own.
 */
class CertificatePolicy
{
    public const BASIS_ATTENDANCE = 'attendance';
    public const BASIS_SCORE      = 'score';
    public const BASIS_BOTH       = 'both';

    public const METRIC_ATTENDANCE = 'attendance';
    public const METRIC_SCORE      = 'score';

    public const KEY_BASIS          = 'certificate_award_basis';
    public const KEY_MIN_ATTENDANCE = 'min_passing_attendance';
    public const KEY_MIN_SCORE      = 'min_passing_score';

    /**
     * Fallbacks used only when a settings row is missing entirely. They match
     * the values shipped by PlatformConfigSeeder so a fresh install and a
     * half-seeded install behave identically.
     */
    public const DEFAULT_BASIS          = self::BASIS_ATTENDANCE;
    public const DEFAULT_MIN_ATTENDANCE = 70;
    public const DEFAULT_MIN_SCORE      = 30;

    /** @var array<string, string|null>|null Memoized per request. */
    private ?array $config = null;

    /* ================================================================ *
     |  Configured rule                                                 |
     * ================================================================ */

    public const BASES = [self::BASIS_ATTENDANCE, self::BASIS_SCORE, self::BASIS_BOTH];

    /** A course's `certificate_rule` when it follows Platform Config (D-058). */
    public const RULE_GENERAL = 'general';

    /** The Platform Config rule. */
    public function general(): CertificateRule
    {
        return new CertificateRule($this->basis(), $this->minAttendance(), $this->minScore());
    }

    /**
     * The rule a course follows: its own when it opted out of the general
     * rule, else the general one. A custom rule with a missing threshold for
     * a metric it requires falls back to the general threshold rather than
     * to a guess.
     */
    public function forCourse(Course $course): CertificateRule
    {
        if (!$course->certificate_custom_rule || !in_array($course->certificate_mode, self::BASES, true)) {
            return $this->general();
        }

        return new CertificateRule(
            $course->certificate_mode,
            $this->clamp($course->certificate_attendance_threshold, $this->minAttendance()),
            $this->clamp($course->certificate_score_threshold, $this->minScore()),
        );
    }

    public function basis(): string
    {
        $basis = (string) ($this->config()[self::KEY_BASIS] ?? '');

        return in_array($basis, self::BASES, true)
            ? $basis
            : self::DEFAULT_BASIS;
    }

    public function minAttendance(): int
    {
        return $this->percent(self::KEY_MIN_ATTENDANCE, self::DEFAULT_MIN_ATTENDANCE);
    }

    public function minScore(): int
    {
        return $this->percent(self::KEY_MIN_SCORE, self::DEFAULT_MIN_SCORE);
    }

    /**
     * Metrics the general rule requires.
     *
     * @return array<int, string>
     */
    public function requiredMetrics(): array
    {
        return $this->general()->requiredMetrics();
    }

    public function requires(string $metric): bool
    {
        return $this->general()->requires($metric);
    }

    public function threshold(string $metric): int
    {
        return $this->general()->threshold($metric);
    }

    /* ================================================================ *
     |  Judgement under the general rule - see CertificateRule          |
     * ================================================================ */

    /**
     * @return array<string, bool|null>
     */
    public function checks(?int $attendancePercent, ?int $scorePercent): array
    {
        return $this->general()->checks($attendancePercent, $scorePercent);
    }

    public function isSatisfiedBy(?int $attendancePercent, ?int $scorePercent): bool
    {
        return $this->general()->isSatisfiedBy($attendancePercent, $scorePercent);
    }

    public function hasEvidence(?int $attendancePercent, ?int $scorePercent): bool
    {
        return $this->general()->hasEvidence($attendancePercent, $scorePercent);
    }

    /* ================================================================ *
     |  Internals                                                       |
     * ================================================================ */

    /** Drop the memoized snapshot — used by tests and by the settings writer. */
    public function refresh(): void
    {
        $this->config = null;
    }

    /**
     * Read the three keys in one query.
     *
     * `settings` has historically carried duplicate rows for the same key
     * (see migration 2026_06_02_164052_dedupe_settings_rows_by_key); the
     * lowest id is canonical because that is the row `SettingRepository::
     * updateByKey` edits, so we order by id and keep the first occurrence —
     * matching what the Angular settings screen displays.
     *
     * @return array<string, string|null>
     */
    private function config(): array
    {
        if ($this->config !== null) {
            return $this->config;
        }

        $rows = Setting::query()
            ->whereIn('key', [self::KEY_BASIS, self::KEY_MIN_ATTENDANCE, self::KEY_MIN_SCORE])
            ->orderBy('id')
            ->get(['key', 'value']);

        $config = [];
        foreach ($rows as $row) {
            $config[$row->key] ??= $row->value;
        }

        return $this->config = $config;
    }

    private function percent(string $key, int $default): int
    {
        $raw = $this->config()[$key] ?? null;

        if ($raw === null || trim((string) $raw) === '' || !is_numeric($raw)) {
            return $default;
        }

        return max(0, min(100, (int) $raw));
    }

    private function clamp(?int $value, int $fallback): int
    {
        return $value === null ? $fallback : max(0, min(100, $value));
    }
}
