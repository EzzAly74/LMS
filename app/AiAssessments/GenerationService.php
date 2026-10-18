<?php

namespace App\AiAssessments;

use App\AiAssessments\Ai\GenerationFailed;
use App\AiAssessments\Ai\GenerationRequest;
use App\AiAssessments\Ai\QuestionGenerator;
use App\Models\Admin;
use App\Models\AiAssessment;
use App\Models\AiAssessmentQuestion;
use App\Models\AiGenerationRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Runs generation for an assessment (D-113).
 *
 * Spend and concurrency:
 *  - one run per Generate / Regenerate click, capped per author per day (H4),
 *    checked and recorded under a row lock on the admin so two parallel
 *    clicks cannot both pass the last free slot;
 *  - an assessment has at most one run "generating" (row lock on the
 *    assessment); a run older than `stale_after_minutes` counts as failed.
 *
 * Regenerate all is safe: the new run's questions are written beside the old
 * ones (tagged with run_id) and the old ones are removed only when the whole
 * run has succeeded. A failed run removes its own partial questions and
 * leaves the previous set untouched.
 */
final class GenerationService
{
    public function __construct(
        private readonly QuestionGenerator $generator,
        private readonly QuestionNormalizer $normalizer,
    ) {}

    public function isConfigured(): bool
    {
        return $this->generator->isConfigured();
    }

    /** @return array{used:int, limit:int, configured:bool} */
    public function usage(Admin $admin): array
    {
        return [
            'used'       => $this->runsToday($admin),
            'limit'      => (int) config('ai-assessments.daily_run_limit'),
            'configured' => $this->isConfigured(),
        ];
    }

    /**
     * Claim the assessment and a run slot; the questions are written later by
     * run() (GenerateAiAssessmentJob).
     *
     * @throws GenerationRefused
     */
    public function start(AiAssessment $assessment, Admin $admin): AiGenerationRun
    {
        $this->assertReady($assessment);

        return DB::transaction(function () use ($assessment, $admin) {
            $locked = AiAssessment::query()->whereKey($assessment->id)->lockForUpdate()->firstOrFail();
            $this->resolveStale($locked);

            if ($locked->isGenerating()) {
                throw new GenerationRefused('busy', 409);
            }

            $run = $this->claimRun($admin, $locked, AiGenerationRun::KIND_GENERATE);

            $locked->forceFill([
                'status'                => AiAssessment::STATUS_GENERATING,
                'current_run_id'        => $run->id,
                'generation_started_at' => Carbon::now(),
                'last_error'            => null,
            ])->save();

            $assessment->setRawAttributes($locked->getAttributes(), true);

            return $run;
        });
    }

    /** Body of GenerateAiAssessmentJob. Never throws: the outcome is stored. */
    public function run(int $runId): void
    {
        $run = AiGenerationRun::query()->find($runId);
        $assessment = $run ? AiAssessment::query()->find($run->ai_assessment_id) : null;
        if ($run === null || $assessment === null || $assessment->current_run_id !== $run->id) {
            return;
        }

        $sources = $assessment->sources()->get()->all();
        $avoid = [];
        $position = 0;
        $tokensIn = 0;
        $tokensOut = 0;

        try {
            foreach ($assessment->requestedCounts() as $type => $count) {
                $result = $this->generator->generate(new GenerationRequest($assessment, $type, $count, $sources, $avoid));
                $tokensIn += $result->inputTokens;
                $tokensOut += $result->outputTokens;

                $questions = $this->normalizer->normalize($type, $result->questions, $count);
                if ($questions === []) {
                    throw new GenerationFailed(GenerationFailed::INVALID_OUTPUT, $type);
                }

                // Abort quietly if the run was superseded (stale reset) or the
                // assessment deleted while the provider was answering.
                if (! AiAssessment::query()->whereKey($assessment->id)->where('current_run_id', $run->id)->exists()) {
                    $this->finishRun($run, 'failed', $tokensIn, $tokensOut, 'superseded');
                    AiAssessmentQuestion::query()->where('run_id', $run->id)->delete();

                    return;
                }

                $now = Carbon::now();
                AiAssessmentQuestion::query()->insert(array_map(function (array $q) use ($assessment, $run, &$position, $now) {
                    return $this->row($q, $assessment->id, $run->id, ++$position, $now);
                }, $questions));

                array_push($avoid, ...array_column($questions, 'prompt'));
            }

            DB::transaction(function () use ($assessment, $run) {
                AiAssessmentQuestion::query()->where('ai_assessment_id', $assessment->id)
                    ->where(fn ($q) => $q->whereNull('run_id')->orWhere('run_id', '!=', $run->id))
                    ->delete();

                AiAssessment::query()->whereKey($assessment->id)->where('current_run_id', $run->id)->update([
                    'status'     => AiAssessment::STATUS_READY,
                    'last_error' => null,
                    'updated_at' => Carbon::now(),
                ]);
            });

            $this->finishRun($run, 'succeeded', $tokensIn, $tokensOut);
        } catch (\Throwable $e) {
            $reason = $e instanceof GenerationFailed ? $e->reason : GenerationFailed::PROVIDER_ERROR;
            if (! $e instanceof GenerationFailed) {
                Log::error('ai-assessments: generation crashed', ['run' => $run->id, 'exception' => $e::class]);
            }
            $this->fail($assessment->id, $run, $reason, $tokensIn, $tokensOut);
        }
    }

