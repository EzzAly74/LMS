<?php

namespace App\Http\Requests\Api\Admin;

use App\AiQuestions\SystemQuestion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * "Generate with AI" from the quiz or assignment form (D-113). Sources are
 * files and / or pasted text; the file content is checked by its bytes in
 * GenerationService (this only bounds size, count and extension). A body
 * `course_id` is checked against course scope by the section middleware.
 */
class AiQuestionGenerateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $limits = config('ai-questions.limits');

        return [
            'files'           => ['nullable', 'array', 'max:'.$limits['files']],
            'files.*'         => ['file', 'max:'.$limits['file_kb'], 'extensions:pdf,docx,pptx'],
            'text'            => ['nullable', 'string', 'max:'.$limits['pasted_chars']],
            'course_id'       => ['nullable', 'integer', Rule::exists('courses', 'id')],
            'assessment_type' => ['nullable', Rule::in(['pre', 'mid', 'post'])],
            'counts'          => ['required', 'array:'.implode(',', SystemQuestion::TYPES)],
            'counts.*'        => ['integer', 'min:0', 'max:'.$limits['questions_per_type']],
            'difficulty'      => ['required', Rule::in(['easy', 'medium', 'hard', 'mixed'])],
            'bloom_levels'    => ['nullable', 'array', 'list', 'max:6'],
            'bloom_levels.*'  => ['string', 'distinct', Rule::in(SystemQuestion::BLOOM)],
            'objectives'      => ['nullable', 'string', 'max:2000'],
            'score'           => ['nullable', 'integer', 'min:0', 'max:100'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $files = $this->file('files') ?? [];
            $text = trim((string) $this->input('text'));
            if ($files === [] && mb_strlen($text) < 50) {
                $validator->errors()->add('text', __('ai_questions.validation.no_source'));
            }

            $counts = $this->input('counts');
            if (is_array($counts)) {
                $total = array_sum(array_map(fn ($n) => is_numeric($n) ? (int) $n : 0, $counts));
                $max = (int) config('ai-questions.limits.questions_total');
                if ($total < 1 || $total > $max) {
                    $validator->errors()->add('counts', __('ai_questions.validation.counts', ['max' => $max]));
                }
            }
        }];
    }

    /** @return array<string, mixed> the generation settings */
    public function settings(): array
    {
        $d = $this->validated();

        return [
            'counts'          => array_map('intval', array_filter((array) $d['counts'], fn ($n) => (int) $n > 0)),
            'difficulty'      => $d['difficulty'],
            'bloom_levels'    => array_values((array) ($d['bloom_levels'] ?? [])),
            'objectives'      => filled($d['objectives'] ?? null) ? trim($d['objectives']) : null,
            'assessment_type' => $d['assessment_type'] ?? null,
            'score'           => (int) ($d['score'] ?? 1),
        ];
    }
}
