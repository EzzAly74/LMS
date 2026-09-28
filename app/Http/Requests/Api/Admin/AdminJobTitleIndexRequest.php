<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Query validation for GET admin/job-titles - the Job Titles index
 * (Figma 2078:102691) with its "Search by learner or job title" box and the
 * Filter modal (2463:138054: Qualification multi-select, Learner).
 *
 * The admin index is separate from GET job-titles because matching and
 * filtering by learner reveals which job title a named person holds; the
 * shared endpoint is open to any signed-in user and keeps its old contract.
 */
class AdminJobTitleIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware enforces auth.user + role:Admin + permission:view-job-titles.
        return true;
    }

    public function rules(): array
    {
        return [
            'page'                => ['sometimes', 'integer', 'min:1'],
            'per_page'            => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search'              => ['sometimes', 'nullable', 'string', 'max:191'],
            'qualification_ids'   => ['sometimes', 'array', 'max:50'],
            'qualification_ids.*' => ['integer', 'distinct', 'exists:qualification_skills,id'],
            'learner_id'          => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
        ];
    }

    public function perPage(): int
    {
        return (int) $this->integer('per_page', 12);
    }

    public function search(): ?string
    {
        $term = trim((string) $this->input('search', ''));

        return $term === '' ? null : $term;
    }

    /** @return list<int> */
    public function qualificationIds(): array
    {
        return array_values(array_map('intval', (array) $this->input('qualification_ids', [])));
    }

    public function learnerId(): ?int
    {
        return $this->filled('learner_id') ? (int) $this->input('learner_id') : null;
    }
}
