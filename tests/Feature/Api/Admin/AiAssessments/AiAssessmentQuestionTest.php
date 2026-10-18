<?php

namespace Tests\Feature\Api\Admin\AiAssessments;

use App\Models\AiAssessment;
use Illuminate\Support\Facades\DB;

/** Review & edit: per-type validation, status, delete, reorder. */
class AiAssessmentQuestionTest extends AiAssessmentTestCase
{
    public function test_a_question_is_edited_in_its_type_shape(): void
    {
        $t = $this->trainer();
        $a = $this->assessment($t['model']);
        $q = $this->question($a);

        $this->putJson(self::URL."/{$a->id}/questions/{$q->id}", [
            'prompt' => '  Which door is a fire exit?  ', 'options' => ['Green door', 'Red door', 'Blue door'],
            'answer' => ['indexes' => [2]], 'explanation' => 'Signs mark it.', 'status' => 'approved',
            'type' => 'short', 'ai_assessment_id' => 999,
        ], $t['headers'])
            ->assertOk()
            ->assertJsonPath('result.prompt', 'Which door is a fire exit?')
            ->assertJsonPath('result.answer.indexes.0', 2)
            ->assertJsonPath('result.type', 'mcq')
            ->assertJsonPath('result.status', 'approved');

        $this->assertSame($a->id, $q->refresh()->ai_assessment_id);
    }

    public function test_answer_keys_that_do_not_fit_the_question_are_refused(): void
    {
        $t = $this->trainer();
        $a = $this->assessment($t['model']);
        $mcq = $this->question($a);
        $blank = $this->question($a, ['type' => 'fill_blank', 'prompt' => 'Exits must stay _____.', 'options' => null, 'answer' => ['answers' => ['clear']]]);
        $tf = $this->question($a, ['type' => 'true_false', 'options' => null, 'answer' => ['value' => true]]);
        $match = $this->question($a, ['type' => 'matching', 'options' => [['left' => 'a', 'right' => '1'], ['left' => 'b', 'right' => '2'], ['left' => 'c', 'right' => '3']], 'answer' => null]);
        $u = fn ($q) => self::URL."/{$a->id}/questions/{$q->id}";
        $h = $t['headers'];

        // MCQ: key out of range, two keys, duplicate options, too few options.
        $this->putJson($u($mcq), ['prompt' => 'P', 'options' => ['A', 'B'], 'answer' => ['indexes' => [5]]], $h)->assertStatus(422)->assertJsonValidationErrors('answer');
        $this->putJson($u($mcq), ['prompt' => 'P', 'options' => ['A', 'B'], 'answer' => ['indexes' => [0, 1]]], $h)->assertStatus(422);
        $this->putJson($u($mcq), ['prompt' => 'P', 'options' => ['A', 'a'], 'answer' => ['indexes' => [0]]], $h)->assertStatus(422);
        $this->putJson($u($mcq), ['prompt' => 'P', 'options' => ['A'], 'answer' => ['indexes' => [0]]], $h)->assertStatus(422);
        // Fill in the blank: one answer per blank.
        $this->putJson($u($blank), ['prompt' => 'Two _____ and _____', 'answer' => ['answers' => ['one']]], $h)->assertStatus(422);
        $this->putJson($u($blank), ['prompt' => 'No blank here', 'answer' => ['answers' => ['x']]], $h)->assertStatus(422);
        $this->putJson($u($blank), ['prompt' => 'Two ___ and [blank]', 'answer' => ['answers' => ['one', 'two']]], $h)->assertOk()
            ->assertJsonPath('result.prompt', 'Two _____ and _____');
        // True / false needs a boolean and no options; matching needs 3+ pairs and no key.
        $this->putJson($u($tf), ['prompt' => 'P', 'answer' => ['value' => 'maybe']], $h)->assertStatus(422);
        $this->putJson($u($tf), ['prompt' => 'P', 'answer' => ['value' => false], 'options' => ['x']], $h)->assertStatus(422);
        $this->putJson($u($match), ['prompt' => 'Match', 'options' => [['left' => 'a', 'right' => '1']]], $h)->assertStatus(422);
        $this->putJson($u($match), ['prompt' => 'Match', 'options' => [['left' => 'a', 'right' => '1', 'x' => 1], ['left' => 'b', 'right' => '2'], ['left' => 'c', 'right' => '3']]], $h)->assertStatus(422);
        // Scenario-only field on another type.
        $this->putJson($u($mcq), ['prompt' => 'P', 'context' => 'Story', 'options' => ['A', 'B'], 'answer' => ['indexes' => [0]]], $h)->assertStatus(422);

        $this->assertSame('What must stay clear at all times? 1', $mcq->refresh()->prompt);
    }

