<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** GET admin/qualification-skills/assignees - the New Qualification modal's search. */
class AdminQualificationAssigneesRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware enforces auth.user + role:Admin + permission:view-qualifications.
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:191'],
            'type'   => ['sometimes', Rule::in(['all', 'job_titles', 'learners'])],
        ];
    }

    public function search(): ?string
    {
        $s = $this->input('search');

        return is_string($s) ? $s : null;
    }

    public function type(): string
    {
        return (string) $this->input('type', 'all');
    }
}
