<?php

namespace Tests\Feature\Api\Setting;

use App\Models\Setting;
use Tests\Feature\Api\ApiTestCase;

/**
 * Regression tests for B-01 (Critical).
 *
 * The unauthenticated `GET /api/v1/settings` used to return every setting
 * whose `type` was not `file`. That included `mobile_shared_bearer_token` —
 * the single shared secret guarding `/api/v1/mobile/*`. With it, and a machine
 * code (a non-secret employee number), anyone could impersonate any employee.
 *
 * The endpoint is now deny-by-default: it returns only the keys named in
 * SettingService::PUBLIC_KEYS.
 *
 * Since 2026-09-25 that list is EMPTY (I18N-03). Its 14 keys - platform
 * identity, contact details, social links, why_us - were read by no client:
 * the Website never calls this endpoint, the Dashboard edits through
 * admin/settings, and the human confirmed the mobile app does not use it. The
 * seed data below still creates those rows, so an empty response proves the
 * endpoint FILTERS them rather than merely finding an empty table.
 */
class PublicSettingsTest extends ApiTestCase
{
    private function seedSettings(): void
    {
        $rows = [
            // Formerly public, now private (I18N-03). They exist in the table
            // and must still NOT be returned.
            ['key' => 'platform_name', 'label' => 'platform_name', 'value' => '2B Academy', 'type' => 'text',   'module' => 'platform'],
            ['key' => 'email1', 'label' => 'email1',        'value' => 'info@2b.test', 'type' => 'text', 'module' => 'contact'],
            ['key' => 'facebook', 'label' => 'facebook',      'value' => 'https://fb.test/2b', 'type' => 'url', 'module' => 'social'],
            // Secret — must never be published
            ['key' => 'mobile_shared_bearer_token', 'label' => 'mobile_shared_bearer_token', 'value' => 'super-secret-token-value', 'type' => 'string', 'module' => 'mobile_security'],
            // Internal tuning — not secret, but not the public site's business
            ['key' => 'min_passing_score', 'label' => 'min_passing_score', 'value' => '60', 'type' => 'number', 'module' => 'platform'],
            ['key' => 'passcode_reset_seconds', 'label' => 'passcode_reset_seconds', 'value' => '30', 'type' => 'number', 'module' => 'mobile_attendance'],
        ];

        foreach ($rows as $row) {
            Setting::query()->create($row);
        }
    }

    public function test_public_settings_do_not_expose_the_mobile_shared_token(): void
    {
        $this->seedSettings();

        $response = $this->getJson(self::BASE.'/settings');

        $response->assertOk();
        $result = $response->json('result');

        $this->assertArrayNotHasKey('mobile_shared_bearer_token', $result);
        $this->assertStringNotContainsString('super-secret-token-value', $response->getContent());
    }

    public function test_public_settings_expose_nothing_now_the_allow_list_is_empty(): void
    {
        $this->seedSettings();

        $result = $this->getJson(self::BASE.'/settings')->assertOk()->json('result');

        // Rows exist for platform_name, email1 and facebook (see seedSettings),
        // so this is the filter at work, not an empty table.
        $this->assertSame([], $result);
        $this->assertGreaterThan(0, Setting::query()->count());
    }

    public function test_internal_tuning_settings_are_not_public(): void
    {
        $this->seedSettings();

        $result = $this->getJson(self::BASE.'/settings')->assertOk()->json('result');

        $this->assertArrayNotHasKey('min_passing_score', $result);
        $this->assertArrayNotHasKey('passcode_reset_seconds', $result);
    }

    public function test_a_newly_added_setting_is_private_by_default(): void
    {
        $this->seedSettings();
        Setting::query()->create([
            'key'    => 'some_future_secret',
            'label'  => 'some_future_secret',
            'value'  => 'do-not-publish',
            'type'   => 'string',
            'module' => 'platform',
        ]);

        $response = $this->getJson(self::BASE.'/settings')->assertOk();

        $this->assertArrayNotHasKey('some_future_secret', $response->json('result'));
        $this->assertStringNotContainsString('do-not-publish', $response->getContent());
    }

    public function test_admin_can_still_read_the_full_settings_list(): void
    {
        $this->seedSettings();
        ['headers' => $headers] = $this->adminToken();

        $response = $this->getJson(self::BASE.'/admin/settings', $headers);

        $response->assertOk();
        $this->assertStringContainsString('mobile_shared_bearer_token', $response->getContent());
    }

    public function test_settings_endpoint_requires_no_auth_and_still_works(): void
    {
        $this->seedSettings();

        $this->getJson(self::BASE.'/settings')->assertOk()->assertJsonStructure(['result']);
    }
}
