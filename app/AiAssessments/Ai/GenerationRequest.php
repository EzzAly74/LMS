<?php

namespace App\AiAssessments\Ai;

use App\Models\AiAssessment;
use App\Models\AiAssessmentSource;

/** One batch: N questions of one type for one assessment. */
final class GenerationRequest
{
    /**
     * @param  list<AiAssessmentSource>  $sources
     * @param  list<string>  $avoid  prompts already in the assessment, not to be repeated
     */
    public function __construct(
        public readonly AiAssessment $assessment,
        public readonly string $type,
        public readonly int $count,
        public readonly array $sources,
        public readonly array $avoid = [],
    ) {}
}
