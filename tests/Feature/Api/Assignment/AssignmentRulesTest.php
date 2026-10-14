<?php

namespace Tests\Feature\Api\Assignment;

use App\Models\Course;
use App\Models\CourseAssignment;
use App\Models\CourseAssignmentQuestion;
use App\Models\Instructor;
use App\Models\User;
use App\Models\UserCourseAssignment;
use App\Notifications\PendingGradeNotification;
use App\Services\Admin\AdminAssignmentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Api\ApiTestCase;

/**
 * Assignment rules from the 2026-10 Jira round (D-090):
 * - NEW2B-6276: an empty pass score is half the total.
 * - NEW2B-6256: a new due date cannot be in the past.
 * - NEW2B-6251, NEW2B-6268: plain messages for a long title, a negative score.
 * - NEW2B-6258: past the due date an unsent assignment cannot be answered.
 * - NEW2B-6277: reaching the pass score is a pass, shown green and counted
 *   in the outline; below it is a fail with a cross.
 * - NEW2B-6250: only the course's instructors hear of a submission to review.
 */
class AssignmentRulesTest extends ApiTestCase
{
    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();
        $this->course = Course::factory()->create();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'course_id' => $this->course->id, 'title' => 'Case study', 'title_ar' => 'دراسة حالة', 'type' => 'post',
            'cohort_scope' => 'all', 'status' => 'active',
            'questions' => [
                ['type' => 'open', 'score' => 3, 'question_en' => 'Why', 'question_ar' => 'لماذا'],
                ['type' => 'open', 'score' => 2, 'question_en' => 'How', 'question_ar' => 'كيف'],
            ],
        ], $overrides);
    }

    private function learner(): array
    {
        $user = User::factory()->create();
        DB::table('users_courses')->insert(['user_id' => $user->id, 'course_id' => $this->course->id, 'created_at' => now(), 'updated_at' => now()]);

        return ['user' => $user] + $this->userToken($user);
    }

    /** One open question worth 20, pass score 16. */
    private function openAssignment(array $attributes = []): array
    {
        $assignment = CourseAssignment::create(array_merge([
            'course_id' => $this->course->id, 'title' => 'Essay', 'title_ar' => 'مقال',
            'pass_score' => 16, 'total_score' => 20, 'status' => 'active', 'cohort_scope' => 'all',
        ], $attributes));
        $question = CourseAssignmentQuestion::create([
            'course_assignment_id' => $assignment->id, 'position' => 0, 'type' => 'open', 'score' => 20, 'question_en' => 'Explain.',
        ]);

        return [$assignment, $question];
    }

    private function url(CourseAssignment $assignment, string $tail): string
    {
        return self::BASE."/courses/{$this->course->id}/assignments/{$assignment->id}/$tail";
    }

    public function test_an_empty_pass_score_is_half_the_total(): void
    {
        ['headers' => $h] = $this->adminToken();

        $id = $this->postJson(self::BASE.'/admin/assignments', $this->payload(), $h)->assertSuccessful()->json('result.id');
        $this->assertSame(3, (int) CourseAssignment::find($id)->pass_score); // half of 5, rounded up

        $id = $this->postJson(self::BASE.'/admin/assignments', $this->payload(['pass_score' => 4]), $h)->assertSuccessful()->json('result.id');
        $this->assertSame(4, (int) CourseAssignment::find($id)->pass_score);
    }

    public function test_the_pass_score_and_question_scores_are_checked_with_plain_messages(): void
    {
        ['headers' => $h] = $this->adminToken();

        $this->postJson(self::BASE.'/admin/assignments', $this->payload(['pass_score' => 6]), $h)
            ->assertStatus(422)->assertJsonValidationErrors(['pass_score' => __('messages.assignment_pass_score_over_total', ['total' => 5])]);

        $bad = $this->payload();
        $bad['questions'][0]['score'] = -1;
        $this->postJson(self::BASE.'/admin/assignments', $bad, $h)
            ->assertStatus(422)->assertJsonValidationErrors(['questions.0.score' => __('messages.assignment_score_negative')]);

        $this->postJson(self::BASE.'/admin/assignments', $this->payload(['title' => str_repeat('a', 256)]), $h)
            ->assertStatus(422)->assertJsonValidationErrors(['title' => __('messages.assignment_title_too_long', ['max' => 255])]);
    }

    /** NEW2B-6288 / 6289: options are pairs and the key is the same option in both languages. */
    public function test_mcq_options_are_paired_and_the_key_is_one_option_in_both_languages(): void
    {
        ['headers' => $h] = $this->adminToken();
        $mcq = fn (array $o = []) => ['questions' => [$o + ['type' => 'mcq', 'score' => 5, 'question_en' => 'Pick', 'question_ar' => 'اختر',
            'options_en' => ['a', 'b'], 'options_ar' => ['أ', 'ب'], 'correct_answer_en' => 'a', 'correct_answer_ar' => 'أ']]];
        $url = self::BASE.'/admin/assignments';

        $this->postJson($url, $this->payload($mcq(['options_ar' => ['أ', 'ب', 'ج']])), $h)
            ->assertStatus(422)->assertJsonValidationErrors('questions.0.options_ar');
        $this->postJson($url, $this->payload($mcq(['correct_answer_en' => 'free text'])), $h)
            ->assertStatus(422)->assertJsonValidationErrors('questions.0.correct_answer_en');
        $this->postJson($url, $this->payload($mcq(['correct_answer_ar' => 'ب'])), $h)
            ->assertStatus(422)->assertJsonValidationErrors('questions.0.correct_answer_ar');

        $this->postJson($url, $this->payload($mcq()), $h)->assertSuccessful();
    }

    public function test_a_new_due_date_cannot_be_in_the_past_but_an_edit_may_keep_its_date(): void
    {
        ['headers' => $h] = $this->adminToken();

        $this->postJson(self::BASE.'/admin/assignments', $this->payload(['due_date' => now()->subDay()->toDateString()]), $h)
            ->assertStatus(422)->assertJsonValidationErrors(['due_date' => __('messages.assignment_due_date_past')]);
        $id = $this->postJson(self::BASE.'/admin/assignments', $this->payload(['due_date' => now()->toDateString()]), $h)
            ->assertSuccessful()->json('result.id');

        // The date went by; editing the title keeps it.
        $past = now()->subDays(3)->toDateString();
        CourseAssignment::whereKey($id)->update(['due_date' => $past]);
        $this->putJson(self::BASE."/admin/assignments/$id", $this->payload(['due_date' => $past, 'title' => 'Renamed']), $h)->assertSuccessful();
        // Moving it to another past day is refused.
        $this->putJson(self::BASE."/admin/assignments/$id", $this->payload(['due_date' => now()->subDays(2)->toDateString()]), $h)
            ->assertStatus(422)->assertJsonValidationErrors('due_date');
    }

    public function test_past_its_due_date_an_unsent_assignment_cannot_be_answered(): void
    {
        [$assignment, $question] = $this->openAssignment(['due_date' => now()->subDay()->toDateString()]);
        ['user' => $user, 'headers' => $h] = $this->learner();

        $this->getJson($this->url($assignment, 'take'), $h)->assertOk()
            ->assertJsonPath('result.assignment.closed', true)
            ->assertJsonPath('result.submission_status', 'closed')
            ->assertJsonCount(0, 'result.questions');
        $this->assertFalse(UserCourseAssignment::where('user_id', $user->id)->exists());

        $this->postJson($this->url($assignment, "questions/{$question->id}/answer"), ['value' => 'late'], $h)
            ->assertStatus(409)->assertJsonPath('message', __('messages.assignment_closed'));
        $this->postJson($this->url($assignment, 'finish'), [], $h)->assertStatus(409);

        // Due today: still open.
        $assignment->update(['due_date' => now()->toDateString()]);
        $this->getJson($this->url($assignment, 'take'), $h)->assertOk()->assertJsonPath('result.assignment.closed', false);
    }

    public function test_reaching_the_pass_score_is_a_pass_and_completes_the_outline_item(): void
    {
        [$assignment, $question] = $this->openAssignment();
        ['user' => $user, 'headers' => $h] = $this->learner();
        $this->postJson($this->url($assignment, "questions/{$question->id}/answer"), ['value' => 'My essay'], $h)->assertOk();

        $item = fn () => collect($this->getJson(self::BASE."/my/courses/{$this->course->id}/outline", $h)->assertOk()->json('result.weeks'))
            ->flatMap(fn ($w) => $w['items'])->firstWhere('kind', 'assignment');
        // Sent, waiting for the instructor: not completed, no verdict yet.
        $this->assertSame('pending', $item()['result']);
        $this->assertFalse($item()['completed']);
        $this->assertNull($this->getJson($this->url($assignment, 'results'), $h)->json('result.passed'));

        $answer = UserCourseAssignment::where('user_id', $user->id)->firstOrFail()->answers()->firstOrFail();
        app(AdminAssignmentService::class)->gradeAnswer($answer, 16, null, User::factory()->create());

        $results = $this->getJson($this->url($assignment, 'results'), $h)->assertOk();
        $this->assertTrue($results->json('result.passed'));
        $this->assertSame('correct', $results->json('result.answers.0.state'));
        $this->assertSame('passed', $item()['result']);
        $this->assertTrue($item()['completed']);
    }

    public function test_below_the_pass_score_is_a_fail_with_a_cross(): void
    {
        [$assignment, $question] = $this->openAssignment(['pass_score' => null]); // half of 20
        ['user' => $user, 'headers' => $h] = $this->learner();
        $this->postJson($this->url($assignment, "questions/{$question->id}/answer"), ['value' => 'Short'], $h)->assertOk();
        $answer = UserCourseAssignment::where('user_id', $user->id)->firstOrFail()->answers()->firstOrFail();
        app(AdminAssignmentService::class)->gradeAnswer($answer, 9, null, User::factory()->create());

        $results = $this->getJson($this->url($assignment, 'results'), $h)->assertOk();
        $this->assertFalse($results->json('result.passed'));
        $this->assertSame(10, $results->json('result.pass_score'));
        $this->assertSame('incorrect', $results->json('result.answers.0.state'));

        $item = collect($this->getJson(self::BASE."/my/courses/{$this->course->id}/outline", $h)->json('result.weeks'))
            ->flatMap(fn ($w) => $w['items'])->firstWhere('kind', 'assignment');
        $this->assertSame('failed', $item['result']);
        $this->assertFalse($item['completed']);
        $this->assertFalse($item['active']);
    }

    public function test_only_the_course_instructors_are_asked_to_review_a_submission(): void
    {
        Notification::fake();
        $make = fn (string $email) => Instructor::create(['name' => 'Inst', 'email' => $email, 'image' => '', 'bio' => '', 'job_title' => 'Trainer']);
        $teaching = $make('teaching@example.test');
        $other = $make('other@example.test');
        DB::table('courses_instructors')->insert(['course_id' => $this->course->id, 'instructor_id' => $teaching->id]);

        [$assignment, $question] = $this->openAssignment();
        ['headers' => $h] = $this->learner();
        $this->postJson($this->url($assignment, "questions/{$question->id}/answer"), ['value' => 'Done'], $h)->assertOk();

        Notification::assertSentTo($teaching, PendingGradeNotification::class);
        Notification::assertNotSentTo($other, PendingGradeNotification::class);
    }
}
