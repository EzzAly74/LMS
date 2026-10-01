<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * When a cohort may be joined, the same everywhere (catalogue, course
 * details, enrolment; website and mobile share these endpoints):
 * - NEW2B-6050: a full cohort is neither offered nor joinable, also when
 *   its own capacity is empty and the limit comes from the course's "Max per
 *   Cohort" or the default cohort size;
 * - NEW2B-6091: with "Enrolment closes before start" = 0, enrolment closes
 *   the day before the start date; on the start date the cohort is not offered.
 */
class AcademyCohortRulesTest extends MobileTestCase
{
    private function cohort(Course $course, array $attributes = []): CourseSection
    {
        return CourseSection::factory()->create(array_merge([
            'course_id' => $course->id, 'capacity' => null, 'enrolment_closes_at' => null,
            'status' => 'open_for_enrollment',
        ], $attributes));
    }

    private function fill(CourseSection $cohort, int $seats): void
    {
        foreach (range(1, $seats) as $_) {
            DB::table('users_courses')->insert([
                'user_id' => $this->employee()->id, 'course_id' => $cohort->course_id, 'group_id' => $cohort->id,
            ]);
        }
    }

    private function setting(string $key, string $value): void
    {
        $row = Setting::query()->where('key', $key)->orderBy('id')->first()
            ?? new Setting(['key' => $key, 'module' => 'platform', 'type' => 'number', 'label' => $key]);
        $row->forceFill(['value' => $value])->save();
        Cache::flush();
    }

    private function listedIds($user): array
    {
        return array_column($this->withHeaders($this->headersFor($user))
            ->getJson(self::BASE.'/mobile/academy/courses?per_page=50')->assertOk()->json('result'), 'id');
    }

    private function enrol($user, Course $course, ?int $cohortId = null)
    {
        return $this->withHeaders($this->headersFor($user))
            ->postJson(self::BASE.'/mobile/academy/courses/'.$course->id.'/enrol', array_filter(['cohort_id' => $cohortId]));
    }

    public function test_a_cohort_full_by_the_courses_max_per_cohort_is_not_offered_or_joinable(): void
    {
        $course = Course::factory()->create(['max_learners' => 2]);
        $cohort = $this->cohort($course);
        $this->fill($cohort, 2);
        $user = $this->employee();

        $this->assertNotContains($course->id, $this->listedIds($user));
        $this->enrol($user, $course, $cohort->id)->assertStatus(409);
        $this->assertSame(2, DB::table('users_courses')->where('group_id', $cohort->id)->count());
    }

    public function test_the_default_cohort_size_limits_a_cohort_with_no_capacity(): void
    {
        $this->setting('default_cohort_size', '1');
        $course = Course::factory()->create(['max_learners' => null]);
        $cohort = $this->cohort($course);
        $first = $this->employee();

        $this->enrol($first, $course, $cohort->id)->assertSuccessful();

        $second = $this->employee();
        $this->assertNotContains($course->id, $this->listedIds($second));
        $this->enrol($second, $course, $cohort->id)->assertStatus(409);
    }

    public function test_a_cohort_with_a_seat_left_is_still_offered(): void
    {
        $course = Course::factory()->create(['max_learners' => 2]);
        $cohort = $this->cohort($course);
        $this->fill($cohort, 1);

        $user = $this->employee();
        $this->assertContains($course->id, $this->listedIds($user));
        $this->enrol($user, $course, $cohort->id)->assertSuccessful();
    }

    public function test_with_offset_zero_a_cohort_is_not_offered_on_its_start_date(): void
    {
        $this->setting('academy_default_close_offset_days', '0');
        $course = Course::factory()->create();
        $cohort = $this->cohort($course, ['start_date' => now()->toDateString(), 'end_date' => now()->addDays(10)->toDateString()]);
        $user = $this->employee();

        $this->assertNotContains($course->id, $this->listedIds($user));
        $this->enrol($user, $course, $cohort->id)->assertStatus(409);
    }

    public function test_with_offset_zero_the_day_before_start_is_the_last_day_to_join(): void
    {
        $this->setting('academy_default_close_offset_days', '0');
        $course = Course::factory()->create();
        $cohort = $this->cohort($course, ['start_date' => now()->addDay()->toDateString()]);
        $user = $this->employee();

        $this->assertContains($course->id, $this->listedIds($user));
        $this->enrol($user, $course, $cohort->id)->assertSuccessful();
    }

    public function test_an_offset_closes_enrolment_that_many_days_earlier(): void
    {
        $this->setting('academy_default_close_offset_days', '2');
        $course = Course::factory()->create();
        $cohort = $this->cohort($course, ['start_date' => now()->addDays(2)->toDateString()]);
        $user = $this->employee();

        $this->assertNotContains($course->id, $this->listedIds($user));
        $this->enrol($user, $course, $cohort->id)->assertStatus(409);
    }
}
