<?php

namespace App\AiQuestions\Ai;

use App\AiQuestions\Sources\SourceInput;

/**
 * One call to the provider: every requested type at once, so the source
 * material is sent (and paid for) once per generation.
 */
final class GenerationRequest
{
    /**
     * @param  'quiz'|'assignment'  $target
     * @param  array<string, int>  $counts  system question type => how many
     * @param  list<string>  $bloomLevels
     * @param  list<SourceInput>  $sources
     */
    public function __construct(
        public readonly string $target,
        public readonly ?string $assessmentType,   // pre | mid | post (the form's Type), or null
        public readonly array $counts,
        public readonly string $difficulty,        // easy | medium | hard | mixed
        public readonly array $bloomLevels,
        public readonly ?string $objectives,
        public readonly array $sources,
    ) {}

    public function total(): int
    {
        return array_sum($this->counts);
    }
}
