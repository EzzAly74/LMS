<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\JobTitle;
use App\Models\QualificationSkill;
use Illuminate\Support\Facades\DB;

/**
 * Self-enrolment follows the catalogue's rules on the server (audit
 * 2026-10-13): a course tied to another job title's qualifications is not
 * offered, and a chosen cohort must be inside the visibility window.
 */
class EnrolmentGuardTest extends MobileTestCase
{
    private function enrol(\App\Models\User $user, Course $course, ?int $cohortId = null)
    {
        return $this->withHeaders($this->headersFor($user))
            ->postJson(self::BASE.'/mobile/academy/courses/'.$course->id.'/enrol', $cohortId ? ['cohort_id' => $cohortId] : []);
    }

    private function roleCourse(QualificationSkill $skill): Course
    {
        $course = Course::factory()->create();
        $course->qualificationSkills()->sync([$skill->id]);
        CourseSection::factory()->create(['course_id' => $course->id]);

        return $course;
    }

    public function test_a_course_for_another_job_title_cannot_be_enrolled_into_by_id(): void
    {
        $theirs = QualificationSkill::query()->create(['name' => ['en' => 'Forklift', 'ar' => 'رافعة']]);
        $mine = QualificationSkill::query()->create(['name' => ['en' => 'First Aid', 'ar' => 'إسعاف']]);
        $jobTitle = JobTitle::factory()->create();
        DB::table('job_title_qualification_skill')->insert(['job_title_id' => $jobTitle->id, 'qualification_skill_id' => $mine->id]);
        $user = $this->employee(['job_title_id' => $jobTitle->id]);

        $this->assertError($this->enrol($user, $this->roleCourse($theirs)), 403);
        $this->assertDatabaseMissing('users_courses', ['user_id' => $user->id]);

        // Their own role's course and a course with no qualification are fine.
        $this->assertSuccess($this->enrol($user, $this->roleCourse($mine)), 201);
        $open = Course::factory()->create();
        CourseSection::factory()->create(['course_id' => $open->id]);
        $this->assertSuccess($this->enrol($user, $open), 201);
    }

    public function test_a_chosen_cohort_outside_the_visibility_window_is_closed(): void
    {
        $user = $this->employee();
        $course = Course::factory()->create();
        // Starts in a year, not opened early: the catalogue does not show it yet.
        $far = CourseSection::factory()->create([
            'course_id' => $course->id, 'status' => 'scheduled',
            'start_date' => now()->addYear()->toDateString(), 'end_date' => now()->addYear()->addMonth()->toDateString(),
            'enrolment_closes_at' => now()->addYear()->subDay()->toDateString(),
        ]);

        $this->assertError($this->enrol($user, $course, $far->id), 409);
        $this->assertDatabaseMissing('users_courses', ['user_id' => $user->id]);

        // Opened early by an admin: joinable.
        $far->update(['status' => 'open_for_enrollment']);
        $this->assertSuccess($this->enrol($user, $course, $far->id), 201);
    }
}
