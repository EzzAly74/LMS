<?php

namespace App\Http\Requests\Api\Admin\AiAssessments;

use Illuminate\Foundation\Http\FormRequest;

/** The full new order; the controller checks it is exactly the assessment's question ids. */
class AiAssessmentReorderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ids'   => ['required', 'array', 'list', 'min:1', 'max:'.(int) config('ai-assessments.limits.questions_total')],
            'ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ];
    }
}
