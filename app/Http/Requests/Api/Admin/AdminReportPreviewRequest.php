<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Query validation for GET admin/reports/compliance-preview. `page` asks for
 * one bounded page of the job-title rows (the Reports screen's pager);
 * without it the endpoint answers its first 25 rows, as before.
 */
class AdminReportPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware enforces auth.user + role:Admin + section:reports.
        return true;
    }

    public function rules(): array
    {
        return [
            'page'     => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /** The page asked for, or null for the legacy first-25 preview. */
    public function page(): ?int
    {
        return $this->has('page') ? (int) $this->integer('page') : null;
    }

    public function perPage(): int
    {
        return (int) $this->integer('per_page', 10);
    }
}
