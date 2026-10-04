<?php

namespace Tests\Feature\Api;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Instructor;
use App\Models\QualificationSkill;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Website course details, Instructor tab (NEW2B-5926, Figma 818:40243):
 * title, rating, learners and courses counts, and the instructor's other
 * courses the viewer can browse.
 */
class CourseDetailInstructorTest extends ApiTestCase
{
    private Instructor $sara;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MobileSettingSeeder::class);
        $this->sara = Instructor::query()->create([
            'name'  => ['en' => 'Sara Al-Mansouri', 'ar' => 'سارة المنصوري'],
            'title' => ['en' => 'Senior L&D Specialist', 'ar' => 'أخصائية أولى للتعلم والتطوير'],
            'bio'   => ['en' => 'Twelve years of leadership programmes.', 'ar' => 'اثنا عشر عامًا.'],
            'email' => 'sara@academy.test',
        ]);
    }

    /** An active, joinable course taught by $instructor. */
    private function course(?Instructor $instructor = null, array $attributes = []): Course
    {
        $course = Course::factory()->create(['active' => true, ...$attributes]);
        CourseSection::factory()->create(['course_id' => $course->id]);
        $course->instructors()->attach(($instructor ?? $this->sara)->id);

        return $course;
    }

    private function enrol(User $user, Course $course): void
    {
        DB::table('users_courses')->insert(['user_id' => $user->id, 'course_id' => $course->id]);
    }

    private function show(Course $course, array $headers = []): \Illuminate\Testing\TestResponse
    {
        return $this->getJson(self::BASE.'/learner/academy/courses/'.$course->id, $headers)->assertOk();
    }

    public function test_the_instructor_carries_title_rating_learners_courses_and_other_courses(): void
    {
        $main = $this->course();
        $second = $this->course();
        $third = $this->course();
        // Inactive with no open cohort: not counted, not in the catalogue, so not listed.
        Course::factory()->create(['active' => false])->instructors()->attach($this->sara->id);

        [$a, $b, $c] = User::factory()->count(3)->create();
        $this->enrol($a, $main);
        $this->enrol($a, $second); // one learner on two courses counts once
        $this->enrol($b, $third);
        DB::table('course_ratings')->insert([
            ['user_id' => $a->id, 'course_id' => $main->id, 'rating' => 5, 'comment' => ''],
            ['user_id' => $b->id, 'course_id' => $third->id, 'rating' => 4, 'comment' => ''],
        ]);

        $r = $this->show($main, ['Accept-Language' => 'en']);
        $r->assertJsonPath('result.instructors.0.title', 'Senior L&D Specialist')
            ->assertJsonPath('result.instructors.0.rating_avg', 4.5)
            ->assertJsonPath('result.instructors.0.rating_count', 2)
            ->assertJsonPath('result.instructors.0.learners_count', 2)
            ->assertJsonPath('result.instructors.0.courses_count', 3)
            ->assertJsonCount(2, 'result.instructors.0.other_courses');

        $ids = array_column($r->json('result.instructors.0.other_courses'), 'id');
        $this->assertEqualsCanonicalizing([$second->id, $third->id], $ids);
        $this->assertNotContains($main->id, $ids);
        $this->assertSame(['id', 'title', 'image', 'course_type', 'level', 'duration_weeks'],
            array_keys($r->json('result.instructors.0.other_courses.0')));

        $this->show($main, ['Accept-Language' => 'ar'])
            ->assertJsonPath('result.instructors.0.title', 'أخصائية أولى للتعلم والتطوير');
    }

    public function test_other_courses_follow_the_catalogue_rule_for_the_viewer(): void
    {
        $main = $this->course();
        $public = $this->course();
        $roleSpecific = $this->course();
        $roleSpecific->qualificationSkills()->attach(QualificationSkill::factory()->create()->id);

        // A guest never sees a course tied to a qualification.
        $ids = array_column($this->show($main)->json('result.instructors.0.other_courses'), 'id');
        $this->assertSame([$public->id], $ids);

        // Nor does a learner whose job title does not require it.
        $learner = $this->userToken();
        $ids = array_column($this->show($main, $learner['headers'])->json('result.instructors.0.other_courses'), 'id');
        $this->assertSame([$public->id], $ids);
    }

    public function test_at_most_three_other_courses_and_none_from_another_instructor(): void
    {
        $main = $this->course();
        foreach (range(1, 5) as $i) {
            $this->course();
        }
        $omar = Instructor::query()->create(['name' => ['en' => 'Omar'], 'email' => 'omar@academy.test']);
        $notHers = $this->course($omar);

        $other = $this->show($main)->json('result.instructors.0.other_courses');
        $this->assertCount(3, $other);
        $this->assertNotContains($notHers->id, array_column($other, 'id'));
    }

    public function test_an_instructor_without_a_title_or_courses_shows_nulls_and_zeros(): void
    {
        $plain = Instructor::query()->create(['name' => ['en' => 'New'], 'email' => 'new@academy.test']);
        $course = Course::factory()->create(['active' => false]);
        CourseSection::factory()->create(['course_id' => $course->id]);
        $course->instructors()->attach($plain->id);

        $this->show($course)
            ->assertJsonPath('result.instructors.0.title', null)
            ->assertJsonPath('result.instructors.0.rating_avg', null)
            ->assertJsonPath('result.instructors.0.learners_count', 0)
            ->assertJsonPath('result.instructors.0.courses_count', 0)
            ->assertJsonPath('result.instructors.0.other_courses', []);
    }

    public function test_the_tab_costs_the_same_queries_however_many_courses_the_instructor_has(): void
    {
        $main = $this->course();
        $count = function () use ($main): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->show($main);
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $this->course();
        $count(); // warm-up
        $few = $count();
        foreach (range(1, 6) as $i) {
            $c = $this->course();
            $this->enrol(User::factory()->create(), $c);
        }
        $this->assertSame($few, $count());
    }

    public function test_the_title_is_set_from_users_edit(): void
    {
        $admin = $this->adminToken();
        $role = \Spatie\Permission\Models\Role::findOrCreate('instructor', 'admin');
        $account = \App\Models\Admin::factory()->create(['email' => 'sara@academy.test']);
        $account->assignRole($role);

        $this->putJson(self::BASE."/admin/users/admin/{$account->id}", [
            'title_en' => '  Senior L&D Specialist ', 'title_ar' => 'أخصائية',
        ], $admin['headers'])->assertOk()
            ->assertJsonPath('result.title_en', 'Senior L&D Specialist')
            ->assertJsonPath('result.title_ar', 'أخصائية');

        $this->putJson(self::BASE."/admin/users/admin/{$account->id}", ['title_en' => str_repeat('x', 151)], $admin['headers'])
            ->assertUnprocessable()->assertJsonValidationErrors(['title_en']);
    }
}
