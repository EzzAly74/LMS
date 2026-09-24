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
 */
class PublicSettingsTest extends ApiTestCase
{
    private function seedSettings(): void
    {
        $rows = [
            // Public
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

    public function test_public_settings_return_only_allow_listed_keys(): void
    {
        $this->seedSettings();

        $result = $this->getJson(self::BASE.'/settings')->assertOk()->json('result');

        $this->assertSame(
            ['email1', 'facebook', 'platform_name'],
            collect(array_keys($result))->sort()->values()->all(),
        );
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
