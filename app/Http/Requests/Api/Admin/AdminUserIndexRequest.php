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
        ];
    }

    public function perPage(): int
    {
        return (int) $this->integer('per_page', 15);
    }
}
