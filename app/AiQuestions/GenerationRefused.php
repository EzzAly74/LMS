<?php

namespace App\AiQuestions;

use RuntimeException;

/** Generation cannot start; `reason` is a key under ai_assessments.errors. */
final class GenerationRefused extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $status)
    {
        parent::__construct($reason);
    }
}
