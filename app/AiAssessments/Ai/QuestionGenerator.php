<?php

namespace App\AiAssessments\Ai;

/**
 * The AI provider behind the builder. Gemini is the only driver today (H1);
 * another provider is a new class bound in AppServiceProvider, nothing else.
 */
interface QuestionGenerator
{
    /** Whether credentials are present. Never exposes them. */
    public function isConfigured(): bool;

    public function model(): string;

    /**
     * Ask for `$request->count` questions of `$request->type`. The returned
     * questions are raw provider output: QuestionNormalizer validates them.
     *
     * @throws GenerationFailed
     */
    public function generate(GenerationRequest $request): GenerationResult;
}
