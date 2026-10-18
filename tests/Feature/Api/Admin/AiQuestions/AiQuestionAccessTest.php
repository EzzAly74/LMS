<?php

namespace Tests\Feature\Api\Admin\AiQuestions;

use App\Models\AiGeneration;
use App\Models\Course;
use App\Models\Instructor;

/**
 * Who may generate and read: the quiz / assignment section's own permissions,
 * own runs only (404 for anyone else's), course scope (D-074).
 */
class AiQuestionAccessTest extends AiQuestionTestCase
{
    public function test_unauthenticated_and_learners_are_refused(): void
    {
        $this->postJson(self::QUIZ, $this->body())->assertStatus(401);
        $this->postJson(self::QUIZ, $this->body(), $this->userToken()['headers'])->assertStatus(403);
    }

    public function test_generating_needs_create_on_that_section(): void
    {
        $this->fakeGemini([$this->geminiBody([$this->mcq()])]);
        $viewer = $this->trainer(['view-quizzes', 'edit-quizzes']);
        $quizOnly = $this->trainer(['view-quizzes', 'create-quizzes']);

        $this->postJson(self::QUIZ, $this->body(), $viewer['headers'])->assertStatus(403);
        $this->postJson(self::ASSIGNMENT, $this->body(), $quizOnly['headers'])->assertStatus(403);
        $this->getJson(self::ASSIGNMENT.'/usage', $quizOnly['headers'])->assertStatus(403);
        $this->assertDatabaseCount('ai_generations', 0);

        $this->postJson(self::QUIZ, $this->body(['counts' => ['mcq' => 1]]), $quizOnly['headers'])->assertStatus(202);
    }

    public function test_a_run_is_readable_only_by_its_author_and_through_its_own_section(): void
    {
        $this->fakeGemini([$this->geminiBody([$this->mcq()])]);
        $me = $this->trainer();
        $id = $this->postJson(self::QUIZ, $this->body(['counts' => ['mcq' => 1]]), $me['headers'])->assertStatus(202)->json('result.generation.id');

        $this->getJson(self::QUIZ."/{$id}", $me['headers'])->assertOk()->assertJsonPath('result.status', 'succeeded');
        $this->getJson(self::QUIZ."/{$id}", $this->trainer()['headers'])->assertNotFound();
        $this->getJson(self::ASSIGNMENT."/{$id}", $me['headers'])->assertNotFound();
        $this->getJson(self::QUIZ.'/999999', $me['headers'])->assertNotFound();
    }

    public function test_a_course_outside_the_trainers_scope_is_refused(): void
    {
        $instructor = Instructor::query()->create(['name' => ['en' => 'Mona', 'ar' => 'منى'], 'email' => 'mona-aiq@academy.test']);
        $mine = Course::factory()->create();
        $theirs = Course::factory()->create();
        $mine->instructors()->attach($instructor->id);
        $scoped = $this->trainer(['view-quizzes', 'create-quizzes'], 'assigned', $instructor->id);
        $this->fakeGemini([$this->geminiBody([$this->mcq()])]);

        $this->postJson(self::QUIZ, $this->body(['course_id' => $theirs->id]), $scoped['headers'])->assertNotFound();
        $this->assertDatabaseCount('ai_generations', 0);
        $this->postJson(self::QUIZ, $this->body(['course_id' => $mine->id, 'counts' => ['mcq' => 1]]), $scoped['headers'])->assertStatus(202);
        $this->assertSame($mine->id, AiGeneration::query()->sole()->course_id);
    }

    public function test_the_old_builder_is_gone(): void
    {
        $this->getJson(self::BASE.'/admin/ai-assessments', $this->trainer()['headers'])->assertNotFound();
        $this->assertFalse(\App\Support\Permissions\AdminSections::has('ai-assessments'));
    }
}
