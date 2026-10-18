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
    ) {}
}
