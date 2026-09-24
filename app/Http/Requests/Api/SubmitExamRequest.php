<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class SubmitExamRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Ownership/enrolment is enforced in UserExamController::submit, which
        // has the route-model-bound Course and CourseExam available.
        return true;
    }

    public function rules(): array
    {
        return [
            'questions'               => 'required|array|min:1|max:500',
            'questions.*.question_id' => 'required|integer|exists:course_exam_questions,id',

            // B-09: `answer_id` had no `exists` rule, so any answer row in the
            // database was accepted — including answers belonging to a
            // different exam. The service now additionally checks that the
            // chosen answer belongs to the question being answered; this rule
            // rejects the obviously-invalid case early with a 422 instead of
            // silently grading it as unanswered.
            'questions.*.answer_id'   => 'required|integer|exists:course_exam_question_answers,id',

            // Accepted for backwards compatibility with existing clients but
            // no longer trusted: the stored question text is read from the
            // database, not from this field.
            'questions.*.question_title' => 'sometimes|nullable|string|max:1000',
        ];
    }
}
