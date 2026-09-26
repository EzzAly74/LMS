<?php

namespace App\Http\Requests\Api\Admin;

use App\Services\Admin\AdminEvaluationReportService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query validation for GET admin/evaluations/scores (Figma 2017:52260) and the
 * `template_id` of the submission detail.
 *
 * per_page is bounded (B-21). The single ids are validated against their
 * tables, so an unknown course or template is a 422 rather than a silently
 * empty list. The chip filters are arrays (the Dashboard sends `key[]=v`),
 * each matching ANY of its values, combined with AND, and capped so a query
 * string cannot build an arbitrarily large IN list.
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
            'page'             => ['sometimes', 'integer', 'min:1'],
            'per_page'         => ['sometimes', 'integer', 'min:1', 'max:100'],
            'course_id'        => ['sometimes', 'nullable', 'integer', 'exists:courses,id'],
            'template_id'      => ['sometimes', 'nullable', 'integer', 'exists:evaluation_categories,id'],
            'search'           => ['sometimes', 'nullable', 'string', 'max:191'],
            'course_ids'       => ['sometimes', 'array', 'max:100'],
            'course_ids.*'     => ['integer', 'min:1'],
            'instructor_ids'   => ['sometimes', 'array', 'max:100'],
            'instructor_ids.*' => ['integer', 'min:1'],
            'learner_ids'      => ['sometimes', 'array', 'max:100'],
            'learner_ids.*'    => ['integer', 'min:1'],
            'results'          => ['sometimes', 'array', 'max:3'],
            'results.*'        => [Rule::in(AdminEvaluationReportService::RESULTS)],
            // Sorted on the last-scored date, the one sortable column in the frame.
            'dir'              => ['sometimes', Rule::in(['asc', 'desc'])],
        ];
    }

    public function perPage(): int
    {
        return (int) $this->integer('per_page', 15);
    }

    /** The validated filters, in the shape AdminEvaluationReportService::scores() takes. */
    public function filters(): array
    {
        $f = [];

        // `course_id` is the B3 single-course filter; it joins the array form.
        $courseIds = $this->ids('course_ids');
        if ($this->filled('course_id')) {
            $courseIds[] = $this->integer('course_id');
        }
        if ($courseIds !== []) {
            $f['course_ids'] = array_values(array_unique($courseIds));
        }

        foreach (['instructor_ids', 'learner_ids'] as $key) {
            if (($ids = $this->ids($key)) !== []) {
                $f[$key] = $ids;
            }
        }

        if ($this->filled('template_id')) {
            $f['template_id'] = $this->integer('template_id');
        }
        if ($this->filled('search')) {
            $f['search'] = $this->string('search')->toString();
        }
        if (($results = array_values(array_unique((array) $this->input('results', [])))) !== []) {
            $f['results'] = $results;
        }
        $f['dir'] = $this->input('dir', 'desc');

        return $f;
    }

    /** @return list<int> */
    private function ids(string $key): array
    {
        return array_values(array_unique(array_map('intval', (array) $this->input($key, []))));
    }
}