    /**
     * Replace one question with a fresh one of the same type (synchronous:
     * a single small call).
     *
     * @throws GenerationRefused
     */
    public function regenerateOne(AiAssessment $assessment, AiAssessmentQuestion $question, Admin $admin): AiAssessmentQuestion
    {
        $this->assertReady($assessment, needCounts: false);

        $run = DB::transaction(function () use ($assessment, $admin) {
            $locked = AiAssessment::query()->whereKey($assessment->id)->lockForUpdate()->firstOrFail();
            $this->resolveStale($locked);
            if ($locked->isGenerating()) {
                throw new GenerationRefused('busy', 409);
            }

            return $this->claimRun($admin, $locked, AiGenerationRun::KIND_REGENERATE);
        });

        $avoid = $assessment->questions()->whereKeyNot($question->id)->pluck('prompt')->all();
        $avoid[] = $question->prompt;

        try {
            $result = $this->generator->generate(new GenerationRequest(
                $assessment, $question->type, 1, $assessment->sources()->get()->all(), array_values($avoid),
            ));
            $fresh = $this->normalizer->normalize($question->type, $result->questions, 1)[0] ?? null;
            if ($fresh === null) {
                throw new GenerationFailed(GenerationFailed::INVALID_OUTPUT);
            }
        } catch (GenerationFailed $e) {
            $this->finishRun($run, 'failed', 0, 0, $e->reason);
            throw new GenerationRefused($e->reason, $e->reason === GenerationFailed::PROVIDER_BUSY ? 429 : 502);
        }

        $question->fill($fresh + ['status' => 'pending', 'run_id' => $run->id])->save();
        $this->finishRun($run, 'succeeded', $result->inputTokens, $result->outputTokens);

        return $question;
    }

    /** A "generating" assessment whose run is too old is released as failed. */
    public function resolveStale(AiAssessment $assessment): void
    {
        $limit = Carbon::now()->subMinutes((int) config('ai-assessments.stale_after_minutes'));
        if (! $assessment->isGenerating() || $assessment->generation_started_at?->gt($limit)) {
            return;
        }

        $run = $assessment->current_run_id ? AiGenerationRun::query()->find($assessment->current_run_id) : null;
        $this->fail($assessment->id, $run, GenerationFailed::TIMEOUT, 0, 0);
        $assessment->refresh();
    }

    /** @throws GenerationRefused */
    private function assertReady(AiAssessment $assessment, bool $needCounts = true): void
    {
        if (! $this->isConfigured()) {
            throw new GenerationRefused(GenerationFailed::NOT_CONFIGURED, 503);
        }
        if (! $assessment->sources()->exists()) {
            throw new GenerationRefused('no_sources', 422);
        }
        if ($needCounts && $assessment->requestedCounts() === []) {
            throw new GenerationRefused('no_counts', 422);
        }
    }

    /** Check the daily cap and record the run, serialised per admin. */
    private function claimRun(Admin $admin, AiAssessment $assessment, string $kind): AiGenerationRun
    {
        Admin::query()->whereKey($admin->id)->lockForUpdate()->value('id');

        if ($this->runsToday($admin) >= (int) config('ai-assessments.daily_run_limit')) {
            throw new GenerationRefused('daily_limit', 429);
        }

        return AiGenerationRun::query()->create([
            'admin_id'         => $admin->id,
            'ai_assessment_id' => $assessment->id,
            'kind'             => $kind,
            'status'           => 'running',
            'model'            => $this->generator->model(),
        ]);
    }

    private function runsToday(Admin $admin): int
    {
        return AiGenerationRun::query()
            ->where('admin_id', $admin->id)
            ->where('created_at', '>=', Carbon::today())
            ->count();
    }

    private function fail(int $assessmentId, ?AiGenerationRun $run, string $reason, int $in, int $out): void
    {
        if ($run !== null) {
            AiAssessmentQuestion::query()->where('run_id', $run->id)->where('ai_assessment_id', $assessmentId)->delete();
            $this->finishRun($run, 'failed', $in, $out, $reason);
        }

        $hasQuestions = AiAssessmentQuestion::query()->where('ai_assessment_id', $assessmentId)->exists();

        AiAssessment::query()->whereKey($assessmentId)
            ->when($run !== null, fn ($q) => $q->where('current_run_id', $run->id))
            ->update([
                'status'     => $hasQuestions ? AiAssessment::STATUS_READY : AiAssessment::STATUS_DRAFT,
                'last_error' => $reason,
                'updated_at' => Carbon::now(),
            ]);
    }

    private function finishRun(AiGenerationRun $run, string $status, int $in, int $out, ?string $error = null): void
    {
        $run->forceFill([
            'status'        => $status,
            'input_tokens'  => $in,
            'output_tokens' => $out,
            'error'         => $error,
            'finished_at'   => Carbon::now(),
        ])->save();
    }

    /** @param  array<string, mixed>  $q */
    private function row(array $q, int $assessmentId, int $runId, int $position, Carbon $now): array
    {
        return [
            'ai_assessment_id' => $assessmentId,
            'run_id'           => $runId,
            'position'         => $position,
            'type'             => $q['type'],
            'context'          => $q['context'],
            'prompt'           => $q['prompt'],
            'options'          => $q['options'] === null ? null : json_encode($q['options'], JSON_UNESCAPED_UNICODE),
            'answer'           => $q['answer'] === null ? null : json_encode($q['answer'], JSON_UNESCAPED_UNICODE),
            'explanation'      => $q['explanation'],
            'source_ref'       => $q['source_ref'],
            'bloom'            => $q['bloom'],
            'difficulty'       => $q['difficulty'],
            'status'           => 'pending',
            'created_at'       => $now,
            'updated_at'       => $now,
        ];
    }
}
