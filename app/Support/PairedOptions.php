<?php

namespace App\Support;

use Illuminate\Validation\Validator;

/**
 * MCQ and reorder options are pairs: option N in English is option N in
 * Arabic (NEW2B-6288), and an MCQ's correct answer is one option, at the
 * same position in both languages (NEW2B-6289). Shared by the quiz and
 * assignment requests, which store questions the same way.
 */
final class PairedOptions
{
    /** @param array<string,mixed> $question */
    public static function check(Validator $validator, int|string $index, array $question): void
    {
        $type = $question['type'] ?? null;
        if (! in_array($type, ['mcq', 'reorder'], true)) {
            return;
        }

        $en = $question['options_en'] ?? null;
        $ar = $question['options_ar'] ?? null;
        if (! is_array($en) || ! is_array($ar)) {
            return;
        }

        if (count($en) !== count($ar)) {
            $validator->errors()->add("questions.$index.options_ar", __('messages.options_count_mismatch'));

            return;
        }

        // A missing key is reported by the request's own required rule.
        if ($type !== 'mcq' || empty($question['correct_answer_en']) || empty($question['correct_answer_ar'])) {
            return;
        }

        $atEn = array_search($question['correct_answer_en'] ?? null, $en, true);
        $atAr = array_search($question['correct_answer_ar'] ?? null, $ar, true);
        if ($atEn === false || $atAr === false) {
            $validator->errors()->add('questions.'.$index.'.correct_answer_'.($atEn === false ? 'en' : 'ar'), __('messages.mcq_key_not_an_option'));
        } elseif ($atEn !== $atAr) {
            $validator->errors()->add("questions.$index.correct_answer_ar", __('messages.mcq_key_not_same_option'));
        }
    }
}
