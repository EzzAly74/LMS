<?php

namespace Tests\Feature\Learner;

use App\Models\Course;
use App\Models\CourseLecture;
use Tests\Feature\Api\ApiTestCase;

/** B-165: GET my/progress/{course} is the learner's own enrolled course only. */
class MyProgressEnrolmentTest extends ApiTestCase
{
    public function test_an_enrolled_learner_reads_their_progress(): void
    {
        ['model' => $learner, 'headers' => $headers] = $this->userToken();
        $course = Course::factory()->create();
        CourseLecture::factory()->create(['course_id' => $course->id]);
        $course->users()->attach($learner->getKey());

        $this->withHeaders($headers)->getJson(self::BASE."/my/progress/{$course->id}")->assertOk();
    }

    public function test_another_course_is_refused(): void
    {
        ['headers' => $headers] = $this->userToken();
        $course = Course::factory()->create();
        CourseLecture::factory()->create(['course_id' => $course->id]);

        $this->withHeaders($headers)->getJson(self::BASE."/my/progress/{$course->id}")->assertForbidden();
    }
}
