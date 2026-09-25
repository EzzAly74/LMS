<?php

namespace Tests\Feature\Api\Setting;

use App\Models\Setting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\ApiTestCase;

/**
 * Regression tests for B-16 and B-17 (both Medium).
 *
 * B-16: POST admin/settings/upload validated `key` only as a string, then fed
 *       it to updateMany() — so it could overwrite ANY setting with a file
 *       path, including mobile_shared_bearer_token, or invent new keys.
 * B-17: the rules used Laravel's `image`, which accepts SVG. SVG is an
 *       executable document and is served inline from the public disk.
 */
class SettingUploadSecurityTest extends ApiTestCase
{
    private function seedSettings(): void
    {
        // The real file setting the product still uses. This was `footer_logo`
        // until 2026-09-25, when the footer/header/banner/about settings were
        // removed as read by nothing (I18N-03) - a test named "a real file
        // setting" should exercise one that actually exists.
        Setting::query()->create([
            'key' => 'certificate', 'label' => 'Certificate', 'value' => null,
            'type' => 'file', 'module' => 'home',
        ]);
        Setting::query()->create([
            'key' => 'mobile_shared_bearer_token', 'label' => 'Mobile token',
            'value' => 'original-secret', 'type' => 'string', 'module' => 'mobile_security',
        ]);
    }

    private function png(string $name = 'logo.png'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 64, 64);
    }

    public function test_upload_cannot_target_a_non_file_setting(): void
    {
        Storage::fake('public');
        $this->seedSettings();
        ['headers' => $headers] = $this->adminToken();

        $this->post(self::BASE.'/admin/settings/upload', [
            'key'  => 'mobile_shared_bearer_token',
            'file' => $this->png(),
        ], $headers + ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertSame(
            'original-secret',
            Setting::query()->where('key', 'mobile_shared_bearer_token')->value('value'),
            'The mobile token must not have been overwritten with a file path.',
        );
    }

    public function test_upload_cannot_invent_a_new_setting_key(): void
    {
        Storage::fake('public');
        $this->seedSettings();
        ['headers' => $headers] = $this->adminToken();

        $this->post(self::BASE.'/admin/settings/upload', [
            'key'  => 'a_brand_new_key',
            'file' => $this->png(),
        ], $headers + ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertDatabaseMissing('settings', ['key' => 'a_brand_new_key']);
    }

    public function test_upload_to_a_real_file_setting_still_works(): void
    {
        Storage::fake('public');
        $this->seedSettings();
        ['headers' => $headers] = $this->adminToken();

        $this->post(self::BASE.'/admin/settings/upload', [
            'key'  => 'certificate',
            'file' => $this->png(),
        ], $headers + ['Accept' => 'application/json'])->assertOk();

        $this->assertNotNull(Setting::query()->where('key', 'certificate')->value('value'));
    }

    public function test_svg_is_rejected(): void
    {
        Storage::fake('public');
        $this->seedSettings();
        ['headers' => $headers] = $this->adminToken();

        $svg = UploadedFile::fake()->createWithContent(
            'logo.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        );

        $this->post(self::BASE.'/admin/settings/upload', [
            'key'  => 'certificate',
            'file' => $svg,
        ], $headers + ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertCount(0, Storage::disk('public')->allFiles());
    }

    public function test_upload_requires_an_admin(): void
    {
        Storage::fake('public');
        $this->seedSettings();
        ['headers' => $headers] = $this->userToken();

        $this->post(self::BASE.'/admin/settings/upload', [
            'key'  => 'certificate',
            'file' => $this->png(),
        ], $headers + ['Accept' => 'application/json'])->assertForbidden();
    }
}
