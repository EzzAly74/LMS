<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query validation for GET admin/job-titles/{job_title}/learners.
 *
 * Sort and direction are allow-listed rather than passed through, per the
 * CLAUDE.md backend standard — the repository interpolates the direction into
 * an ORDER BY, so an un-allow-listed value would be a SQL injection vector.
 *
 * `per_page` is bounded. B-21 recorded 41 controllers reading `per_page` with
 * no min/max at all, which lets a caller request the whole table; new
 * endpoints do not repeat that.
 */
class JobTitleLearnersRequest extends FormRequest
{
    public const SORTS = ['name', 'completion', 'employee_id'];

    public function authorize(): bool
    {
        // Route middleware enforces auth.user + role:Admin + permission:view-job-titles.
        return true;
    }

    public function rules(): array
    {
        return [
            'page'     => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search'   => ['sometimes', 'nullable', 'string', 'max:191'],
            'sort'     => ['sometimes', Rule::in(self::SORTS)],
            'dir'      => ['sometimes', Rule::in(['asc', 'desc'])],
        ];
    }

    public function perPage(): int
    {
        return (int) $this->integer('per_page', 20);
    }

    public function sort(): string
    {
        return (string) $this->string('sort', 'name');
    }

    public function direction(): string
    {
        return (string) $this->string('dir', 'asc');
    }
}
