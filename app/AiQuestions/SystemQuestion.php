<?php

namespace App\AiQuestions;

use Illuminate\Support\Facades\Validator;

/**
 * AI output -> the platform's own question shape (course_exam_questions /
 * course_assignment_questions, as the Add / Edit Quiz and Assignment forms
 * send them):
 *
 *   { type, score, question_en, question_ar, options_en, options_ar,
 *     correct_answer_en, correct_answer_ar, explanation_en, explanation_ar }
 *
 *  - mcq:     2-6 paired options; the key is the same option in both
 *             languages (PairedOptions, NEW2B-6289);
 *  - yes_no:  the key is Yes / No and نعم / لا (the form's yesNoText);
 *  - open:    no options, no key; the model answer goes into the
 *             explanation, the only field the form keeps for open questions;
 *  - reorder: 3-8 paired items in the correct order; the key is that order
 *             as JSON (the form's keyFields).
 *
 * The limits are the FormRequests' (AdminQuizStoreRequest,
 * AdminAssignmentStoreRequest), so whatever is added to the form also saves.
 * An item that does not fit is dropped, never repaired: a wrong key is worse
 * than a missing question.
 */
final class SystemQuestion
{
    public const MCQ = 'mcq';
    public const YES_NO = 'yes_no';
    public const OPEN = 'open';
    public const REORDER = 'reorder';

    /** What the AI can write. Assignments' `file` type needs a learner upload, so it is never generated. */
    public const TYPES = [self::MCQ, self::YES_NO, self::OPEN, self::REORDER];

    public const BLOOM = ['remember', 'understand', 'apply', 'analyze', 'evaluate', 'create'];
    public const DIFFICULTIES = ['easy', 'medium', 'hard'];

    private const YES = ['en' => 'Yes', 'ar' => 'نعم'];
    private const NO = ['en' => 'No', 'ar' => 'لا'];
    private const MODEL_ANSWER = ['en' => 'Expected answer: ', 'ar' => 'الإجابة المتوقعة: '];

    /**
     * @param  array<string, int>  $counts  type => wanted
     * @param  list<array<string, mixed>>  $raw  provider output
     * @return list<array<string, mixed>>  in the order of $counts, then as written
     */
    public static function normalize(array $counts, array $raw, int $score): array
    {
        $byType = [];
        $seen = [];
        foreach ($raw as $item) {
            $type = is_array($item) && is_string($item['type'] ?? null) ? $item['type'] : null;
            if ($type === null || ! isset($counts[$type]) || count($byType[$type] ?? []) >= $counts[$type]) {
                continue;
            }
            $question = self::one($type, $item, $score);
            if ($question === null) {
                continue;
            }
            $key = mb_strtolower(preg_replace('/\s+/u', ' ', $question['question_en']) ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $byType[$type][] = $question;
        }

        $out = [];
        foreach (array_keys($counts) as $type) {
            array_push($out, ...($byType[$type] ?? []));
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private static function one(string $type, array $item, int $score): ?array
    {
        $paired = in_array($type, [self::MCQ, self::REORDER], true);
        [$min, $max] = $type === self::MCQ ? [2, 6] : [3, 8];

        $rules = [
            'question_en'    => ['required', 'string', 'max:2000'],
            'question_ar'    => ['required', 'string', 'max:2000'],
            'explanation_en' => ['nullable', 'string', 'max:4000'],
            'explanation_ar' => ['nullable', 'string', 'max:4000'],
        ];
        if ($paired) {
            foreach (['en', 'ar'] as $lang) {
                $rules["options_$lang"] = ['required', 'array', 'list', "min:$min", "max:$max"];
                $rules["options_$lang.*"] = ['required', 'string', 'max:500', 'distinct:ignore_case'];
            }
        }
        if ($type === self::MCQ) {
            $rules['correct_index'] = ['required', 'integer', 'min:0'];
        }
        if ($type === self::YES_NO) {
            $rules['correct_yes'] = ['required', 'boolean'];
        }
        if ($type === self::OPEN) {
            $rules['model_answer_en'] = ['nullable', 'string', 'max:800'];
            $rules['model_answer_ar'] = ['nullable', 'string', 'max:800'];
        }

        $item = self::trimmed($item);
        $v = Validator::make($item, $rules);
        if ($v->fails()) {
            return null;
        }
        $d = $v->validated();

        $q = [
            'type'              => $type,
            'score'             => $score,
            'question_en'       => $d['question_en'],
            'question_ar'       => $d['question_ar'],
            'options_en'        => [],
            'options_ar'        => [],
            'correct_answer_en' => null,
            'correct_answer_ar' => null,
            'explanation_en'    => $d['explanation_en'] ?? null ?: null,
            'explanation_ar'    => $d['explanation_ar'] ?? null ?: null,
        ];

        if ($paired) {
            if (count($d['options_en']) !== count($d['options_ar'])) {
                return null; // options are pairs: option N in English is option N in Arabic
            }
            $q['options_en'] = array_values($d['options_en']);
            $q['options_ar'] = array_values($d['options_ar']);
        }

        if ($type === self::MCQ) {
            $i = (int) $d['correct_index'];
            if ($i >= count($q['options_en'])) {
                return null;
            }
            $q['correct_answer_en'] = $q['options_en'][$i];
            $q['correct_answer_ar'] = $q['options_ar'][$i];
        } elseif ($type === self::YES_NO) {
            $answer = filter_var($d['correct_yes'], FILTER_VALIDATE_BOOL) ? self::YES : self::NO;
            $q['options_en'] = [self::YES['en'], self::NO['en']];
            $q['options_ar'] = [self::YES['ar'], self::NO['ar']];
            $q['correct_answer_en'] = $answer['en'];
            $q['correct_answer_ar'] = $answer['ar'];
        } elseif ($type === self::REORDER) {
            $q['correct_answer_en'] = json_encode($q['options_en'], JSON_UNESCAPED_UNICODE);
            $q['correct_answer_ar'] = json_encode($q['options_ar'], JSON_UNESCAPED_UNICODE);
        } elseif ($type === self::OPEN) {
            foreach (['en', 'ar'] as $lang) {
                $answer = $d["model_answer_$lang"] ?? null;
                if (filled($answer)) {
                    $why = $q["explanation_$lang"];
                    $q["explanation_$lang"] = mb_substr(self::MODEL_ANSWER[$lang].$answer.($why ? "\n".$why : ''), 0, 5000);
                }
            }
        }

        return $q;
    }

    /** Trim every string, top level and in lists. */
    private static function trimmed(array $item): array
    {
        return array_map(function ($value) {
            if (is_string($value)) {
                return trim($value);
            }
            if (is_array($value)) {
                return array_map(fn ($v) => is_string($v) ? trim($v) : $v, $value);
            }

            return $value;
        }, $item);
    }
}
