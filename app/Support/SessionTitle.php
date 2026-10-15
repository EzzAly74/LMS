<?php

namespace App\Support;

/**
 * A cohort session's title in the reader's language.
 *
 * course_sessions.title is one string, written in the language of whoever
 * created the session: the schedule import writes "Session 7" or "الجلسة 7",
 * a live session started from the Dashboard writes "Live Session — <date>" or
 * "جلسة مباشرة — <date>". Those generated titles are recognised here and
 * written again in the current locale; a title an admin typed is returned as
 * it is.
 */
final class SessionTitle
{
    /** Generated title keys and the placeholder each carries. */
    private const GENERATED = [
        'messages.schedule_session_title' => ['n', '\d+'],
        'messages.passcode.session_title' => ['date', '.+'],
    ];

    private const LOCALES = ['en', 'ar'];

    /** Whether $title is one of the generated titles (in any language), not one an admin typed. */
    public static function isGenerated(string $title): bool
    {
        foreach (self::GENERATED as $key => [$name, $pattern]) {
            foreach (self::LOCALES as $from) {
                $template = trans($key, [], $from);
                if (! is_string($template) || ! str_contains($template, ':'.$name)) {
                    continue;
                }
                $regex = '/^'.str_replace(preg_quote(':'.$name, '/'), '('.$pattern.')', preg_quote($template, '/')).'$/u';
                if (preg_match($regex, trim($title)) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  int|null  $number  the session's place in its cohort's schedule;
     *                            when given it replaces the number in a
     *                            generated "Session N" title.
     */
    public static function localize(?string $title, ?int $number = null, ?string $locale = null): ?string
    {
        if ($title === null || $title === '') {
            return $title;
        }
        $locale ??= app()->getLocale();

        foreach (self::GENERATED as $key => [$name, $pattern]) {
            foreach (self::LOCALES as $from) {
                $template = trans($key, [], $from);
                if (! is_string($template) || ! str_contains($template, ':'.$name)) {
                    continue;
                }
                $regex = '/^'.str_replace(preg_quote(':'.$name, '/'), '('.$pattern.')', preg_quote($template, '/')).'$/u';
                if (preg_match($regex, trim($title), $m) !== 1) {
                    continue;
                }
                $value = $name === 'n' && $number !== null ? (string) $number : $m[1];
                // A generated date reads in the reader's language ("14 October 2026" / "14 أكتوبر 2026").
                if ($name === 'date') {
                    try {
                        $value = \Illuminate\Support\Carbon::parse($value)->locale($locale)->translatedFormat('j F Y');
                    } catch (\Throwable) {
                        // An unparsable date stays as written.
                    }
                }

                return trans($key, [$name => $value], $locale);
            }
        }

        return $title;
    }
}
