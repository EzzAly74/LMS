<?php

namespace Tests\Feature\Api\Admin\AiAssessments;

use App\Models\Course;
use App\Models\Instructor;
use Illuminate\Support\Facades\DB;

/**
 * Who reaches what (D-113, H2): the permission, the author-only rule (404 for
 * anyone else's ids, IDOR), super admins, and course scope (D-074).
 */
class AiAssessmentAccessTest extends AiAssessmentTestCase
{
    public function test_unauthenticated_is_refused(): void
    {
        $this->getJson(self::URL)->assertStatus(401);
    }

    public function test_a_learner_token_is_refused(): void
    {
        $this->getJson(self::URL, $this->userToken()['headers'])->assertStatus(403);
    }

    public function test_an_admin_without_the_permission_is_refused(): void
    {
        $other = $this->trainer(['view-quizzes']);
        $this->getJson(self::URL, $other['headers'])->assertStatus(403);
        $this->postJson(self::URL, ['title' => 'x'], $other['headers'])->assertStatus(403);
    }

    public function test_view_only_cannot_create_edit_generate_or_delete(): void
    {
        $viewer = $this->trainer(['view-ai-assessments']);
        $mine = $this->withText($this->assessment($viewer['model']));
        $h = $viewer['headers'];

        $this->getJson(self::URL, $h)->assertOk();
        $this->postJson(self::URL, $this->validBody(), $h)->assertStatus(403);
        $this->putJson(self::URL."/{$mine->id}", ['title' => 'New'], $h)->assertStatus(403);
        $this->postJson(self::URL."/{$mine->id}/generate", [], $h)->assertStatus(403);
        $this->postJson(self::URL."/{$mine->id}/sources/text", ['text' => str_repeat('a', 60)], $h)->assertStatus(403);
        $this->deleteJson(self::URL."/{$mine->id}", [], $h)->assertStatus(403);
        $this->assertDatabaseHas('ai_assessments', ['id' => $mine->id, 'title' => 'Workplace safety basics']);
    }

    public function test_the_library_lists_only_my_assessments(): void
    {
        $me = $this->trainer();
        $other = $this->trainer();
        $mine = $this->assessment($me['model'], ['title' => 'Mine']);
        $this->assessment($other['model'], ['title' => 'Theirs']);

        $ids = array_column($this->getJson(self::URL, $me['headers'])->assertOk()->json('result'), 'id');

        $this->assertSame([$mine->id], $ids);
    }

    public function test_every_route_answers_404_for_another_trainers_assessment(): void
    {
        $me = $this->trainer();
        $other = $this->trainer();
        $theirs = $this->withText($this->assessment($other['model']));
        $question = $this->question($theirs);
        $source = $theirs->sources()->first();
        $h = $me['headers'];
        $base = self::URL."/{$theirs->id}";

        $this->getJson($base, $h)->assertNotFound();
        $this->putJson($base, ['title' => 'Hijacked'], $h)->assertNotFound();
        $this->postJson("{$base}/duplicate", [], $h)->assertNotFound();
        $this->postJson("{$base}/sources/text", ['text' => str_repeat('x', 80)], $h)->assertNotFound();
        $this->deleteJson("{$base}/sources/{$source->id}", [], $h)->assertNotFound();
        $this->postJson("{$base}/generate", [], $h)->assertNotFound();
        $this->postJson("{$base}/questions/{$question->id}/regenerate", [], $h)->assertNotFound();
        $this->putJson("{$base}/questions/{$question->id}", ['prompt' => 'x'], $h)->assertNotFound();
        $this->patchJson("{$base}/questions/{$question->id}/status", ['status' => 'approved'], $h)->assertNotFound();
        $this->putJson("{$base}/questions/order", ['ids' => [$question->id]], $h)->assertNotFound();
        $this->deleteJson("{$base}/questions/{$question->id}", [], $h)->assertNotFound();
        $this->getJson("{$base}/export?format=xlsx&variant=trainer", $h)->assertNotFound();
        $this->deleteJson($base, [], $h)->assertNotFound();

        $this->assertDatabaseHas('ai_assessments', ['id' => $theirs->id, 'title' => 'Workplace safety basics']);
        $this->assertDatabaseHas('ai_assessment_questions', ['id' => $question->id, 'status' => 'pending']);
        $this->assertDatabaseHas('ai_assessment_sources', ['id' => $source->id]);
        $this->assertDatabaseCount('ai_generation_runs', 0);
    }

