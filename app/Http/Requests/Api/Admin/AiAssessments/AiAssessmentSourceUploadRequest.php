<?php

namespace App\Http\Requests\Api\Admin\AiAssessments;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A source file. Size and extension are checked here; the content signature
 * (the authoritative check) is SourceReader::detect in SourceService.
 */
class AiAssessmentSourceUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.(int) config('ai-assessments.limits.file_kb'), 'extensions:pdf,docx,pptx'],
        ];
    }
}
