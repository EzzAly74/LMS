<?php

namespace Tests\Feature\Api;

use App\Models\Blog;
use App\Models\JobTitle;
use App\Models\User;

/**
 * NEW2B-5892: the blog author line shows the author's job title in the page
 * language (it showed the HR department, Arabic-only, on the English site).
 */
class BlogAuthorTitleTest extends ApiTestCase
{
    public function test_the_author_title_is_the_job_title_in_the_request_language(): void
    {
        $title = JobTitle::query()->create(['name' => 'أخصائي تطوير', 'name_ar' => 'أخصائي تطوير', 'name_en' => 'Development Specialist']);
        $author = User::factory()->create(['job_title_id' => $title->id, 'department_name' => 'إدارة الموارد البشرية']);
        Blog::query()->create([
            'title' => ['en' => 'Leading hybrid teams', 'ar' => 'قيادة الفرق'], 'slug' => 'lead',
            'level' => 'beginner', 'author_user_id' => $author->id, 'is_anonymous' => false, 'reading_time' => 4,
            'active' => true, 'published_at' => now()->subDay(),
        ]);

        $this->getJson(self::BASE.'/blogs/lead', ['Accept-Language' => 'en'])->assertOk()
            ->assertJsonPath('result.author_bio.title', 'Development Specialist');
        $this->getJson(self::BASE.'/blogs/lead', ['Accept-Language' => 'ar'])->assertOk()
            ->assertJsonPath('result.author_bio.title', 'أخصائي تطوير');
    }

    public function test_no_job_title_means_no_title_line(): void
    {
        $author = User::factory()->create(['job_title_id' => null, 'department_name' => 'إدارة الموارد البشرية']);
        Blog::query()->create([
            'title' => ['en' => 'Leading hybrid teams', 'ar' => 'قيادة الفرق'], 'slug' => 'lead2',
            'level' => 'beginner', 'author_user_id' => $author->id, 'is_anonymous' => false, 'reading_time' => 4,
            'active' => true, 'published_at' => now()->subDay(),
        ]);

        $this->getJson(self::BASE.'/blogs/lead2', ['Accept-Language' => 'en'])->assertOk()
            ->assertJsonPath('result.author_bio.title', null);
    }
}
