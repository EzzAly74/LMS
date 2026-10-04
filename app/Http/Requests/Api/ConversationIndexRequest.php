<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET conversations: the Dashboard inbox (tab + pages, NEW2B-5905) and the
 * website Messages widget (role). Both filters run in SQL before the page is
 * taken, so they are allow-listed here.
 */
class ConversationIndexRequest extends FormRequest
{
    public const TABS = ['all', 'unread', 'received', 'sent'];

    public const ROLES = ['all', 'instructors', 'admins', 'learners'];

    public const DEFAULT_PER_PAGE = 30;

    public const MAX_PER_PAGE = 100;

    public function authorize(): bool
    {
        // Route middleware enforces auth.user; the service scopes every row to the caller.
        return true;
    }

    public function rules(): array
    {
        return [
            'tab'      => ['sometimes', 'nullable', Rule::in(self::TABS)],
            'role'     => ['sometimes', 'nullable', Rule::in(self::ROLES)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'page'     => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? self::DEFAULT_PER_PAGE);
    }
}
