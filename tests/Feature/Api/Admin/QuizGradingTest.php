<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Admin;
use App\Models\Course;
use App\Models\CourseExam;
use App\Models\CourseExamQuestion;
use App\Models\Instructor;
use App\Models\User;
use App\Models\UserExam;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Api\ApiTestCase;

/**
 * NEW2B-6368 / NEW2B-6360: scoring a quiz's open answer from the Dashboard
 * was a 500 for every account (the grader was typed as a learner, as B-131
 * on assignments). The super admin and the course's instructor can score;
 * an instructor cannot score another course's quiz; a learner cannot score.
 */
class QuizGradingTest extends ApiTestCase
{
    private Course $course;
    private UserExam $attempt;
    private int $answerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->course = Course::factory()->create();
        [$this->attempt, $this->answerId] = $this->pendingAttempt($this->course);
    }

    /** @return array{UserExam, int} */
    private function pendingAttempt(Course $course): array
    {
        $quiz = CourseExam::factory()->create(['course_id' => $course->id, 'section_id' => null, 'pass_score' => 6]);
        $mcq = CourseExamQuestion::create(['course_exam_id' => $quiz->id, 'position' => 0, 'type' => 'mcq', 'score' => 5,
            'question' => 'Q', 'question_en' => 'Q', 'options_en' => ['a', 'b'], 'correct_answer_en' => 'a']);
        $open = CourseExamQuestion::create(['course_exam_id' => $quiz->id, 'position' => 1, 'type' => 'open', 'score' => 5, 'question' => 'Why', 'question_en' => 'Why']);
        $attempt = UserExam::factory()->create([
            'user_id' => User::factory(), 'course_id' => $course->id, 'exam_id' => $quiz->id,
            'total_score' => 5, 'max_score' => 10, 'status' => 'pending', 'submission_status' => 'submitted',
        ]);
        DB::table('user_exam_answers')->insert(['user_exam_id' => $attempt->id, 'question_id' => $mcq->id, 'awarded_score' => 5, 'is_correct' => true, 'created_at' => now(), 'updated_at' => now()]);
        $answerId = DB::table('user_exam_answers')->insertGetId(['user_exam_id' => $attempt->id, 'question_id' => $open->id, 'awarded_score' => null, 'created_at' => now(), 'updated_at' => now()]);

        return [$attempt, $answerId];
    }

    private function url(?UserExam $attempt = null, ?int $answerId = null): string
    {
        $attempt ??= $this->attempt;

        return self::BASE."/admin/quizzes/submissions/{$attempt->id}/answers/".($answerId ?? $this->answerId).'/grade';
    }

    /** An instructor's Dashboard account limited to their courses (D-074). */
    private function instructorFor(Course $course): array
    {
        $instructor = Instructor::query()->create(['name' => ['en' => 'Mona', 'ar' => 'منى'], 'email' => 'mona-'.uniqid().'@academy.test']);
        $course->instructors()->attach($instructor->id);
        $role = Role::findOrCreate('instructor-'.uniqid(), 'admin');
        DB::table('roles')->where('id', $role->id)->update(['course_scope' => 'assigned']);
        $role->givePermissionTo(array_map(fn ($n) => Permission::findOrCreate($n, 'admin'), ['view-quizzes', 'edit-quizzes']));
        $admin = Admin::factory()->create();
        $admin->forceFill(['instructor_id' => $instructor->id])->save();
        $admin->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return ['model' => $admin, 'headers' => ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken]];
    }

    public function test_the_super_admin_scores_an_open_answer_and_the_attempt_is_graded(): void
    {
        ['model' => $admin, 'headers' => $h] = $this->adminToken();

        $this->putJson($this->url(), ['awarded_score' => 4, 'feedback' => 'Good'], $h)->assertOk()
            ->assertJsonPath('result.answer.awarded_score', 4)
            ->assertJsonPath('result.answer.feedback', 'Good');

        $row = DB::table('user_exams')->where('id', $this->attempt->id)->first();
        $this->assertSame(9, (int) $row->total_score);
        $this->assertSame((int) $admin->id, (int) $row->reviewed_by);
        $this->assertNotNull($row->reviewed_at);

        // Changing the score later works too (Edit score).
        $this->putJson($this->url(), ['awarded_score' => 2], $h)->assertOk()->assertJsonPath('result.answer.awarded_score', 2);
        $this->assertSame(7, (int) DB::table('user_exams')->where('id', $this->attempt->id)->value('total_score'));
    }

    public function test_the_courses_instructor_scores_but_not_another_courses_quiz(): void
    {
        ['headers' => $h] = $this->instructorFor($this->course);
        $this->putJson($this->url(), ['awarded_score' => 5], $h)->assertOk();

        $other = Course::factory()->create();
        [$foreign, $foreignAnswer] = $this->pendingAttempt($other);
        $this->putJson($this->url($foreign, $foreignAnswer), ['awarded_score' => 5], $h)->assertNotFound();
        $this->assertNull(DB::table('user_exam_answers')->where('id', $foreignAnswer)->value('awarded_score'));
    }

    public function test_a_learner_cannot_score_and_a_score_is_bounded(): void
    {
        ['headers' => $learner] = $this->userToken();
        $this->putJson($this->url(), ['awarded_score' => 5], $learner)->assertStatus(403);

        ['headers' => $h] = $this->adminToken();
        $this->putJson($this->url(), ['awarded_score' => -1], $h)->assertStatus(422);
        // Over the question's points: capped at them.
        $this->putJson($this->url(), ['awarded_score' => 50], $h)->assertOk()->assertJsonPath('result.answer.awarded_score', 5);
    }
}
