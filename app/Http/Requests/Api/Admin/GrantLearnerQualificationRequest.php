<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Granting qualifications to one learner (D-045, Figma 2066:100876).
 *
 * `exists` on every id rather than filtering silently: a typo'd or stale
 * qualification id is a mistake the admin should see, not something to drop.
 * The same reasoning as the B4 importer, which reports unknown job titles
 * instead of inventing them.
 */
class GrantLearnerQualificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware enforces auth.user + role:Admin + permission:view-qualifications.
        return true;
    }

    public function rules(): array
    {
        return [
            'qualification_skill_ids'   => ['required', 'array', 'min:1', 'max:100'],
            'qualification_skill_ids.*' => ['integer', 'distinct', 'exists:qualification_skills,id'],

            // Why the grant was made. Optional, but this is the only place the
            // reason for a manual override can be recorded.
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return list<int> */
    public function skillIds(): array
    {
        return array_map('intval', $this->input('qualification_skill_ids', []));
    }

    public function note(): ?string
    {
        $note = $this->input('note');

        return is_string($note) && trim($note) !== '' ? trim($note) : null;
    }
}
