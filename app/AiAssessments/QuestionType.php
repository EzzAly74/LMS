<?php

namespace App\AiAssessments;

/**
 * The seven question types of the spec and the one shape each is stored in.
 *
 * Shapes (`options` / `answer` columns):
 *  - mcq, scenario: options = [string, ...] (2-6), answer = {indexes: [i]}
 *                   scenario also needs `context` (the situation).
 *  - multi:         options = [string, ...] (3-8), answer = {indexes: [i, ...]} (1+)
 *  - true_false:    options = null,                answer = {value: bool}
 *  - short:         options = null,                answer = {text: string}   (model answer)
 *  - fill_blank:    prompt holds one `_____` per blank, answer = {answers: [string per blank]}
 *  - matching:      options = [{left, right}, ...] (3-8 pairs, the pairs are the key), answer = null
 *
 * `rules()` is the single validation of that shape. AI output goes through it
 * (QuestionNormalizer) and so does a trainer's edit (the FormRequest), so a
 * question can never be stored in a shape the exports do not understand.
 */
final class QuestionType
{
    public const MCQ = 'mcq';
    public const MULTI = 'multi';
    public const TRUE_FALSE = 'true_false';
    public const SHORT = 'short';
    public const FILL_BLANK = 'fill_blank';
    public const MATCHING = 'matching';
    public const SCENARIO = 'scenario';

    public const ALL = [self::MCQ, self::MULTI, self::TRUE_FALSE, self::SHORT, self::FILL_BLANK, self::MATCHING, self::SCENARIO];

    public const BLOOM = ['remember', 'understand', 'apply', 'analyze', 'evaluate', 'create'];

    public const DIFFICULTIES = ['easy', 'medium', 'hard'];

    /** A blank in a fill-in-the-blank prompt, as stored. */
    public const BLANK = '_____';

    /** Text limits shared by the AI output and the editor. */
    private const PROMPT_MAX = 2000;
    private const OPTION_MAX = 500;
    private const LONG_MAX = 4000;

    /** @return array{0:int,1:int}|null option count bounds, null when the type has no option list */
    public static function optionBounds(string $type): ?array
    {
        return match ($type) {
            self::MCQ, self::SCENARIO => [2, 6],
            self::MULTI => [3, 8],
            self::MATCHING => [3, 8],
            default => null,
        };
    }

    public static function usesIndexes(string $type): bool
    {
        return in_array($type, [self::MCQ, self::MULTI, self::SCENARIO], true);
    }

    /**
     * Laravel validation rules for one question of `$type`, keys relative to
     * `$prefix` ('' or 'questions.0.').
     *
     * @return array<string, mixed>
     */
    public static function rules(string $type, string $prefix = ''): array
    {
        $p = $prefix;
        $rules = [
            $p.'prompt'      => ['required', 'string', 'max:'.self::PROMPT_MAX],
            $p.'explanation' => ['nullable', 'string', 'max:'.self::LONG_MAX],
            $p.'source_ref'  => ['nullable', 'string', 'max:255'],
            $p.'bloom'       => ['nullable', 'string', 'in:'.implode(',', self::BLOOM)],
            $p.'difficulty'  => ['nullable', 'string', 'in:'.implode(',', self::DIFFICULTIES)],
            $p.'context'     => $type === self::SCENARIO
                ? ['required', 'string', 'max:'.self::LONG_MAX]
                : ['prohibited'],
        ];

        $bounds = self::optionBounds($type);

        if ($type === self::MATCHING) {
            $rules[$p.'options'] = ['required', 'array', 'min:'.$bounds[0], 'max:'.$bounds[1]];
            $rules[$p.'options.*'] = ['required', 'array:left,right'];
            $rules[$p.'options.*.left'] = ['required', 'string', 'max:'.self::OPTION_MAX];
            $rules[$p.'options.*.right'] = ['required', 'string', 'max:'.self::OPTION_MAX];
            $rules[$p.'answer'] = ['prohibited'];
        } elseif ($bounds !== null) {
            $rules[$p.'options'] = ['required', 'array', 'list', 'min:'.$bounds[0], 'max:'.$bounds[1]];
            $rules[$p.'options.*'] = ['required', 'string', 'distinct:ignore_case', 'max:'.self::OPTION_MAX];
            $rules[$p.'answer'] = ['required', 'array:indexes'];
            $rules[$p.'answer.indexes'] = ['required', 'array', 'list', 'min:1', $type === self::MULTI ? 'max:8' : 'max:1'];
            $rules[$p.'answer.indexes.*'] = ['required', 'integer', 'min:0', 'distinct'];
        } else {
            $rules[$p.'options'] = ['prohibited'];
            $rules[$p.'answer'] = ['required', 'array'];
            if ($type === self::TRUE_FALSE) {
                $rules[$p.'answer.value'] = ['required', 'boolean'];
            } elseif ($type === self::SHORT) {
                $rules[$p.'answer.text'] = ['required', 'string', 'max:'.self::LONG_MAX];
            } elseif ($type === self::FILL_BLANK) {
                $rules[$p.'answer.answers'] = ['required', 'array', 'list', 'min:1', 'max:10'];
                $rules[$p.'answer.answers.*'] = ['required', 'string', 'max:'.self::OPTION_MAX];
            }
        }

        return $rules;
    }

