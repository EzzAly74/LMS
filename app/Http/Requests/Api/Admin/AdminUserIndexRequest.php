<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query validation for GET admin/users.
 *
 * The index previously read its query string straight off the Request with no
 * validation at all, including `per_page`, which made it one of the 41
 * unbounded-pagination controllers recorded as B-21: a caller could ask for the
 * entire people table in one response. Stage B bounds it while the endpoint is
 * being extended for the Figma learners list rather than leaving a known
 * finding open in code being touched.
 *
 * `role` and `status` are deliberately NOT enumerated here. The controller's
 * normaliseRole() already accepts either a system bucket
 * (admin|instructor|learner) or any custom role machine name from the `roles`
 * table, so an allow-list in this class would have to be kept in sync with a
 * database table and would reject legitimate custom roles.
 */
class AdminUserIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware enforces auth.user + role:Admin.
        return true;
    }

    public function rules(): array
    {
        return [
            'page'              => ['sometimes', 'integer', 'min:1'],
            'per_page'          => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search'            => ['sometimes', 'nullable', 'string', 'max:191'],
            'role'              => ['sometimes', 'nullable', 'string', 'max:100'],
            // Matches the controller's normaliseStatus(), which accepts exactly
            // these three and silently maps anything else to null.
            'status'            => ['sometimes', 'nullable', Rule::in(['active', 'inactive', 'deactivated'])],

            // intArray() accepts EITHER an array OR a comma-separated string
            // ("3,7,11"). Constraining this to `array` would have rejected the
            // string form and broken existing callers, so both are allowed and
            // the element rule is applied only when an array is sent.
            'instructor_ids'    => ['sometimes', 'nullable'],
            'instructor_ids.*'  => ['sometimes', 'integer', 'min:1'],

            // Learners-list filters (D3, Figma 1986:74701; D-053). New, so they
            // take arrays only - the Dashboard sends `key[]=v`. Each matches ANY
            // of its values; different filters combine with AND. Bounded so a
            // query string cannot build an arbitrarily large IN list.
            'course_ids'              => ['sometimes', 'array', 'max:100'],
            'course_ids.*'            => ['integer', 'min:1'],
            // Learners enrolled in a course taught by one of these instructors.
            // Distinct from `instructor_ids`, which selects the instructors
            // THEMSELVES on the Users page - reusing that name would have
            // changed an existing contract.
            'course_instructor_ids'   => ['sometimes', 'array', 'max:100'],
            'course_instructor_ids.*' => ['integer', 'min:1'],
            'qualification_ids'       => ['sometimes', 'array', 'max:100'],
            'qualification_ids.*'     => ['integer', 'min:1'],
            'learner_types'           => ['sometimes', 'array', 'max:3'],
            'learner_types.*'         => [Rule::in(self::LEARNER_TYPES)],
            // Last activity (users.last_active_at), inclusive calendar days.
            'active_from'             => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            // after_or_equal only when a start was sent: against an absent field
            // Laravel would compare with the literal string "active_from".
            'active_to'               => array_values(array_filter(['sometimes', 'nullable', 'date_format:Y-m-d',
                $this->filled('active_from') ? 'after_or_equal:active_from' : null])),
        ];
    }

    /** Values of users.learner_type (see AdminUserStoreRequest). */
    public const LEARNER_TYPES = ['online', 'offline', 'hybrid'];

    /**
     * The learner filters that were sent, typed. Empty arrays and blank dates
     * are dropped, so "no filter" and "filter on nothing" cannot be confused.
     *
     * @return array{course_ids?: list<int>, course_instructor_ids?: list<int>, qualification_ids?: list<int>, learner_types?: list<string>, active_from?: string, active_to?: string}
     */
    public function learnerFilters(): array
    {
        $out = [];
        foreach (['course_ids', 'course_instructor_ids', 'qualification_ids'] as $key) {
            $ids = array_values(array_unique(array_map('intval', (array) $this->input($key, []))));
            if ($ids !== []) {
                $out[$key] = $ids;
            }
        }
        $types = array_values(array_unique((array) $this->input('learner_types', [])));
        if ($types !== []) {
            $out['learner_types'] = $types;
        }
        foreach (['active_from', 'active_to'] as $key) {
            if ($this->filled($key)) {
                $out[$key] = (string) $this->input($key);
            }
        }

        return $out;
    }

    public function perPage(): int
    {
        return (int) $this->integer('per_page', 15);
    }
}
