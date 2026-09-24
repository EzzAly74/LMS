<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Query validation for GET admin/evaluations/scores (Figma 2017:52260).
 *
 * per_page is bounded (B-21). Filters are validated against the tables they
 * point at, so an unknown course or template is a 422 rather than a silently
 * empty list.
 */
class AdminEvaluationScoresRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware enforces auth.user + role:Admin + permission:view-evaluations.
        return true;
    }

    public function rules(): array
    {
        return [
            'page'        => ['sometimes', 'integer', 'min:1'],
            'per_page'    => ['sometimes', 'integer', 'min:1', 'max:100'],
            'course_id'   => ['sometimes', 'nullable', 'integer', 'exists:courses,id'],
            'template_id' => ['sometimes', 'nullable', 'integer', 'exists:evaluation_categories,id'],
            'search'      => ['sometimes', 'nullable', 'string', 'max:191'],
        ];
    }

    public function perPage(): int
    {
        return (int) $this->integer('per_page', 15);
    }
}