    /**
     * The checks a rule array cannot express: every correct index points at
     * an option, and a fill-in-the-blank has one answer per blank.
     *
     * @param  array<string, mixed>  $q  a question already passing rules()
     * @return string|null  the problem, or null
     */
    public static function consistencyError(string $type, array $q): ?string
    {
        if (self::usesIndexes($type)) {
            foreach ($q['answer']['indexes'] as $index) {
                if ($index >= count($q['options'])) {
                    return 'answer.indexes';
                }
            }
        }

        if ($type === self::FILL_BLANK) {
            $blanks = substr_count(self::normaliseBlanks((string) $q['prompt']), self::BLANK);
            if ($blanks === 0 || $blanks !== count($q['answer']['answers'])) {
                return 'answer.answers';
            }
        }

        return null;
    }

    /** Any run of 3+ underscores (or a [blank] marker) becomes the stored blank. */
    public static function normaliseBlanks(string $prompt): string
    {
        return (string) preg_replace('/_{3,}|\[blank\]/iu', self::BLANK, $prompt);
    }

    /**
     * Keep only the fields a type stores, in their stored form.
     *
     * @param  array<string, mixed>  $q
     * @return array<string, mixed>
     */
    public static function clean(string $type, array $q): array
    {
        $clean = [
            'type'        => $type,
            'context'     => $type === self::SCENARIO ? trim((string) $q['context']) : null,
            'prompt'      => trim($type === self::FILL_BLANK ? self::normaliseBlanks((string) $q['prompt']) : (string) $q['prompt']),
            'explanation' => self::nullableText($q['explanation'] ?? null),
            'source_ref'  => self::nullableText($q['source_ref'] ?? null),
            'bloom'       => $q['bloom'] ?? null,
            'difficulty'  => $q['difficulty'] ?? null,
            'options'     => null,
            'answer'      => null,
        ];

        if ($type === self::MATCHING) {
            $clean['options'] = array_map(
                fn (array $pair) => ['left' => trim($pair['left']), 'right' => trim($pair['right'])],
                array_values($q['options']),
            );
        } elseif (self::usesIndexes($type)) {
            $clean['options'] = array_map(fn ($o) => trim((string) $o), array_values($q['options']));
            $indexes = array_map('intval', $q['answer']['indexes']);
            sort($indexes);
            $clean['answer'] = ['indexes' => $indexes];
        } else {
            $clean['answer'] = match ($type) {
                self::TRUE_FALSE => ['value' => (bool) $q['answer']['value']],
                self::SHORT => ['text' => trim((string) $q['answer']['text'])],
                self::FILL_BLANK => ['answers' => array_map(fn ($a) => trim((string) $a), array_values($q['answer']['answers']))],
                default => null,
            };
        }

        return $clean;
    }

    private static function nullableText(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
