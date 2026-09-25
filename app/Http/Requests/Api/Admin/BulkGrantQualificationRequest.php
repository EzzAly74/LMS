<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Granting one qualification to many learners — the "Assign Qualification"
 * bulk action on the learners list (Q-038, Figma 2325:117118).
 *
 * Bounded at 500 learners per call. A bulk grant is a single INSERT, so the
 * limit is not about query cost: it is about blast radius. An unbounded
 * "assign to everyone" is one mis-click away from putting a qualification on
 * every employee in the company, and every one of those rows then has to be
 * found and revoked by hand.
 */
class BulkGrantQualificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware enforces auth.user + role:Admin + permission:view-qualifications.
        return true;
    }

    public function rules(): array
    {
        return [
            'user_ids'   => ['required', 'array', 'min:1', 'max:500'],
            'user_ids.*' => ['integer', 'distinct', 'exists:users,id'],
            'note'       => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return list<int> */
    public function userIds(): array
    {
        return array_map('intval', $this->input('user_ids', []));
    }

    public function note(): ?string
    {
        $note = $this->input('note');

        return is_string($note) && trim($note) !== '' ? trim($note) : null;
    }
}
