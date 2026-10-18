<?php

namespace App\Http\Requests\Api\Admin\AiAssessments;

use Illuminate\Foundation\Http\FormRequest;

/** `as_version`: keep the title and bump the version, instead of a "(copy)". */
class AiAssessmentDuplicateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['as_version' => ['sometimes', 'boolean']];
    }
}