    public function test_a_question_or_source_of_another_assessment_cannot_be_reached_through_mine(): void
    {
        $me = $this->trainer();
        $other = $this->trainer();
        $mine = $this->assessment($me['model']);
        $theirs = $this->withText($this->assessment($other['model']));
        $foreignQuestion = $this->question($theirs);
        $foreignSource = $theirs->sources()->first();

        $this->putJson(self::URL."/{$mine->id}/questions/{$foreignQuestion->id}", ['prompt' => 'x'], $me['headers'])->assertNotFound();
        $this->deleteJson(self::URL."/{$mine->id}/questions/{$foreignQuestion->id}", [], $me['headers'])->assertNotFound();
        $this->deleteJson(self::URL."/{$mine->id}/sources/{$foreignSource->id}", [], $me['headers'])->assertNotFound();
        $this->assertDatabaseHas('ai_assessment_questions', ['id' => $foreignQuestion->id]);
        $this->assertDatabaseHas('ai_assessment_sources', ['id' => $foreignSource->id]);
    }

    public function test_a_super_admin_sees_every_assessment(): void
    {
        $trainer = $this->trainer();
        $theirs = $this->assessment($trainer['model']);
        $super = $this->superAdmin();

        $this->getJson(self::URL, $super['headers'])->assertOk()->assertJsonPath('result.0.id', $theirs->id)
            ->assertJsonPath('result.0.author.id', $trainer['model']->id);
        $this->getJson(self::URL."/{$theirs->id}", $super['headers'])->assertOk();
    }

    public function test_a_course_scoped_trainer_cannot_tag_or_see_another_instructors_course(): void
    {
        $instructor = Instructor::query()->create(['name' => ['en' => 'Mona', 'ar' => 'منى'], 'email' => 'mona-ai@academy.test']);
        $mine = Course::factory()->create();
        $theirs = Course::factory()->create();
        $mine->instructors()->attach($instructor->id);

        $scoped = $this->trainer(self::CRUD, 'assigned', $instructor->id);
        $h = $scoped['headers'];

        $this->postJson(self::URL, $this->validBody(['course_id' => $theirs->id]), $h)->assertNotFound();
        $this->postJson(self::URL, $this->validBody(['course_id' => $mine->id]), $h)->assertCreated();

        // Its own assessment, tagged to a course later taken away, disappears.
        $hidden = $this->assessment($scoped['model'], ['course_id' => $theirs->id]);
        $this->getJson(self::URL."/{$hidden->id}", $h)->assertNotFound();
        $ids = array_column($this->getJson(self::URL, $h)->json('result'), 'id');
        $this->assertNotContains($hidden->id, $ids);

        $courses = array_column($this->getJson(self::URL.'/courses', $h)->assertOk()->json('result'), 'id');
        $this->assertSame([$mine->id], $courses);
    }

    public function test_the_permission_is_seeded_for_super_admin_roles_only(): void
    {
        $names = DB::table('permissions')->where('name', 'like', '%-ai-assessments')->pluck('name')->sort()->values()->all();
        $this->assertSame(['create-ai-assessments', 'delete-ai-assessments', 'edit-ai-assessments', 'view-ai-assessments'], $names);
    }

    private function validBody(array $override = []): array
    {
        return $override + [
            'title' => 'Fire safety', 'type' => 'pre', 'content_language' => 'en', 'difficulty' => 'easy',
        ];
    }
}
