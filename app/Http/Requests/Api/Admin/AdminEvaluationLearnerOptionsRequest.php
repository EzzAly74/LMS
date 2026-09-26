<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Query validation for GET admin/evaluations/learner-options, the searchable
 * choices behind the Learners chip on "View Learners scores".
 */
class AdminEvaluationLearnerOptionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware enforces auth.user + role:Admin + permission:view-evaluations.
        return true;
    }

    public function rules(): array
    {
        return [
            'page'     => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search'   => ['sometimes', 'nullable', 'string', 'max:191'],
        ];
    }

    public function perPage(): int
    {
        return (int) $this->integer('per_page', 50);
    }
}
