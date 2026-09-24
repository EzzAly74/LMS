<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared query validation for the two paginated tables on the learner detail
 * screen. Both pagers take nothing but page + per_page, and per_page is bounded
 * (B-21).
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
        ];
    }

    public function perPage(): int
    {
        return (int) $this->integer('per_page', 10);
    }
}
