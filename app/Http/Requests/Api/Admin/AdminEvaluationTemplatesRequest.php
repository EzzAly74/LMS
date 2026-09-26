<?php

namespace App\Http\Requests\Api\Admin;

use App\Services\Admin\AdminEvaluationReportService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query validation for GET admin/evaluations/templates (Figma 2009:88432).
 *
 * Sort and filter fields are allow-listed; per_page is bounded (B-21); the
 * chip filters are capped arrays. The date range is on the last response, in
 * inclusive calendar days.
 */
class AdminEvaluationTemplatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware enforces auth.user + role:Admin + permission:view-evaluations.
        return true;
    }

    public function rules(): array
    {
        return [
            'page'             => ['sometimes', 'integer', 'min:1'],
            'per_page'         => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search'           => ['sometimes', 'nullable', 'string', 'max:191'],
            'course_ids'       => ['sometimes', 'array', 'max:100'],
            'course_ids.*'     => ['integer', 'min:1'],
            'instructor_ids'   => ['sometimes', 'array', 'max:100'],
            'instructor_ids.*' => ['integer', 'min:1'],
            'results'          => ['sometimes', 'array', 'max:3'],
            'results.*'        => [Rule::in(AdminEvaluationReportService::RESULTS)],
            'scored_from'      => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            // after_or_equal only when a start was sent: against an absent field
            // Laravel would compare with the literal string "scored_from".
            'scored_to'        => array_values(array_filter(['sometimes', 'nullable', 'date_format:Y-m-d',
                $this->filled('scored_from') ? 'after_or_equal:scored_from' : null])),
            'sort'             => ['sometimes', Rule::in(['created_at', 'name', 'last_scored'])],
            'dir'              => ['sometimes', Rule::in(['asc', 'desc'])],
        ];
    }

    public function perPage(): int
    {
        return (int) $this->integer('per_page', 15);
    }

    /** The validated filters, in the shape AdminEvaluationReportService::templates() takes. */
    public function filters(): array
    {
        $f = [];

        foreach (['course_ids', 'instructor_ids'] as $key) {
            $ids = array_values(array_unique(array_map('intval', (array) $this->input($key, []))));
            if ($ids !== []) {
                $f[$key] = $ids;
            }
        }
        if (($results = array_values(array_unique((array) $this->input('results', [])))) !== []) {
            $f['results'] = $results;
        }
        foreach (['search', 'scored_from', 'scored_to'] as $key) {
            if ($this->filled($key)) {
                $f[$key] = (string) $this->input($key);
            }
        }
        $f['sort'] = $this->input('sort', 'created_at');
        $f['dir']  = $this->input('dir', 'desc');

        return $f;
    }
}
