<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Resolve a Spatie-translatable column that was read with the query builder.
 *
 * Eloquent's HasTranslations decodes `{"en":"...","ar":"..."}` for you, but a
 * raw DB::table() select returns the stored JSON string as is. Reports built
 * on raw queries for speed (the admin learner profile tables, for one) then
 * shipped that string straight to the UI. This picks the current locale,
 * falls back to the other language, and passes a plain, non-JSON value
 * through unchanged, since some legacy columns (assignment titles) were never
 * translatable.
 */
final class LocalizedJson
{
    public static function pick(?string $raw, ?string $locale = null): ?string
    {
        if ($raw === null || $raw === '') {
            return $raw;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return $raw;
        }

        $locale ??= app()->getLocale();
        $other = $locale === 'ar' ? 'en' : 'ar';

        foreach ([$locale, $other] as $key) {
            if (isset($decoded[$key]) && is_string($decoded[$key]) && $decoded[$key] !== '') {
                return $decoded[$key];
            }
        }

        // Some other shape of JSON: first non-empty string, else nothing.
        foreach ($decoded as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
