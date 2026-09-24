<?php

namespace Tests\Feature\Api\Course;

use App\Models\Course;
use App\Models\CourseExam;
use Tests\Feature\Api\ApiTestCase;

/**
 * Regression tests for B-15 (Medium, effectively an IDOR).
 *
 * The learner course-player routes had no enrolment or visibility check —
 * LearnerCoursePlayerService contained no reference to enrolment at all — so
 * any authenticated learner could read the outline, lecture content and media
 * URLs, quizzes and assignments of any course.
 */
class CourseEnrolmentScopeTest extends ApiTestCase
{
    /** Routes that must be scoped to the learner's own enrolments. */
    private function scopedRoutes(Course $course): array
    {
        return [
            ['GET', self::BASE."/my/courses/{$course->id}/outline"],
            ['GET', self::BASE."/courses/{$course->id}/my-progress"],
            ['GET', self::BASE."/courses/{$course->id}/certificate-status"],
            ['GET', self::BASE."/courses/{$course->id}/evaluate"],
        ];
    }

    public function test_a_learner_cannot_read_a_course_they_are_not_enrolled_in(): void
    {
        $course = Course::factory()->create();
        ['headers' => $headers] = $this->userToken(); // not enrolled

        foreach ($this->scopedRoutes($course) as [$method, $uri]) {
            $this->json($method, $uri, [], $headers)
                ->assertStatus(403, "Expected 403 for $method $uri");
        }
    }

    public function test_an_enrolled_learner_is_not_blocked(): void
    {
        $course = Course::factory()->create();
        ['model' => $user, 'headers' => $headers] = $this->userToken();
        $course->users()->attach($user->getKey());

        foreach ($this->scopedRoutes($course) as [$method, $uri]) {
            $status = $this->json($method, $uri, [], $headers)->getStatusCode();

            $this->assertNotSame(403, $status, "Enrolled learner was blocked on $method $uri");
        }
    }

    public function test_enrolment_in_one_course_does_not_grant_another(): void
    {
        $mine   = Course::factory()->create();
        $theirs = Course::factory()->create();

        ['model' => $user, 'headers' => $headers] = $this->userToken();
        $mine->users()->attach($user->getKey());

        $this->getJson(self::BASE."/my/courses/{$theirs->id}/outline", $headers)
            ->assertStatus(403);

        $this->getJson(self::BASE."/my/courses/{$mine->id}/outline", $headers)
            ->assertSuccessful();
    }

    public function test_exam_submission_requires_enrolment(): void
    {
        $course = Course::factory()->create();
        $exam   = CourseExam::factory()->create(['course_id' => $course->id]);
        ['headers' => $headers] = $this->userToken(); // not enrolled

        $this->postJson(self::BASE."/courses/{$course->id}/exams/{$exam->id}/submit", [
            'questions' => [['question_id' => 1, 'answer_id' => 1]],
        ], $headers)->assertStatus(403);
    }

    public function test_these_routes_still_require_authentication(): void
    {
        $course = Course::factory()->create();

        foreach ($this->scopedRoutes($course) as [$method, $uri]) {
            $this->json($method, $uri)->assertStatus(401, "Expected 401 for $method $uri");
        }
    }
}
