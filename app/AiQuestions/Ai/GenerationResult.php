<?php

namespace App\AiQuestions\Ai;

final class GenerationResult
{
    /**
     * @param  list<array<string, mixed>>  $questions  raw provider output
     */
    public function __construct(
        public readonly array $questions,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        // The model that answered (a fallback when the configured one could not).
        public readonly ?string $model = null,
    ) {}
}
