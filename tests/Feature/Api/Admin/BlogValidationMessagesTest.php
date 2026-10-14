<?php

namespace Tests\Feature\Api\Admin;

use Tests\Feature\Api\ApiTestCase;

/**
 * NEW2B-5877 / 5878 / 5890: blog limits come back as clear messages that name
 * the field ("English subtitle", "reading time"), not "subtitle.en", in the
 * request language; nothing is saved.
 */
class BlogValidationMessagesTest extends ApiTestCase
{
    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'title' => ['en' => 'Leading hybrid teams', 'ar' => 'قيادة الفرق الهجينة'],
            'subtitle' => ['en' => 'Short', 'ar' => 'قصير'],
            'level' => 'beginner', 'is_anonymous' => true, 'reading_time' => 5,
            'sections' => [['title' => ['en' => 'Intro', 'ar' => 'مقدمة'], 'body' => ['en' => '<p>x</p>', 'ar' => '<p>س</p>'],
                'quote' => ['en' => 'q', 'ar' => 'ق']]],
        ], $overrides);
    }

    public function test_over_limit_fields_name_themselves_in_english(): void
    {
        $admin = $this->adminToken();
        $r = $this->postJson(self::BASE.'/admin/blogs', $this->payload([
            'subtitle' => ['en' => str_repeat('a', 1001)],
            'reading_time' => 1001,
            'sections' => [['quote' => ['ar' => str_repeat('ق', 1001)]]],
        ]), $admin['headers'] + ['Accept-Language' => 'en'])->assertUnprocessable();

        $this->assertStringContainsString('English subtitle', $r->json('errors')['subtitle.en'][0]);
        $this->assertStringContainsString('1000', $r->json('errors')['subtitle.en'][0]);
        $this->assertStringContainsString('reading time', $r->json('errors')['reading_time'][0]);
        $this->assertStringContainsString('section Arabic quote', $r->json('errors')['sections.0.quote.ar'][0]);
        foreach ($r->json('errors') as $messages) {
            $this->assertDoesNotMatchRegularExpression('/\b(subtitle|title|sections)\.\w+/', $messages[0]);
        }
        $this->assertDatabaseCount('blogs', 0);
    }

    /** NEW2B-5965: a blog name is at most 100 characters. NEW2B-5950: images are PNG / JPG / WebP up to 2 MB. */
    public function test_name_and_image_limits(): void
    {
        $h = $this->adminToken()['headers'] + ['Accept-Language' => 'en'];
        $url = self::BASE.'/admin/blogs';
        $png = fn (int $kb) => \Illuminate\Http\UploadedFile::fake()->image('c.png', 800, 450)->size($kb);

        $this->post($url, $this->payload(['title' => ['en' => str_repeat('a', 101)]]) + ['image' => $png(100)], $h + ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('title.en');
        $this->post($url, $this->payload() + ['image' => $png(2049)], $h + ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('image');
        $this->post($url, $this->payload() + ['image' => \Illuminate\Http\UploadedFile::fake()->image('c.gif')], $h + ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('image');
        $withSection = $this->payload();
        $withSection['sections'][0]['image'] = $png(3000);
        $this->post($url, $withSection + ['image' => $png(100)], $h + ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('sections.0.image');
        $this->assertDatabaseCount('blogs', 0);
    }

    /** A title with URL characters, or at the 100-character limit, still saves with a safe slug. */
    public function test_the_slug_is_url_safe_and_fits_its_column(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $h = $this->adminToken()['headers'] + ['Accept' => 'application/json'];
        $png = fn () => \Illuminate\Http\UploadedFile::fake()->image('c.png', 800, 450)->size(100);
        $url = self::BASE.'/admin/blogs';

        $this->post($url, $this->payload(['title' => ['en' => 'Safety: 101 / Part #2?']]) + ['image' => $png()], $h)->assertSuccessful();
        $this->post($url, $this->payload(['title' => ['en' => 'Safety: 101 / Part #2?']]) + ['image' => $png()], $h)->assertSuccessful();
        $this->post($url, $this->payload(['title' => ['en' => str_repeat('word ', 20)]]) + ['image' => $png()], $h)->assertSuccessful();

        $slugs = \App\Models\Blog::query()->orderBy('id')->pluck('slug')->all();
        $this->assertSame(['safety-101-part-2', 'safety-101-part-2-2'], array_slice($slugs, 0, 2));
        $this->assertLessThanOrEqual(150, mb_strlen($slugs[2]));
        foreach ($slugs as $slug) {
            $this->assertMatchesRegularExpression('/^[\p{Arabic}a-z0-9-]+$/u', $slug);
        }
    }

    public function test_messages_follow_the_request_language(): void
    {
        $admin = $this->adminToken();
        $r = $this->postJson(self::BASE.'/admin/blogs', $this->payload(['subtitle' => ['ar' => str_repeat('ب', 1001)]]),
            $admin['headers'] + ['Accept-Language' => 'ar'])->assertUnprocessable();

        $this->assertStringContainsString('العنوان الفرعي بالعربية', $r->json('errors')['subtitle.ar'][0]);
    }
}
