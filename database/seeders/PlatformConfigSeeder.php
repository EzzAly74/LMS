<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Seeds the Platform Settings keys consumed by the Angular admin
 * `/admin/settings` (Platform Config) page.
 *
 * Only inserts rows that don't already exist — existing values are left
 * untouched so admin edits survive `db:seed --class=PlatformConfigSeeder`.
 */
class PlatformConfigSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->rows() as $row) {
            Setting::query()->firstOrCreate(['key' => $row['key']], $row);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(): array
    {
        $module = 'platform';

        return [
            // ── General ───────────────────────────────────────────────

            // ── Enrolment & Attendance ────────────────────────────────
            ['key' => 'default_cohort_size',     'value' => '30', 'type' => 'number',  'label' => 'Default Cohort Size',     'module' => $module],
            ['key' => 'course_attendance_enabled','value' => '1', 'type' => 'boolean', 'label' => 'Course Attendance',       'module' => $module],
            ['key' => 'passcode_reset_seconds',  'value' => '30', 'type' => 'number',  'label' => 'Passcode Reset (seconds)','module' => $module],

            // ── Grading & Certificates ────────────────────────────────
            ['key' => 'abnormal_rating_threshold',  'value' => '30', 'type' => 'number',  'label' => 'Abnormal Rating Threshold', 'module' => $module],
            // The certificate rule — read back by App\Services\CertificatePolicy,
            // which is the single source of truth for certificate eligibility.
            // attendance | score | both
            ['key' => 'certificate_award_basis',    'value' => 'attendance', 'type' => 'text',   'label' => 'Certificate Awarded Based On', 'module' => $module],
            ['key' => 'min_passing_attendance',     'value' => '70',         'type' => 'number', 'label' => 'Min Passing Attendance (%)',    'module' => $module],
            ['key' => 'min_passing_score',          'value' => '30',         'type' => 'number', 'label' => 'Min Passing Score (%)',         'module' => $module],

            // ── About Us (rich text + image) ──────────────────────────
            // header_logo, banner_background, footer_logo, banner_description, why_us already exist via SettingSeeder.
        ];
    }
}
