<?php

namespace Tests\Feature\Api\Setting;

use App\Models\Setting;
use Tests\Feature\Api\ApiTestCase;

/**
 * Platform Config numbers are whole numbers in range (NEW2B-6055,
 * NEW2B-6059): a cohort has at least one seat and nothing is negative.
 */
class PlatformConfigValidationTest extends ApiTestCase
{
    private function saveSettings(array $settings, array $headers)
    {
        return $this->putJson(self::BASE.'/admin/settings', ['settings' => $settings], $headers);
    }

    public static function invalidValues(): array
    {
        return [
            'cohort size 0'          => ['default_cohort_size', '0'],
            'cohort size negative'   => ['default_cohort_size', '-5'],
            'cohort size decimal'    => ['default_cohort_size', '2.5'],
            'offset negative'        => ['academy_default_close_offset_days', '-1'],
            'passcode 0'             => ['passcode_reset_seconds', '0'],
            'attendance over 100'    => ['min_passing_attendance', '101'],
            'score negative'         => ['min_passing_score', '-1'],
            'attendance flag'        => ['course_attendance_enabled', 'yes'],
            'basis'                  => ['certificate_award_basis', 'luck'],
            'cohort size empty'      => ['default_cohort_size', ''],
        ];
    }

    /** @dataProvider invalidValues */
    public function test_out_of_range_values_are_rejected_and_not_saved(string $key, string $value): void
    {
        ['headers' => $h] = $this->adminToken();
        Setting::query()->updateOrCreate(['key' => $key], ['value' => '7', 'module' => 'platform', 'type' => 'number', 'label' => $key]);

        $this->saveSettings([$key => $value], $h)->assertStatus(422)->assertJsonValidationErrors("settings.{$key}");

        $this->assertSame('7', Setting::query()->where('key', $key)->orderBy('id')->value('value'));
    }

    public function test_values_in_range_are_saved(): void
    {
        ['headers' => $h] = $this->adminToken();
        Setting::query()->create(['key' => 'default_cohort_size', 'value' => '30', 'module' => 'platform', 'type' => 'number', 'label' => 'x']);

        $this->saveSettings(['default_cohort_size' => '1', 'academy_default_close_offset_days' => '0', 'min_passing_score' => '100'], $h)->assertOk();

        $this->assertSame('1', Setting::query()->where('key', 'default_cohort_size')->value('value'));
    }
}
