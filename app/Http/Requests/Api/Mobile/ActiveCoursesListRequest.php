<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Mobile;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Query params of `GET mobile/courses` (every active course, no employee).
 *
 * New surface, so the paging params are strict: `per_page` is capped
 * so one call can never pull the whole table.
 */
final class ActiveCoursesListRequest extends FormRequest
{
    public const MAX_PER_PAGE = 50;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page'     => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:' . self::MAX_PER_PAGE],
        ];
    }
}
