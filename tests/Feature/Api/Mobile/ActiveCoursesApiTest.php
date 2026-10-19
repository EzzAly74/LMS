<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\Category;
use App\Models\Course;
use App\Models\CourseRating;
use App\Models\Instructor;
use App\Models\QualificationSkill;
use Illuminate\Support\Facades\DB;

/**
 * `GET /api/v1/mobile/courses`: every active course, paginated, with the
 * shared token and Accept-Language only (no Employee-Code).
 */
class ActiveCoursesApiTest extends MobileTestCase
{
    private const URL = self::BASE . '/mobile/courses';

    public function test_lists_only_active_courses_newest_first_without_employee_code(): void
    {
        $older    = Course::factory()->create(['created_at' => now()->subDays(2)]);
        $newer    = Course::factory()->create(['created_at' => now()->subDay()]);
        Course::factory()->inactive()->create(['created_at' => now()]);

        $response = $this->withHeaders($this->tokenOnlyHeaders())->getJson(self::URL);

        $this->assertPaginated($response);
        $response->assertJsonPath('meta.total', 2)
                 ->assertJsonPath('message', 'Active courses retrieved.');
        $this->assertSame([$newer->id, $older->id], $response->json('result.*.id'));
    }

    public function test_lists_courses_with_no_cohort_and_courses_tied_to_qualifications(): void
    {
        // No availability / job-title rule: the course has no cohort and a
        // qualification, both of which the employee catalogue would hide.
        $course = Course::factory()->create();
        $course->qualificationSkills()->attach(QualificationSkill::factory()->create()->id);

        $this->withHeaders($this->tokenOnlyHeaders())->getJson(self::URL)
             ->assertOk()
             ->assertJsonPath('meta.total', 1)
             ->assertJsonPath('result.0.id', $course->id);
    }

    public function test_item_shape_carries_course_data_and_nothing_learner_specific(): void
    {
        $category   = Category::factory()->create(['name' => ['en' => 'Safety', 'ar' => 'السلامة']]);
        $course     = Course::factory()->create(['category_id' => $category->id]);
        $skill      = QualificationSkill::factory()->create(['name' => ['en' => 'First Aid', 'ar' => 'الإسعافات']]);
        $instructor = Instructor::query()->create(['name' => ['en' => 'Mona', 'ar' => 'منى'], 'email' => 'mona@academy.test']);
        $course->qualificationSkills()->attach($skill->id);
        $course->instructors()->attach($instructor->id);
        CourseRating::factory()->create(['course_id' => $course->id, 'rating' => 4]);
        CourseRating::factory()->create(['course_id' => $course->id, 'rating' => 5]);

        $response = $this->withHeaders($this->tokenOnlyHeaders())->getJson(self::URL);

        $response->assertOk()
                 ->assertJsonStructure(['result' => [[
                     'id', 'title', 'description', 'course_type', 'level', 'duration_weeks', 'image', 'hours',
                     'has_certificate', 'category' => ['id', 'name'], 'instructors' => [['id', 'name', 'image']],
                     'qualifications' => [['id', 'name']], 'rating' => ['avg', 'count'],
                 ]]])
                 ->assertJsonPath('result.0.category.name', 'Safety')
                 ->assertJsonPath('result.0.qualifications.0.name', 'First Aid')
                 ->assertJsonPath('result.0.instructors.0.name', 'Mona')
                 ->assertJsonPath('result.0.rating.avg', 4.5)
                 ->assertJsonPath('result.0.rating.count', 2)
                 ->assertJsonMissingPath('result.0.cta')
                 ->assertJsonMissingPath('result.0.next_cohort');
    }

