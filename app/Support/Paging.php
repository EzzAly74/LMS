<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * One ceiling for list page sizes (B-213): `per_page` / `limit` were read as
 * a bare int, so a single request could ask for every row. Values are kept
 * between 1 and MAX; 200 is the largest size a client asks for today (the
 * quiz / assignment course pickers), so no existing caller is cut short.
 */
final class Paging
{
    public const MAX = 200;

    public static function perPage(Request $request, int $default, string $key = 'per_page'): int
    {
        $value = $request->input($key);
        $size = is_numeric($value) ? (int) $value : $default;

        return max(1, min($size, self::MAX));
    }
}
