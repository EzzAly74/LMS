<?php

namespace App\Http\Requests\Api\Admin\AiAssessments;

/** Same rules as creating; every field optional (each wizard step saves its own part). */
class AiAssessmentUpdateRequest extends AiAssessmentStoreRequest
{
    protected function presence(): string
    {
        return 'sometimes';
    }
}
