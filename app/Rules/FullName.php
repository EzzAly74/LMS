<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A person's full name on a Dashboard account (NEW2B-6161): at least two
 * names, each at least three letters long, and at most 100 characters in all.
 * Counts characters, not bytes, so Arabic names follow the same rule.
 */
class FullName implements ValidationRule
{
    public const MAX = 100;

    public const MIN_PARTS = 2;

    public const MIN_PART_LENGTH = 3;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $name = trim((string) preg_replace('/\s+/u', ' ', $value));

        if (mb_strlen($name) > self::MAX) {
            $fail('validation.full_name.max')->translate(['max' => self::MAX]);

            return;
        }

        $parts = $name === '' ? [] : explode(' ', $name);
        if (count($parts) < self::MIN_PARTS) {
            $fail('validation.full_name.parts')->translate(['min' => self::MIN_PARTS]);

            return;
        }

        foreach ($parts as $part) {
            if (mb_strlen($part) < self::MIN_PART_LENGTH) {
                $fail('validation.full_name.part_length')->translate(['min' => self::MIN_PART_LENGTH]);

                return;
            }
        }
    }
}