    public function test_status_delete_and_reorder(): void
    {
        $t = $this->trainer();
        $a = $this->assessment($t['model'], ['status' => 'ready']);
        [$q1, $q2, $q3] = [$this->question($a), $this->question($a), $this->question($a)];
        $h = $t['headers'];

        $this->patchJson(self::URL."/{$a->id}/questions/{$q2->id}/status", ['status' => 'flagged'], $h)->assertOk()->assertJsonPath('result.status', 'flagged');
        $this->patchJson(self::URL."/{$a->id}/questions/{$q2->id}/status", ['status' => 'deleted'], $h)->assertStatus(422);

        $this->putJson(self::URL."/{$a->id}/questions/order", ['ids' => [$q3->id, $q1->id, $q2->id]], $h)->assertOk();
        $this->assertSame([$q3->id, $q1->id, $q2->id], $a->questions()->pluck('id')->all());
        $this->assertSame([1, 2, 3], $a->questions()->pluck('position')->all());

        // The order must name exactly this assessment's questions.
        $this->putJson(self::URL."/{$a->id}/questions/order", ['ids' => [$q1->id, $q2->id]], $h)->assertStatus(422);
        $this->putJson(self::URL."/{$a->id}/questions/order", ['ids' => [$q1->id, $q1->id, $q2->id]], $h)->assertStatus(422);
        $other = $this->question($this->assessment($this->trainer()['model']));
        $this->putJson(self::URL."/{$a->id}/questions/order", ['ids' => [$q1->id, $q2->id, $other->id]], $h)->assertStatus(422);
        $this->assertSame(1, $other->refresh()->position);

        $this->deleteJson(self::URL."/{$a->id}/questions/{$q3->id}", [], $h)->assertOk();
        $this->assertSame([[$q1->id, 1], [$q2->id, 2]], $a->questions()->get()->map(fn ($q) => [$q->id, $q->position])->all());
        $this->deleteJson(self::URL."/{$a->id}/questions/{$q1->id}", [], $h)->assertOk();
        $this->deleteJson(self::URL."/{$a->id}/questions/{$q2->id}", [], $h)->assertOk();
        $this->assertSame(AiAssessment::STATUS_DRAFT, $a->refresh()->status);
    }

    public function test_reorder_is_one_update_whatever_the_size(): void
    {
        $t = $this->trainer();
        $a = $this->assessment($t['model']);
        $ids = [];
        for ($i = 0; $i < 30; $i++) {
            $ids[] = $this->question($a)->id;
        }
        $this->putJson(self::URL."/{$a->id}/questions/order", ['ids' => array_reverse($ids)], $t['headers'])->assertOk(); // warm-up

        DB::enableQueryLog();
        $this->putJson(self::URL."/{$a->id}/questions/order", ['ids' => $ids], $t['headers'])->assertOk();
        $updates = array_filter(DB::getQueryLog(), fn ($q) => str_starts_with($q['query'], 'UPDATE ai_assessment_questions'));
        DB::disableQueryLog();

        $this->assertCount(1, $updates);
        $this->assertSame($ids, $a->questions()->pluck('id')->all());
    }

    public function test_edits_wait_for_a_running_generation(): void
    {
        $t = $this->trainer();
        $a = $this->assessment($t['model']);
        $q = $this->question($a);
        $a->update(['status' => 'generating', 'generation_started_at' => now()]);

        $this->putJson(self::URL."/{$a->id}/questions/{$q->id}", ['prompt' => 'P', 'options' => ['A', 'B'], 'answer' => ['indexes' => [0]]], $t['headers'])->assertStatus(409);
        $this->deleteJson(self::URL."/{$a->id}/questions/{$q->id}", [], $t['headers'])->assertStatus(409);
        $this->putJson(self::URL."/{$a->id}/questions/order", ['ids' => [$q->id]], $t['headers'])->assertStatus(409);
        $this->getJson(self::URL."/{$a->id}/export?format=pdf&variant=trainer", $t['headers'])->assertStatus(422);
    }
}
