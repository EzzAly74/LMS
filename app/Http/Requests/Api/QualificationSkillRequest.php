<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class QualificationSkillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'    => ['required', 'array'],
            'name.en' => ['required', 'string', 'max:255'],
            'name.ar' => ['required', 'string', 'max:255'],

            /*
             * One-step assignment to job titles (Figma 2066:100876). Previously
             * a qualification had to be created first and linked afterwards via
             * PUT job-titles/{id}/qualifications, so the modal could not do what
             * it is designed to do in a single submit.
             *
             * Optional, so existing callers are unaffected. `exists` rather than
             * a bare integer, so a bad id is a 422 instead of a silently
             * dropped assignment.
             *
             * NOTE: the same modal also assigns to individual LEARNERS. That is
             * deliberately not implemented — there is no learner-qualification
             * table, and adding one changes what "required qualifications"
             * means for the compliance figures on the job-title cards and the
             * learners list. See 04-decisions.md D-045; it needs a business
             * decision, not a schema guess.
             */
            'job_title_ids'   => ['sometimes', 'array', 'max:200'],
            'job_title_ids.*' => ['integer', 'exists:job_titles,id'],
        ];
    }
}
