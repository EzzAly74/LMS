<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared query validation for the two paginated tables on the learner detail
 * screen: page + per_page (bounded, B-21), and the page's search and From / To
 * (Figma 2181:115043, NEW2B-6226), which filter both tables.
 */
class AdminLearnerTableRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware enforces auth.user + role:Admin + permission:view-users.
        return true;
    }

    public function rules(): array
    {
        return [
            'page'     => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search'   => ['sometimes', 'nullable', 'string', 'max:191'],
            'from'     => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to'       => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /** @return array{search: ?string, from: ?string, to: ?string} */
    public function filters(): array
    {
        $search = trim((string) $this->input('search', ''));

        return [
            'search' => $search !== '' ? $search : null,
            'from'   => $this->input('from') ?: null,
            'to'     => $this->input('to') ?: null,
        ];
    }

    public function perPage(): int
    {
        return (int) $this->integer('per_page', 10);
    }
}
