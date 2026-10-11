<?php

namespace Tests\Feature\Api;

use App\Models\Blog;
use App\Models\JobTitle;
use App\Models\QualificationSkill;
use Illuminate\Support\Facades\DB;

/**
 * NEW2B-5888: the blog filters show how many published blogs each job title
 * and each level has.
 */
class BlogFilterCountsTest extends ApiTestCase
{
    private function blog(string $slug, string $level, array $qualificationIds, bool $published = true): void
    {
        $blog = Blog::query()->create([
            'title' => ['en' => $slug, 'ar' => $slug], 'slug' => $slug, 'level' => $level, 'is_anonymous' => true,
            'reading_time' => 3, 'active' => $published, 'published_at' => now()->subDay(),
        ]);
        foreach ($qualificationIds as $q) {
            DB::table('blog_qualification_skill')->insert(['blog_id' => $blog->id, 'qualification_skill_id' => $q]);
        }
    }

    public function test_job_titles_and_levels_carry_published_blog_counts(): void
    {
        $safety = QualificationSkill::factory()->create();
        $excel = QualificationSkill::factory()->create();
        $manager = JobTitle::query()->create(['name' => 'Operations Manager', 'name_en' => 'Operations Manager', 'name_ar' => 'مدير العمليات']);
        $analyst = JobTitle::query()->create(['name' => 'Analyst', 'name_en' => 'Analyst', 'name_ar' => 'محلل']);
        DB::table('job_title_qualification_skill')->insert([
            ['job_title_id' => $manager->id, 'qualification_skill_id' => $safety->id],
            ['job_title_id' => $manager->id, 'qualification_skill_id' => $excel->id],
            ['job_title_id' => $analyst->id, 'qualification_skill_id' => $excel->id],
        ]);

        $this->blog('a', 'beginner', [$safety->id, $excel->id]); // counts once for the manager
        $this->blog('b', 'beginner', [$excel->id]);
        $this->blog('c', 'professional', [$safety->id]);
        $this->blog('draft', 'professional', [$excel->id], published: false);

        $titles = collect($this->getJson(self::BASE.'/blogs/job-titles', ['Accept-Language' => 'en'])->assertOk()->json('result'))
            ->pluck('count', 'name')->all();
        $this->assertSame(['Analyst' => 2, 'Operations Manager' => 3], $titles);

        $this->getJson(self::BASE.'/blogs/levels')->assertOk()->assertExactJson([
            'status' => 'success', 'message' => __('messages.retrieved'),
            'result' => [
                ['value' => 'beginner', 'count' => 2],
                ['value' => 'intermediate', 'count' => 0],
                ['value' => 'professional', 'count' => 1],
            ],
        ]);
    }
}
