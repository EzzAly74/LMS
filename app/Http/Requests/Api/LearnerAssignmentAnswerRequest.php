<?php

namespace App\Http\Requests\Api;

use App\Models\CourseAssignmentQuestion;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a learner's answer to a single rich assignment question.
 * Mirrors LearnerQuizAnswerRequest — see CourseAssignmentQuestion::TYPES.
 */
class LearnerAssignmentAnswerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var CourseAssignmentQuestion|null $question */
        $question = $this->route('question');

        return match ($question?->type) {
            // D-033 / D-064: one uploaded file, multipart.
            'file' => ['file' => AssignmentFileRules::rules()],
            'open' => ['value' => ['required', 'string', 'max:500']],
            'reorder' => ['order' => ['required', 'array', 'min:1'], 'order.*' => ['string', 'max:500']],
            default => ['value' => ['required', 'string', 'max:1000']], // mcq | yes_no
        };
    }

    public function messages(): array
    {
        return AssignmentFileRules::messages('file');
    }
}
