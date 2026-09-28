<?php

namespace App\Http\Requests\Api\Admin;

use App\Models\Evaluation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create / update an evaluation template (Figma 2409:132793 / 2409:133222).
 *
 * Everything in the builder is required except the cohort: names in both
 * languages, at least one question, and for a scale question the words under
 * "1" and "5" in both languages. Question types are the two the builder offers
 * (Q-051), and every question in a template has the same one (D-061). A course must have evaluation enabled - a template scoped to any
 * other course would never be shown to anyone. A cohort must belong to the
 * chosen course. Names are unique per language, ignoring case (as in D-034).
 */
class AdminEvaluationTemplateRequest extends FormRequest
{
    public const MAX_QUESTIONS = 50;

    /** The words under "1" and "5": Figma annotates the field "max 20 character". */
    public const MAX_LABEL = 20;

    public function authorize(): bool
    {
        // Route middleware enforces auth.user + role:Admin + permission:view-evaluations.
        return true;
    }

    public function rules(): array
    {
        return [
            'name'                        => ['required', 'array'],
            'name.en'                     => ['required', 'string', 'max:191'],
            'name.ar'                     => ['required', 'string', 'max:191'],
            'course_id'                   => ['nullable', 'integer', Rule::exists('courses', 'id')->where('is_evaluate', 1)],
            'section_id'                  => ['nullable', 'integer', 'exists:course_sections,id'],
            'questions'                   => ['required', 'array', 'min:1', 'max:'.self::MAX_QUESTIONS],
            'questions.*.title'           => ['required', 'array'],
            'questions.*.title.en'        => ['required', 'string', 'max:500'],
            'questions.*.title.ar'        => ['required', 'string', 'max:500'],
            'questions.*.type'            => ['required', Rule::in(Evaluation::BUILDER_TYPES)],
            'questions.*.required'        => ['required', 'boolean'],
            'questions.*.scale_label_min'    => ['nullable', 'array', 'required_if:questions.*.type,scale'],
            'questions.*.scale_label_min.en' => ['required_if:questions.*.type,scale', 'nullable', 'string', 'max:'.self::MAX_LABEL],
            'questions.*.scale_label_min.ar' => ['required_if:questions.*.type,scale', 'nullable', 'string', 'max:'.self::MAX_LABEL],
            'questions.*.scale_label_max'    => ['nullable', 'array', 'required_if:questions.*.type,scale'],
            'questions.*.scale_label_max.en' => ['required_if:questions.*.type,scale', 'nullable', 'string', 'max:'.self::MAX_LABEL],
            'questions.*.scale_label_max.ar' => ['required_if:questions.*.type,scale', 'nullable', 'string', 'max:'.self::MAX_LABEL],
        ];
    }

    public function after(): array
    {
        return [function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            // One question type per template (D-061): the builder sets it once
            // in the General section and every question follows it.
            $types = array_unique(array_column((array) $this->input('questions'), 'type'));
            if (count($types) > 1) {
                $v->errors()->add('questions', __('messages.evaluation_single_type'));
            }

            $courseId  = $this->input('course_id');
            $sectionId = $this->input('section_id');
            if ($sectionId !== null) {
                $belongs = $courseId !== null && DB::table('course_sections')
                    ->where('id', $sectionId)->where('course_id', $courseId)->exists();
                if (! $belongs) {
                    $v->errors()->add('section_id', __('messages.evaluation_cohort_mismatch'));
                }
            }

            $self = $this->route('template')?->id;
            foreach (['en', 'ar'] as $locale) {
                $name = mb_strtolower(trim((string) $this->input("name.{$locale}")));
                $taken = DB::table('evaluation_categories')
                    ->when($self, fn ($q) => $q->where('id', '!=', $self))
                    ->whereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(name) THEN name ELSE JSON_OBJECT('{$locale}', name) END, '$.{$locale}'))) = ?", [$name])
                    ->exists();
                if ($taken) {
                    $v->errors()->add("name.{$locale}", __('messages.evaluation_name_taken'));
                }
            }
        }];
    }

    /** The validated payload, normalised for EvaluationTemplateService. */
    public function payload(): array
    {
        $d = $this->validated();

        return [
            'name'       => ['en' => trim($d['name']['en']), 'ar' => trim($d['name']['ar'])],
            'course_id'  => isset($d['course_id']) ? (int) $d['course_id'] : null,
            'section_id' => isset($d['section_id']) ? (int) $d['section_id'] : null,
            'questions'  => array_map(fn ($q) => [
                'title'    => ['en' => trim($q['title']['en']), 'ar' => trim($q['title']['ar'])],
                'type'     => $q['type'],
                'required' => (bool) $q['required'],
                'scale_label_min' => $q['type'] === 'scale' ? ['en' => trim($q['scale_label_min']['en']), 'ar' => trim($q['scale_label_min']['ar'])] : null,
                'scale_label_max' => $q['type'] === 'scale' ? ['en' => trim($q['scale_label_max']['en']), 'ar' => trim($q['scale_label_max']['ar'])] : null,
            ], array_values($d['questions'])),
        ];
    }
}
