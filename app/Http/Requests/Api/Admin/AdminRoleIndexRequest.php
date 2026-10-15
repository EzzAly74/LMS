<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Query validation for GET admin/roles. `page` asks for one bounded page (the
 * Roles screen); without it every role comes back, as the Controllers role
 * picker expects. per_page is capped at 100 like the other admin lists.
 */
class AdminRoleIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware enforces auth.user + role:Admin + section:roles.
        return true;
    }

    public function rules(): array
    {
        return [
            'page'     => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search'   => ['sometimes', 'nullable', 'string'],
        ];
    }

    /** The page asked for, or null for the whole list. */
    public function page(): ?int
    {
        return $this->has('page') ? (int) $this->integer('page') : null;
    }

    public function perPage(): int
    {
        return (int) $this->integer('per_page', 12);
    }

    /** At most 100 characters, as the endpoint always took. */
    public function search(): ?string
    {
        $s = $this->input('search');

        return is_string($s) && trim($s) !== '' ? mb_substr(trim($s), 0, 100) : null;
    }
}
