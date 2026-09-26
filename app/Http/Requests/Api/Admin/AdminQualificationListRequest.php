<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query validation for GET admin/qualification-skills (Figma 2066:100159) and
 * its Export, which takes the same search. per_page is bounded (B-21).
 */
class AdminQualificationListRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware enforces auth.user + role:Admin + permission:view-qualifications.
        return true;
    }

    public function rules(): array
    {
        return [
            'page'     => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search'   => ['sometimes', 'nullable', 'string', 'max:191'],
            'format'   => ['sometimes', Rule::in(['xlsx', 'csv'])],
        ];
    }

    public function perPage(): int
    {
        return (int) $this->integer('per_page', 15);
    }

    public function search(): ?string
    {
        $s = $this->input('search');

        return is_string($s) && trim($s) !== '' ? trim($s) : null;
    }

    public function fileFormat(): string
    {
        return (string) $this->input('format', 'xlsx');
    }
}
