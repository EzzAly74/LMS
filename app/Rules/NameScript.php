<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The English name has no Arabic letters and the Arabic name has Arabic
 * letters, so a name typed into the other language's field is caught when it
 * is saved instead of showing in the wrong language everywhere (2026-10-14:
 * an account had its English and Arabic names swapped).
 */
class NameScript implements ValidationRule
{
    public const ENGLISH = 'en';

    public const ARABIC = 'ar';

    public function __construct(private readonly string $language) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $hasArabic = preg_match('/\p{Arabic}/u', $value) === 1;

        if ($this->language === self::ENGLISH && $hasArabic) {
            $fail('validation.name_script.en')->translate();
        } elseif ($this->language === self::ARABIC && ! $hasArabic) {
            $fail('validation.name_script.ar')->translate();
        }
    }
}
