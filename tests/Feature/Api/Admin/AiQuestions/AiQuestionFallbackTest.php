<?php

namespace Tests\Feature\Api\Admin\AiQuestions;

use App\Models\AiGeneration;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * When the configured model cannot answer now (quota used up, high demand,
 * withdrawn), the same request goes at once to the next model; a problem that
 * another model would not fix (bad key, refused content) does not move on.
 */
class AiQuestionFallbackTest extends AiQuestionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['ai-questions.gemini.fallback_models' => 'gemini-lite, gemini-test, not a model!']);
    }

    /** @param  array<string, mixed>  $byModel  model => body or status */
    private function fakeModels(array $byModel): void
    {
        Http::fake(function (HttpRequest $request) use ($byModel) {
            foreach ($byModel as $model => $reply) {
                if (str_contains($request->url(), "/models/{$model}:generateContent")) {
                    return is_int($reply) ? Http::response(['error' => ['status' => 'X']], $reply) : Http::response($reply);
                }
            }

            return Http::response(['error' => ['status' => 'NOT_FOUND']], 404);
        });
    }

    /** @return list<string> the models asked, in order */
    private function modelsAsked(): array
    {
        return array_map(
            fn (HttpRequest $r) => preg_replace('#^.*/models/([^:]+):.*$#', '$1', $r->url()),
            $this->generateCalls(),
        );
    }

    public function test_a_busy_or_exhausted_model_hands_over_at_once(): void
    {
        foreach ([503, 429, 404] as $status) {
            Http::clearResolvedInstances();
            $t = $this->trainer();
            $this->fakeModels(['gemini-test' => $status, 'gemini-lite' => $this->geminiBody([$this->mcq()])]);

            $id = $this->postJson(self::QUIZ, $this->body(['counts' => ['mcq' => 1]]), $t['headers'])->json('result.generation.id');
            $this->getJson(self::QUIZ."/{$id}", $t['headers'])->assertJsonPath('result.status', 'succeeded');

            $this->assertSame(['gemini-test', 'gemini-lite'], array_slice($this->modelsAsked(), -2), "HTTP {$status}: no retries on the first model");
            $this->assertSame('gemini-lite', AiGeneration::query()->find($id)->model, 'the run records the model that answered');
        }
        Sleep::assertNeverSlept();
    }

    public function test_a_key_or_content_problem_does_not_move_on(): void
    {
        $t = $this->trainer();
        $this->fakeModels(['gemini-test' => 401, 'gemini-lite' => $this->geminiBody([$this->mcq()])]);

        $id = $this->postJson(self::QUIZ, $this->body(['counts' => ['mcq' => 1]]), $t['headers'])->json('result.generation.id');

        $this->getJson(self::QUIZ."/{$id}", $t['headers'])->assertJsonPath('result.error', 'provider_auth');
        $this->assertSame(['gemini-test'], $this->modelsAsked());
    }

    public function test_when_every_model_is_busy_the_reason_is_explained(): void
    {
        $t = $this->trainer();
        $this->fakeModels(['gemini-test' => 503, 'gemini-lite' => 503]);

        $id = $this->postJson(self::QUIZ, $this->body(['counts' => ['mcq' => 1]]), $t['headers'])->json('result.generation.id');

        $this->getJson(self::QUIZ."/{$id}", $t['headers'])
            ->assertJsonPath('result.status', 'failed')
            ->assertJsonPath('result.error', 'provider_busy');
        $this->assertSame('gemini-test', $this->modelsAsked()[0]);
        $this->assertNotContains('not a model!', $this->modelsAsked(), 'invalid model names in config are ignored');
    }
}
