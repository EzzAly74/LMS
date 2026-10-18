<?php

namespace App\Http\Requests\Api\Admin\AiAssessments;

use App\Models\AiAssessment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Library filters: allow-listed, bounded (D-113). */
class AiAssessmentIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search'           => ['nullable', 'string', 'max:100'],
            'type'             => ['nullable', Rule::in(AiAssessment::TYPES)],
            'content_language' => ['nullable', Rule::in(AiAssessment::LANGUAGES)],
            'course_id'        => ['nullable', 'integer', 'min:1'],
            'page'             => ['nullable', 'integer', 'min:1', 'max:100000'],
            'per_page'         => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
