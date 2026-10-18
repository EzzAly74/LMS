<?php

namespace App\Http\Requests\Api\Admin\AiAssessments;

use App\AiAssessments\QuestionType;
use App\Models\AiAssessmentQuestion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A trainer's edit of one question: the same shape rules as AI output
 * (QuestionType), for the question's own type (the type is not editable).
 * Ownership is checked by the controller; here the question is only looked up
 * inside the route's assessment to learn its type.
 */
class AiAssessmentQuestionUpdateRequest extends FormRequest
{
    private ?string $type = null;

    public function authorize(): bool
    {
        return true;
    }

    public function questionType(): string
    {
        return $this->type ??= (function () {
            $type = AiAssessmentQuestion::query()
                ->whereKey((int) $this->route('question'))
                ->where('ai_assessment_id', (int) $this->route('assessment'))
                ->value('type');

            return in_array($type, QuestionType::ALL, true) ? $type : QuestionType::MCQ;
        })();
    }

    public function rules(): array
    {
        return QuestionType::rules($this->questionType()) + [
            'status' => ['sometimes', Rule::in(AiAssessmentQuestion::STATUSES)],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            if (QuestionType::consistencyError($this->questionType(), $this->all()) !== null) {
                $validator->errors()->add('answer', __('ai_assessments.validation.answer_key'));
            }
        }];
    }

    /** @return array<string, mixed> the stored shape */
    public function payload(): array
    {
        $data = $this->validated();
        $clean = QuestionType::clean($this->questionType(), $data + ['context' => null]);
        unset($clean['type']);
        if (isset($data['status'])) {
            $clean['status'] = $data['status'];
        }

        return $clean;
    }
}
