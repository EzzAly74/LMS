<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Course;
use App\Models\CourseAssignment;
use App\Models\CourseExam;
use App\Models\CourseExamQuestion;
use App\Models\User;
use App\Models\UserExam;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\ApiTestCase;

/**
 * D-065 (Q-053 answered 2026-09-28): one Pre / Mid / Post type on quizzes and
 * assignments, Post = the final exam; the attempts list filters Passed /
 * Failed (Figma 1983:42584). Plus B-133 (editing a quiz orphaned answers) and
 * B-134 (attempts with an ungraded open answer read as graded).
 */
class QuizTypeAndResultTest extends ApiTestCase
{
    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();
        $this->course = Course::factory()->create();
    }

    /** An MCQ with both languages, as the form sends it (D-066). */
    private function mcq(array $extra = []): array
    {
        return $extra + [
            'type' => 'mcq', 'score' => 20, 'question_en' => 'Pick', 'question_ar' => 'اختر',
            'options_en' => ['a', 'b'], 'options_ar' => ['أ', 'ب'], 'correct_answer_en' => 'a', 'correct_answer_ar' => 'أ',
        ];
    }

    private function payload(array $extra = [], ?array $questions = null): array
    {
        return $extra + [
            'course_id'    => $this->course->id,
            'title'        => 'Knowledge Check',
            'title_ar'     => 'اختبار المعرفة',
            'type'         => 'mid',
            'cohort_scope' => 'all',
            'status'       => 'active',
            'pass_score'   => 10,
            'questions'    => $questions ?? [$this->mcq()],
        ];
    }

    private function createQuiz(array $extra = []): CourseExam
    {
        ['headers' => $h] = $this->adminToken();
        $id = $this->postJson(self::BASE.'/admin/quizzes', $this->payload($extra), $h)->assertSuccessful()->json('result.id');

        return CourseExam::findOrFail($id);
    }

    // ------------------------------------------------------------ type

    public function test_post_is_the_final_exam_and_the_others_are_not(): void
    {
        $post = $this->createQuiz(['type' => 'post']);
        $mid  = $this->createQuiz(['type' => 'mid']);

        $this->assertSame(['post', true], [$post->type, (bool) $post->is_final]);
        $this->assertSame(['mid', false], [$mid->type, (bool) $mid->is_final]);

        ['headers' => $h] = $this->adminToken();
        $this->getJson(self::BASE."/admin/quizzes/{$post->id}", $h)->assertOk()->assertJsonPath('result.type', 'post');
    }

    public function test_the_legacy_final_flag_keeps_type_in_step(): void
    {
        $exam = CourseExam::factory()->create(['course_id' => $this->course->id, 'is_final' => true]);
        $this->assertSame('post', $exam->fresh()->type);

        $exam->update(['is_final' => false]);
        $this->assertNull($exam->fresh()->type);

        $exam->update(['type' => 'pre']);
        $exam->update(['is_final' => false]);
        $this->assertSame('pre', $exam->fresh()->type);
    }

    public function test_an_unknown_type_is_rejected(): void
    {
        ['headers' => $h] = $this->adminToken();

        $this->postJson(self::BASE.'/admin/quizzes', $this->payload(['type' => 'final']), $h)->assertStatus(422)->assertJsonValidationErrors('type');
        $this->postJson(self::BASE.'/admin/assignments', $this->payload(['type' => 'x']), $h)->assertStatus(422)->assertJsonValidationErrors('type');
    }

    // ------------------------------------------------------------ D-066

    public function test_type_and_every_arabic_field_are_required(): void
    {
        ['headers' => $h] = $this->adminToken();
        $bare = ['type' => 'mcq', 'score' => 20, 'question_en' => 'Pick', 'options_en' => ['a', 'b'], 'correct_answer_en' => 'a'];

        foreach (['quizzes', 'assignments'] as $kind) {
            $body = $this->payload([], [$bare]);
            unset($body['type'], $body['title_ar']);

            $this->postJson(self::BASE."/admin/$kind", $body, $h)->assertStatus(422)->assertJsonValidationErrors([
                'type', 'title_ar', 'questions.0.question_ar', 'questions.0.options_ar', 'questions.0.correct_answer_ar',
            ]);
        }
    }

    public function test_an_open_question_needs_no_answer_key_in_either_language(): void
    {
        ['headers' => $h] = $this->adminToken();

        $this->postJson(self::BASE.'/admin/quizzes', $this->payload([], [[
            'type' => 'open', 'score' => 5, 'question_en' => 'Why', 'question_ar' => 'لماذا',
        ]]), $h)->assertSuccessful();
    }

    public function test_an_assignment_keeps_its_type(): void
    {
        ['headers' => $h] = $this->adminToken();
        $id = $this->postJson(self::BASE.'/admin/assignments', $this->payload(['type' => 'pre']), $h)->assertSuccessful()->json('result.id');

        $this->assertSame('pre', CourseAssignment::findOrFail($id)->type);
        $this->getJson(self::BASE."/admin/assignments/{$id}", $h)->assertOk()->assertJsonPath('result.type', 'pre');
    }

    // ------------------------------------------------------------ B-133

    public function test_editing_a_quiz_keeps_its_question_ids_so_answers_still_point_at_them(): void
    {
        $quiz = $this->createQuiz();
        $question = $quiz->questions()->firstOrFail();
        $attempt = UserExam::factory()->create(['course_id' => $this->course->id, 'exam_id' => $quiz->id, 'total_score' => 20, 'max_score' => 20]);
        DB::table('user_exam_answers')->insert(['user_exam_id' => $attempt->id, 'question_id' => $question->id, 'is_correct' => true, 'created_at' => now(), 'updated_at' => now()]);

        ['headers' => $h] = $this->adminToken();
        $this->putJson(self::BASE."/admin/quizzes/{$quiz->id}", $this->payload([], [
            $this->mcq(['id' => $question->id, 'question_en' => 'Pick one']),
        ]), $h)->assertOk();

        $this->assertSame('Pick one', CourseExamQuestion::findOrFail($question->id)->question_en);
        $this->assertSame(1, DB::table('user_exam_answers')->where('question_id', $question->id)->count());
    }

    public function test_a_question_id_from_another_quiz_is_refused(): void
    {
        $quiz = $this->createQuiz();
        $other = $this->createQuiz()->questions()->firstOrFail();
        ['headers' => $h] = $this->adminToken();

        $this->putJson(self::BASE."/admin/quizzes/{$quiz->id}", $this->payload([], [
            $this->mcq(['id' => $other->id]),
        ]), $h)->assertStatus(422)->assertJsonValidationErrors('questions.0.id');
    }

    // ------------------------------------------------------------ Passed / Failed + B-134

    private function attempt(CourseExam $quiz, int $score, bool $pendingOpen = false): UserExam
    {
        $attempt = UserExam::factory()->create([
            'user_id' => User::factory(), 'course_id' => $this->course->id, 'exam_id' => $quiz->id,
            'total_score' => $score, 'max_score' => 20, 'status' => 'pending',
        ]);
        if ($pendingOpen) {
            $open = CourseExamQuestion::create(['course_exam_id' => $quiz->id, 'position' => 9, 'type' => 'open', 'score' => 5, 'question' => 'Why', 'question_en' => 'Why']);
            DB::table('user_exam_answers')->insert(['user_exam_id' => $attempt->id, 'question_id' => $open->id, 'awarded_score' => null, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $attempt;
    }

    private function ids(string $query): array
    {
        ['headers' => $h] = $this->adminToken();

        return collect($this->getJson(self::BASE.'/admin/quizzes/submissions'.$query, $h)->assertOk()->json('result'))->pluck('id')->sort()->values()->all();
    }

    public function test_attempts_filter_by_passed_and_failed_against_the_pass_score(): void
    {
        $quiz = $this->createQuiz();
        $pass = $this->attempt($quiz, 15);
        $fail = $this->attempt($quiz, 5);
        $waiting = $this->attempt($quiz, 18, pendingOpen: true);

        $this->assertSame([$pass->id], $this->ids('?result=passed'));
        $this->assertSame([$fail->id], $this->ids('?result=failed'));

        ['headers' => $h] = $this->adminToken();
        $row = collect($this->getJson(self::BASE.'/admin/quizzes/submissions', $h)->json('result'))->firstWhere('id', $waiting->id);
        $this->assertSame('pending', $row['status']);
        $this->assertNull($row['passed']);
        $this->assertSame([$waiting->id], $this->ids('?status=pending'));
    }

    public function test_attempts_filter_by_quiz_type_and_return_it(): void
    {
        $pre = $this->attempt($this->createQuiz(['type' => 'pre']), 12);
        $post = $this->attempt($this->createQuiz(['type' => 'post']), 12);

        $this->assertSame([$pre->id], $this->ids('?types[]=pre'));
        $this->assertSame(collect([$pre->id, $post->id])->sort()->values()->all(), $this->ids('?types[]=pre&types[]=post'));

        ['headers' => $h] = $this->adminToken();
        $row = collect($this->getJson(self::BASE.'/admin/quizzes/submissions', $h)->json('result'))->firstWhere('id', $post->id);
        $this->assertSame('post', $row['quiz_type']);
    }

    // ------------------------------------------------------------ assignments (same list, 1983:42584)

    public function test_assignment_attempts_filter_by_passed_failed_and_type(): void
    {
        $pre  = CourseAssignment::factory()->create(['course_id' => $this->course->id, 'type' => 'pre', 'pass_score' => 10]);
        $post = CourseAssignment::factory()->create(['course_id' => $this->course->id, 'type' => 'post', 'pass_score' => 10]);
        $row = fn (CourseAssignment $a, ?int $score) => \App\Models\UserCourseAssignment::create([
            'user_id' => User::factory()->create()->id, 'course_assignment_id' => $a->id,
            'total_score' => $score, 'max_score' => 20, 'submitted_at' => now(),
        ])->id;
        $passed  = $row($pre, 15);
        $failed  = $row($post, 5);
        $waiting = $row($post, null);

        ['headers' => $h] = $this->adminToken();
        $ids = fn (string $q) => collect($this->getJson(self::BASE.'/admin/assignments/submissions'.$q, $h)->assertOk()->json('result'))
            ->pluck('id')->sort()->values()->all();

        $this->assertSame([$passed], $ids('?result=passed'));
        $this->assertSame([$failed], $ids('?result=failed'));
        $this->assertSame([$passed], $ids('?types[]=pre'));
        $this->assertSame(collect([$failed, $waiting])->sort()->values()->all(), $ids('?types[]=post'));

        $first = collect($this->getJson(self::BASE.'/admin/assignments/submissions', $h)->json('result'))->firstWhere('id', $passed);
        $this->assertSame(['pre', true], [$first['assignment_type'], $first['passed']]);

        $this->getJson(self::BASE.'/admin/assignments/submissions?result=maybe', $h)->assertStatus(422);
        $this->getJson(self::BASE.'/admin/assignments/submissions?types[]=final', $h)->assertStatus(422);
    }

    public function test_invalid_filters_are_rejected(): void
    {
        ['headers' => $h] = $this->adminToken();

        $this->getJson(self::BASE.'/admin/quizzes/submissions?result=maybe', $h)->assertStatus(422);
        $this->getJson(self::BASE.'/admin/quizzes/submissions?types[]=final', $h)->assertStatus(422);
    }

    // ------------------------------------------------------------ B-136

    public function test_a_legacy_question_shows_its_text_options_and_key_in_the_editor(): void
    {
        $exam = CourseExam::factory()->create(['course_id' => $this->course->id]);
        $q = CourseExamQuestion::create(['course_exam_id' => $exam->id, 'position' => 0, 'type' => 'mcq', 'score' => 5,
            'question' => ['en' => 'What does Management mean?', 'ar' => 'ما معنى الإدارة؟']]);
        \App\Models\CourseExamQuestionAnswer::create(['question_id' => $q->id, 'answer' => ['en' => 'Projects', 'ar' => 'المشروعات'], 'is_correct' => true]);
        \App\Models\CourseExamQuestionAnswer::create(['question_id' => $q->id, 'answer' => ['en' => 'Research', 'ar' => 'البحوث'], 'is_correct' => false]);

        ['headers' => $h] = $this->adminToken();
        $row = $this->getJson(self::BASE."/admin/quizzes/{$exam->id}", $h)->assertOk()->json('result.questions.0');

        $this->assertSame('What does Management mean?', $row['question_en']);
        $this->assertSame('ما معنى الإدارة؟', $row['question_ar']);
        $this->assertSame(['Projects', 'Research'], $row['options_en']);
        $this->assertSame('Projects', $row['correct_answer_en']);
        $this->assertSame('المشروعات', $row['correct_answer_ar']);
    }
}
