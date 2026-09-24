<?php

namespace Tests\Feature\Api\Assignment;

use App\Models\Course;
use App\Models\CourseAssignment;
use App\Models\UserCourseAssignment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\ApiTestCase;

/**
 * Regression tests for B-10 (High).
 *
 * Every upload went to the `public` disk, which Apache serves straight off the
 * filesystem — and the /storage/{path} fallback route performs no
 * authorization at all. Learner assignment submissions were therefore readable
 * by anyone holding or guessing the URL, and the submission response handed out
 * that exact URL as `user_file_url`.
 *
 * Submissions now live on the `private` disk (outside app/public, no symlink)
 * and are reachable only through an authorized download route.
 */
class SubmissionDownloadAuthorizationTest extends ApiTestCase
{
    /** @return array{0: Course, 1: CourseAssignment, 2: UserCourseAssignment, 3: \App\Models\User} */
    private function makeSubmission(): array
    {
        Storage::fake('private');
        Storage::fake('public');

        $course     = Course::factory()->create();
        $assignment = CourseAssignment::factory()->create(['course_id' => $course->id]);

        ['model' => $owner, 'headers' => $headers] = $this->userToken();
        $course->users()->attach($owner->getKey());

        $this->post(
            self::BASE."/courses/{$course->id}/assignments/{$assignment->id}/submit",
            ['file' => UploadedFile::fake()->createWithContent('work.txt', 'my private answer')],
            $headers + ['Accept' => 'application/json'],
        )->assertOk();

        $submission = UserCourseAssignment::query()
            ->where('user_id', $owner->getKey())
            ->where('course_assignment_id', $assignment->id)
            ->firstOrFail();

        return [$course, $assignment, $submission, $owner];
    }

    private function fileUrl(Course $c, CourseAssignment $a, UserCourseAssignment $s): string
    {
        return self::BASE."/courses/{$c->id}/assignments/{$a->id}/submissions/{$s->id}/file";
    }

    public function test_the_submission_is_written_to_the_private_disk_not_the_public_one(): void
    {
        [, , $submission] = $this->makeSubmission();

        $this->assertTrue(
            Storage::disk('private')->exists($submission->user_file),
            'The submission should be on the private disk.',
        );
        $this->assertCount(
            0,
            Storage::disk('public')->allFiles(),
            'Nothing should have been written to the public disk.',
        );
    }

    public function test_another_learner_cannot_download_someone_elses_submission(): void
    {
        [$course, $assignment, $submission] = $this->makeSubmission();

        // A different learner, enrolled in the same course.
        ['model' => $other, 'headers' => $otherHeaders] = $this->userToken();
        $course->users()->attach($other->getKey());

        $this->get($this->fileUrl($course, $assignment, $submission), $otherHeaders + ['Accept' => 'application/json'])
            ->assertStatus(403);
    }

    public function test_the_author_can_download_their_own_submission(): void
    {
        [$course, $assignment, $submission, $owner] = $this->makeSubmission();

        $headers = ['Authorization' => 'Bearer '.$owner->createToken('dl')->plainTextToken];

        $response = $this->get($this->fileUrl($course, $assignment, $submission), $headers);

        $response->assertOk();
        $this->assertSame('my private answer', $response->streamedContent());
    }

    public function test_an_admin_can_download_a_submission_for_grading(): void
    {
        [$course, $assignment, $submission] = $this->makeSubmission();
        ['headers' => $headers] = $this->adminToken();

        $this->get($this->fileUrl($course, $assignment, $submission), $headers)->assertOk();
    }

    public function test_a_guest_cannot_download(): void
    {
        [$course, $assignment, $submission] = $this->makeSubmission();

        $this->get($this->fileUrl($course, $assignment, $submission), ['Accept' => 'application/json'])
            ->assertStatus(401);
    }

    public function test_the_exposed_url_is_the_authorized_route_not_a_public_path(): void
    {
        [$course, $assignment, , $owner] = $this->makeSubmission();

        $headers  = ['Authorization' => 'Bearer '.$owner->createToken('dl')->plainTextToken];
        $response = $this->getJson(
            self::BASE."/courses/{$course->id}/assignments/{$assignment->id}/my-submission",
            $headers,
        )->assertOk();

        $url = $response->json('result.user_file_url');

        $this->assertNotNull($url);
        $this->assertStringContainsString('/submissions/', $url);
        $this->assertStringNotContainsString('/storage/', $url);
    }

    public function test_a_submission_from_another_assignment_returns_404(): void
    {
        [$course, , $submission] = $this->makeSubmission();

        $otherAssignment = CourseAssignment::factory()->create(['course_id' => $course->id]);
        ['headers' => $headers] = $this->adminToken();

        $this->get(
            self::BASE."/courses/{$course->id}/assignments/{$otherAssignment->id}/submissions/{$submission->id}/file",
            $headers,
        )->assertStatus(404);
    }
}
