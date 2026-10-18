<?php

namespace App\AiAssessments\Ai;

use App\Models\AiAssessment;
use App\Models\AiAssessmentSource;

/**
 * One call to the provider: every requested type at once, so the source
 * material is sent (and paid for) once per generation, not once per type.
 */
final class GenerationRequest
{
    /**
     * @param  array<string, int>  $counts  question type => how many, in QuestionType order
     * @param  list<AiAssessmentSource>  $sources
     * @param  list<string>  $avoid  prompts already in the assessment, not to be repeated
     */
    public function __construct(
        public readonly AiAssessment $assessment,
        public readonly array $counts,
        public readonly array $sources,
        public readonly array $avoid = [],
        // A person waits on this call (Regenerate one): retry only briefly.
        public readonly bool $interactive = false,
    ) {}

    public function total(): int
    {
        return array_sum($this->counts);
    }
}
