<?php

namespace App\Http\Requests\Api\Admin\AiAssessments;

use App\Models\AiAssessmentQuestion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Approve / flag / reset one question. */
class AiAssessmentQuestionStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['status' => ['required', Rule::in(AiAssessmentQuestion::STATUSES)]];
    }
}
