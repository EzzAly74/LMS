<?php

namespace Tests\Feature\Api\Exam;

use App\Models\Course;
use App\Models\CourseExam;
use App\Models\CourseExamQuestion;
use App\Models\CourseExamQuestionAnswer;
use Tests\Feature\Api\ApiTestCase;

/**
 * Regression tests for B-09 and B-08 (both High).
 *
 * B-09: the score was ($exam->degree / count($questions)) * $correctAnswers,
 *       where $questions came straight from the request — so answering one
 *       question you knew scored 100%. question_id was never checked against
 *       the exam and answer_id had no exists rule.
 * B-08: CourseExamQuestionResource emitted is_correct to every caller, and the
 *       read route is auth.user only, so any learner could read the answer key.
 */
class ExamIntegrityTest extends ApiTestCase
{
    /** @return array{0: Course, 1: CourseExam, 2: array<int, CourseExamQuestion>} */
    private function makeExam(int $questionCount = 4, int $degree = 100): array
    {
        $course = Course::factory()->create();
        $exam   = CourseExam::factory()->create(['course_id' => $course->id, 'degree' => $degree]);

        $questions = [];
        for ($i = 1; $i <= $questionCount; $i++) {
            $q = CourseExamQuestion::query()->create([
                'course_exam_id' => $exam->id,
                'question'       => json_encode(['en' => "Question $i", 'ar' => "سؤال $i"]),
                'position'       => $i,
            ]);

            CourseExamQuestionAnswer::query()->create([
                'question_id' => $q->id, 'answer' => 'right', 'is_correct' => true,
            ]);
            CourseExamQuestionAnswer::query()->create([
                'question_id' => $q->id, 'answer' => 'wrong', 'is_correct' => false,
            ]);

            $questions[] = $q->load('answers');
        }

        return [$course, $exam, $questions];
    }

    private function submitUrl(Course $c, CourseExam $e): string
    {
        return self::BASE."/courses/{$c->id}/exams/{$e->id}/submit";
    }

    private function correctAnswerId(CourseExamQuestion $q): int
    {
        return $q->answers->firstWhere('is_correct', true)->id;
    }

    private function wrongAnswerId(CourseExamQuestion $q): int
    {
        return $q->answers->firstWhere('is_correct', false)->id;
    }

    // ---------------------------------------------------------------------
    // B-09 — grading must be server-authoritative
    // ---------------------------------------------------------------------

    public function test_answering_one_of_four_questions_correctly_does_not_score_full_marks(): void
    {
        [$course, $exam, $questions] = $this->makeExam(4, 100);
        ['model' => $user, 'headers' => $headers] = $this->userToken();
        $course->users()->attach($user->getKey());

        // The old exploit: send a single question you know the answer to.
        $response = $this->postJson($this->submitUrl($course, $exam), [
            'questions' => [
                ['question_id' => $questions[0]->id, 'answer_id' => $this->correctAnswerId($questions[0])],
            ],
        ], $headers);

        $response->assertCreated();

        // 1 of the exam's 4 questions correct => 25, not 100.
        $this->assertEqualsWithDelta(25.0, (float) $response->json('result.user_degree'), 0.01);
        $this->assertSame('fail', $response->json('result.status'));
    }

    public function test_full_marks_require_every_exam_question_correct(): void
    {
        [$course, $exam, $questions] = $this->makeExam(4, 100);
        ['model' => $user, 'headers' => $headers] = $this->userToken();
        $course->users()->attach($user->getKey());

        $payload = array_map(fn ($q) => [
            'question_id' => $q->id,
            'answer_id'   => $this->correctAnswerId($q),
        ], $questions);

        $response = $this->postJson($this->submitUrl($course, $exam), ['questions' => $payload], $headers)
            ->assertCreated();

        $this->assertEqualsWithDelta(100.0, (float) $response->json('result.user_degree'), 0.01);
        $this->assertSame('success', $response->json('result.status'));
    }

    public function test_an_answer_belonging_to_another_exam_is_not_credited(): void
    {
        [$course, $exam, $questions]  = $this->makeExam(2, 100);
        [, , $otherQuestions]         = $this->makeExam(2, 100);
        ['model' => $user, 'headers' => $headers] = $this->userToken();
        $course->users()->attach($user->getKey());

        // Both question ids are from THIS exam, but the answer ids come from a
        // different exam's questions. They must not be credited.
        $response = $this->postJson($this->submitUrl($course, $exam), [
            'questions' => [
                ['question_id' => $questions[0]->id, 'answer_id' => $this->correctAnswerId($otherQuestions[0])],
                ['question_id' => $questions[1]->id, 'answer_id' => $this->correctAnswerId($otherQuestions[1])],
            ],
        ], $headers)->assertCreated();

        $this->assertEqualsWithDelta(0.0, (float) $response->json('result.user_degree'), 0.01);
    }

