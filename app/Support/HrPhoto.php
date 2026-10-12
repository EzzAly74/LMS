<?php

namespace App\Support;

/**
 * An employee photo stored by HR (NEW2B-6219).
 *
 * HR's employee feed sends `imageUrl` as a path under its media folder
 * ("Employee/<file>.jpg"); the picture is served from
 * config('hr.files_base_url') + that path. Only a plain relative image path
 * is accepted, so a feed value can never point the page at another host or
 * climb out of the folder.
 */
final class HrPhoto
{
    private const PATH = '#^[A-Za-z0-9_\-]+(?:/[A-Za-z0-9_\-]+)*\.(?:jpe?g|png|webp|gif)$#i';

    /** The path to keep from a feed value, or null when it is empty or unsafe. */
    public static function path(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $path = ltrim(trim(str_replace(chr(92), '/', $value)), '/');

        return preg_match(self::PATH, $path) === 1 ? $path : null;
    }

    /** The photo's address, or null when there is none or no base is set. */
    public static function url(?string $path): ?string
    {
        $path = self::path($path);
        $base = rtrim((string) config('hr.files_base_url'), '/');
        if ($path === null || $base === '' || ! str_starts_with($base, 'https://')) {
            return null;
        }

        return $base.'/'.implode('/', array_map('rawurlencode', explode('/', $path)));
    }
}
