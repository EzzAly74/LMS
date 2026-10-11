<?php

declare(strict_types=1);

namespace App\Support\Mail;

use Illuminate\Support\HtmlString;

/**
 * Right-to-left helpers for the HTML emails (NEW2B-5899).
 *
 * Gmail and most webmail drop `dir` on <html> / <body>, so an Arabic email
 * fell back to left-to-right: colons on the wrong side, "2B" split around its
 * label, the sign-off reordered. The templates now set the direction on every
 * cell, and each value the user typed (a Latin name in an Arabic sentence, an
 * email, a phone number) is isolated so it cannot reorder the text around it.
 */
final class Bidi
{
    /** The value, escaped, in its own direction: a span with dir plus Unicode first-strong isolates. */
    public static function isolate(?string $value): HtmlString
    {
        $value = (string) $value;
        $dir = preg_match('/\p{Arabic}/u', $value) === 1 ? 'rtl' : 'ltr';

        return new HtmlString('<span dir="'.$dir.'">'."\u{2068}".e($value)."\u{2069}".'</span>');
    }

    /** dir / align / font for an email in this locale. */
    public static function forLocale(string $locale): array
    {
        $ar = $locale === 'ar';

        return [
            'ar'    => $ar,
            'dir'   => $ar ? 'rtl' : 'ltr',
            'align' => $ar ? 'right' : 'left',
            'font'  => $ar ? 'Tahoma,Arial,Helvetica,sans-serif' : 'Arial,Helvetica,sans-serif',
        ];
    }
}