    public function test_unanswered_questions_count_against_the_score(): void
    {
        [$course, $exam, $questions] = $this->makeExam(4, 100);
        ['model' => $user, 'headers' => $headers] = $this->userToken();
        $course->users()->attach($user->getKey());

        $response = $this->postJson($this->submitUrl($course, $exam), [
            'questions' => [
                ['question_id' => $questions[0]->id, 'answer_id' => $this->correctAnswerId($questions[0])],
                ['question_id' => $questions[1]->id, 'answer_id' => $this->correctAnswerId($questions[1])],
                ['question_id' => $questions[2]->id, 'answer_id' => $this->wrongAnswerId($questions[2])],
                // question 4 omitted entirely
            ],
        ], $headers)->assertCreated();

        $this->assertEqualsWithDelta(50.0, (float) $response->json('result.user_degree'), 0.01);
    }

    public function test_client_supplied_question_title_is_not_stored(): void
    {
        [$course, $exam, $questions] = $this->makeExam(1, 100);
        ['model' => $user, 'headers' => $headers] = $this->userToken();
        $course->users()->attach($user->getKey());

        $this->postJson($this->submitUrl($course, $exam), [
            'questions' => [[
                'question_id'    => $questions[0]->id,
                'answer_id'      => $this->correctAnswerId($questions[0]),
                'question_title' => '<script>alert(1)</script> forged title',
            ]],
        ], $headers)->assertCreated();

        $this->assertDatabaseMissing('user_exam_answers', [
            'question' => '<script>alert(1)</script> forged title',
        ]);
    }

    public function test_a_nonexistent_answer_id_is_rejected(): void
    {
        [$course, $exam, $questions] = $this->makeExam(1, 100);
        ['model' => $user, 'headers' => $headers] = $this->userToken();
        $course->users()->attach($user->getKey());

        $this->postJson($this->submitUrl($course, $exam), [
            'questions' => [['question_id' => $questions[0]->id, 'answer_id' => 999999]],
        ], $headers)->assertStatus(422);
    }

    public function test_a_second_submission_is_refused(): void
    {
        [$course, $exam, $questions] = $this->makeExam(1, 100);
        ['model' => $user, 'headers' => $headers] = $this->userToken();
        $course->users()->attach($user->getKey());

        $payload = ['questions' => [[
            'question_id' => $questions[0]->id,
            'answer_id'   => $this->correctAnswerId($questions[0]),
        ]]];

        $this->postJson($this->submitUrl($course, $exam), $payload, $headers)->assertCreated();
        $this->postJson($this->submitUrl($course, $exam), $payload, $headers)->assertStatus(409);

        $this->assertSame(1, \App\Models\UserExam::query()
            ->where('user_id', $user->getKey())->where('exam_id', $exam->id)->count());
    }

    public function test_the_database_refuses_a_duplicate_submission_row(): void
    {
        // The 409 above is a check-then-insert and races. The unique index is
        // what actually guarantees one row per learner per exam.
        [$course, $exam] = $this->makeExam(1, 100);
        ['model' => $user] = $this->userToken();

        \App\Models\UserExam::query()->create([
            'user_id' => $user->getKey(), 'course_id' => $course->id, 'exam_id' => $exam->id,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        \App\Models\UserExam::query()->create([
            'user_id' => $user->getKey(), 'course_id' => $course->id, 'exam_id' => $exam->id,
        ]);
    }

    // ---------------------------------------------------------------------
    // B-08 — the answer key must not reach learners
    // ---------------------------------------------------------------------

    public function test_a_learner_cannot_see_which_answer_is_correct(): void
    {
        [$course, $exam] = $this->makeExam(2, 100);
        ['model' => $user, 'headers' => $headers] = $this->userToken();
        $course->users()->attach($user->getKey());

        $response = $this->getJson(self::BASE."/courses/{$course->id}/exams/{$exam->id}", $headers);

        $response->assertOk();
        $this->assertStringNotContainsString('is_correct', $response->getContent());
    }

    public function test_an_admin_can_still_see_the_answer_key(): void
    {
        [$course, $exam] = $this->makeExam(2, 100);
        ['headers' => $headers] = $this->adminToken();

        $response = $this->getJson(self::BASE."/courses/{$course->id}/exams/{$exam->id}", $headers);

        $response->assertOk();
        $this->assertStringContainsString('is_correct', $response->getContent());
    }
}
