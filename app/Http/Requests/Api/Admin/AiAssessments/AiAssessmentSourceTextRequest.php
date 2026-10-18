<?php

namespace App\Http\Requests\Api\Admin\AiAssessments;

use Illuminate\Foundation\Http\FormRequest;

class AiAssessmentSourceTextRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'text'  => ['required', 'string', 'min:50', 'max:'.(int) config('ai-assessments.limits.pasted_chars')],
            'label' => ['nullable', 'string', 'max:120'],
        ];
    }
}