    public function test_translatable_fields_follow_accept_language(): void
    {
        $category = Category::factory()->create(['name' => ['en' => 'Safety', 'ar' => 'السلامة']]);
        Course::factory()->create([
            'title'       => ['en' => 'Fire Safety', 'ar' => 'السلامة من الحرائق'],
            'category_id' => $category->id,
        ]);

        $this->withHeaders($this->tokenOnlyHeaders('ar'))->getJson(self::URL)
             ->assertOk()
             ->assertJsonPath('result.0.title', 'السلامة من الحرائق')
             ->assertJsonPath('result.0.category.name', 'السلامة')
             ->assertJsonPath('message', 'تم استرجاع الدورات النشطة.');

        $this->withHeaders($this->tokenOnlyHeaders('en'))->getJson(self::URL)
             ->assertOk()
             ->assertJsonPath('result.0.title', 'Fire Safety');
    }

    public function test_pages_do_not_overlap_and_meta_is_correct(): void
    {
        // Same created_at on purpose: the id tie-break keeps pages stable.
        $ids = Course::factory()->count(5)->create(['created_at' => now()])->pluck('id')->sortDesc()->values()->all();

        $page1 = $this->withHeaders($this->tokenOnlyHeaders())->getJson(self::URL . '?per_page=2&page=1');
        $page3 = $this->withHeaders($this->tokenOnlyHeaders())->getJson(self::URL . '?per_page=2&page=3');
        $page2 = $this->withHeaders($this->tokenOnlyHeaders())->getJson(self::URL . '?per_page=2&page=2');

        $page1->assertJsonPath('meta.per_page', 2)->assertJsonPath('meta.last_page', 3)->assertJsonPath('meta.total', 5);
        $this->assertSame(
            $ids,
            array_merge($page1->json('result.*.id'), $page2->json('result.*.id'), $page3->json('result.*.id')),
        );

        $this->withHeaders($this->tokenOnlyHeaders())->getJson(self::URL . '?per_page=2&page=9')
             ->assertOk()->assertJsonPath('result', []);
    }

    public function test_invalid_paging_is_rejected(): void
    {
        foreach (['per_page=0', 'per_page=51', 'per_page=abc', 'page=0', 'page=-1'] as $query) {
            $this->withHeaders($this->tokenOnlyHeaders())->getJson(self::URL . '?' . $query)
                 ->assertStatus(422);
        }
    }

    public function test_requires_the_shared_token(): void
    {
        Course::factory()->create();

        $this->withHeaders(['Accept-Language' => 'en', 'Accept' => 'application/json'])
             ->getJson(self::URL)->assertStatus(401)->assertJson(['status' => 'error']);

        $this->withHeaders(['X-Api-Token' => 'wrong', 'Accept-Language' => 'en', 'Accept' => 'application/json'])
             ->getJson(self::URL)->assertStatus(401)->assertJson(['status' => 'error']);
    }

    public function test_an_employee_code_does_not_change_the_list(): void
    {
        $withQualification = Course::factory()->create();
        $withQualification->qualificationSkills()->attach(QualificationSkill::factory()->create()->id);
        Course::factory()->create();

        $anonymous = $this->withHeaders($this->tokenOnlyHeaders())->getJson(self::URL)->json('result.*.id');
        $asEmployee = $this->withHeaders($this->headersFor($this->employee()))->getJson(self::URL)->json('result.*.id');

        $this->assertCount(2, $anonymous);
        $this->assertSame($anonymous, $asEmployee);
    }

    public function test_query_count_does_not_grow_with_the_page_size(): void
    {
        $make = function (int $n): void {
            Course::factory()->count($n)->create()->each(function (Course $course) {
                $course->qualificationSkills()->attach(QualificationSkill::factory()->create()->id);
                $course->instructors()->attach(Instructor::query()->create([
                    'name' => ['en' => 'I'.$course->id, 'ar' => 'I'.$course->id], 'email' => "i{$course->id}@academy.test",
                ])->id);
                CourseRating::factory()->create(['course_id' => $course->id]);
            });
        };

        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->withHeaders($this->tokenOnlyHeaders())->getJson(self::URL . '?per_page=50')->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        // The first request also loads the mobile settings into the cache.
        $this->withHeaders($this->tokenOnlyHeaders())->getJson(self::URL)->assertOk();

        $make(2);
        $few = $count();
        $make(8);
        $many = $count();

        $this->assertSame($few, $many);
    }
}
