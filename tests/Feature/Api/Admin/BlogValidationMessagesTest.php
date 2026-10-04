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

    public function test_messages_follow_the_request_language(): void
    {
        $admin = $this->adminToken();
        $r = $this->postJson(self::BASE.'/admin/blogs', $this->payload(['subtitle' => ['ar' => str_repeat('ب', 1001)]]),
            $admin['headers'] + ['Accept-Language' => 'ar'])->assertUnprocessable();

        $this->assertStringContainsString('العنوان الفرعي بالعربية', $r->json('errors')['subtitle.ar'][0]);
    }
}
