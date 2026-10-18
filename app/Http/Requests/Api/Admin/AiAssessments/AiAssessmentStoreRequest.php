<?php

namespace App\Http\Requests\Api\Admin\AiAssessments;

use App\AiAssessments\QuestionType;
use App\Models\AiAssessment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Details + configuration of an assessment (wizard steps 1 and 3). A course
 * named here is checked against the account's course scope by the section
 * middleware (D-074, body `course_id`).
 */
class AiAssessmentStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function presence(): string
    {
        return 'required';
    }

    public function rules(): array
    {
        $presence = $this->presence();
        $perType = (int) config('ai-assessments.limits.questions_per_type');

        return [
            'title'             => [$presence, 'string', 'min:2', 'max:200'],
            'type'              => [$presence, Rule::in(AiAssessment::TYPES)],
            'content_language'  => [$presence, Rule::in(AiAssessment::LANGUAGES)],
            'difficulty'        => [$presence, Rule::in(AiAssessment::DIFFICULTIES)],
            'audience'          => ['nullable', 'string', 'max:150'],
            'course_id'         => ['nullable', 'integer', Rule::exists('courses', 'id')],
            'objectives'        => ['nullable', 'string', 'max:4000'],
            'bloom_levels'      => ['nullable', 'array', 'list', 'max:6'],
            'bloom_levels.*'    => ['string', 'distinct', Rule::in(QuestionType::BLOOM)],
            'question_counts'   => ['nullable', 'array:'.implode(',', QuestionType::ALL)],
            'question_counts.*' => ['integer', 'min:0', 'max:'.$perType],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $counts = $this->input('question_counts');
            if (! is_array($counts)) {
                return;
            }
            $total = array_sum(array_map(fn ($n) => is_numeric($n) ? (int) $n : 0, $counts));
            $max = (int) config('ai-assessments.limits.questions_total');
            if ($total > $max) {
                $validator->errors()->add('question_counts', __('ai_assessments.validation.questions_total', ['max' => $max]));
            }
        }];
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $data = $this->validated();
        if (array_key_exists('question_counts', $data)) {
            $data['question_counts'] = array_map('intval', array_filter((array) $data['question_counts'], fn ($n) => (int) $n > 0));
        }
        if (array_key_exists('bloom_levels', $data)) {
            $data['bloom_levels'] = array_values((array) $data['bloom_levels']);
        }

        return $data;
    }
}
