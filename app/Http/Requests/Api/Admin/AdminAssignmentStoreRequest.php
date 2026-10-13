<?php

namespace App\Http\Requests\Api\Admin;

use App\Models\CourseAssignment;
use App\Models\CourseAssignmentQuestion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class AdminAssignmentStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'course_id'                 => ['required', 'integer', 'exists:courses,id'],
            'title'                     => ['required', 'string', 'max:255'],
            // Figma marks the Arabic title, Type and every Arabic question
            // field required (answered 2026-09-28, D-066).
            'title_ar'                  => ['required', 'string', 'max:255'],
            'instructions_en'           => ['nullable', 'string', 'max:5000'],
            'instructions_ar'           => ['nullable', 'string', 'max:5000'],
            'due_date'                  => ['nullable', 'date'],
            'cohort_scope'              => ['required', Rule::in(['all', 'specific'])],
            'cohort_ids'                => ['nullable', 'array'],
            // Cohorts are course_sections of THIS course (B-137).
            'cohort_ids.*'              => ['integer', 'distinct', Rule::exists('course_sections', 'id')->where('course_id', (int) $this->input('course_id'))],
            'pass_score'                => ['nullable', 'integer', 'min:0'],
            'status'                    => ['nullable', Rule::in(['draft', 'active'])],
            // Pre / Mid / Post (D-065, required by D-066). On quizzes Post is the final exam.
            'type'                      => ['required', Rule::in(['pre', 'mid', 'post'])],

            'questions'                 => ['required', 'array', 'min:1'],
            // Present when editing: the question is updated in place, so its
            // learners' answers survive (B-128). Checked against this
            // assignment in AdminAssignmentService::syncQuestions.
            'questions.*.id'            => ['nullable', 'integer', 'distinct'],
            'questions.*.type'          => ['required', Rule::in(CourseAssignmentQuestion::TYPES)],
            'questions.*.score'         => ['required', 'integer', 'min:0'],
            'questions.*.question_en'   => ['required', 'string', 'max:2000'],
            'questions.*.question_ar'   => ['required', 'string', 'max:2000'],
            'questions.*.options_en'    => ['nullable', 'array'],
            'questions.*.options_en.*'  => ['string', 'max:500'],
            'questions.*.options_ar'    => ['nullable', 'array'],
            'questions.*.options_ar.*'  => ['string', 'max:500'],
            'questions.*.correct_answer_en' => ['nullable', 'string', 'max:5000'],
            'questions.*.correct_answer_ar' => ['nullable', 'string', 'max:5000'],
            'questions.*.explanation_en'    => ['nullable', 'string', 'max:5000'],
            'questions.*.explanation_ar'    => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** Plain messages for the limits an author can hit (NEW2B-6251, NEW2B-6268). */
    public function messages(): array
    {
        return [
            'title.max'          => __('messages.assignment_title_too_long', ['max' => 255]),
            'title_ar.max'       => __('messages.assignment_title_too_long', ['max' => 255]),
            'questions.*.score.min' => __('messages.assignment_score_negative'),
            'pass_score.min'     => __('messages.assignment_score_negative'),
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $scope    = $this->input('cohort_scope');
            $cohorts  = $this->input('cohort_ids', []);

            // A new due date cannot be in the past (NEW2B-6256); an edit may
            // keep the date it already had.
            $due = $this->input('due_date');
            if (filled($due) && ! $v->errors()->has('due_date')) {
                $day = Carbon::parse($due)->startOfDay();
                $id = $this->route('assignment');
                $kept = $id !== null ? CourseAssignment::whereKey((int) $id)->value('due_date') : null;
                if ($day->lt(today()) && ($kept === null || Carbon::parse($kept)->toDateString() !== $day->toDateString())) {
                    $v->errors()->add('due_date', __('messages.assignment_due_date_past'));
                }
            }

            // The pass score is out of the questions' total.
            $total = array_sum(array_map(fn ($q) => max(0, (int) ($q['score'] ?? 0)), (array) $this->input('questions', [])));
            if ($this->filled('pass_score') && ! $v->errors()->has('pass_score') && (int) $this->input('pass_score') > $total) {
                $v->errors()->add('pass_score', __('messages.assignment_pass_score_over_total', ['total' => $total]));
            }

            if ($scope === 'specific' && (empty($cohorts) || !is_array($cohorts))) {
                $v->errors()->add('cohort_ids', __('Select at least one cohort when scope is specific.'));
            }

            foreach ((array) $this->input('questions', []) as $i => $q) {
                $type = $q['type'] ?? null;

                foreach (['en', 'ar'] as $lang) {
                    $opts = $q["options_$lang"] ?? [];
                    if (in_array($type, ['mcq', 'reorder'], true) && (empty($opts) || count($opts) < 2)) {
                        $v->errors()->add("questions.$i.options_$lang", __('Provide at least two options.'));
                    }

                    if (! in_array($type, CourseAssignmentQuestion::MANUAL_TYPES, true) && empty($q["correct_answer_$lang"])) {
                        $v->errors()->add("questions.$i.correct_answer_$lang", __('Correct answer is required for this question type.'));
                    }
                }
            }
        });
    }
}
