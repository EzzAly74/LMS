<?php

namespace App\AiQuestions\Ai;

use RuntimeException;

/**
 * A run that produced nothing usable. `reason` is a key under
 * ai_assessments.errors, safe to show and store (never provider text, which
 * could echo source material or request details).
 */
final class GenerationFailed extends RuntimeException
{
    public const NOT_CONFIGURED = 'not_configured';
    public const PROVIDER_AUTH = 'provider_auth';
    public const PROVIDER_BUSY = 'provider_busy';
    public const PROVIDER_ERROR = 'provider_error';
    public const BLOCKED = 'blocked';
    public const INVALID_OUTPUT = 'invalid_output';
    public const SOURCE_UNAVAILABLE = 'source_unavailable';
    public const TIMEOUT = 'timeout';

    public function __construct(public readonly string $reason, string $detail = '')
    {
        parent::__construct($detail !== '' ? $reason.': '.$detail : $reason);
    }
}
