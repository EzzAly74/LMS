<?php

namespace App\Http\Requests\Api\Admin\AiAssessments;

use App\AiAssessments\Export\AssessmentExporter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AiAssessmentExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'format'  => ['required', Rule::in(AssessmentExporter::FORMATS)],
            'variant' => ['required', Rule::in(AssessmentExporter::VARIANTS)],
        ];
    }
}
