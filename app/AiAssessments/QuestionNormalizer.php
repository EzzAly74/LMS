<?php

namespace App\AiAssessments;

use Illuminate\Support\Facades\Validator;

/**
 * Turns raw provider output into stored questions. Anything that does not fit
 * the type's shape (QuestionType::rules + consistencyError) is dropped, never
 * repaired by guessing: a wrong answer key is worse than a missing question.
 */
final class QuestionNormalizer
{
    /**
     * A one-call answer holding several types: each item is checked against
     * the shape of the `type` it declares, at most `$counts[type]` are kept per
     * type, and the result is ordered by type as configured (QuestionType
     * order), then as the model wrote them. Items of a type that was not asked
     * for are dropped.
     *
     * @param  array<string, int>  $counts
     * @param  list<array<string, mixed>>  $raw
     * @return list<array<string, mixed>>
     */
    public function normalizeMixed(array $counts, array $raw): array
    {
        $byType = [];
        foreach ($raw as $item) {
            $type = is_string($item['type'] ?? null) ? $item['type'] : null;
            if ($type !== null && isset($counts[$type])) {
                $byType[$type][] = $item;
            }
        }

        $out = [];
        $seen = [];
        foreach ($counts as $type => $count) {
            foreach ($this->normalize($type, $byType[$type] ?? [], $count) as $q) {
                // Also no duplicate prompt across types.
                $key = mb_strtolower(preg_replace('/\s+/u', ' ', $q['prompt']) ?? '');
                if (! isset($seen[$key])) {
                    $seen[$key] = true;
                    $out[] = $q;
                }
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $raw
     * @return list<array<string, mixed>>  QuestionType::clean() shapes
     */
    public function normalize(string $type, array $raw, int $limit): array
    {
        $out = [];
        $seen = [];

        foreach ($raw as $item) {
            if (count($out) >= $limit) {
                break;
            }

            $candidate = $this->map($type, $item);
            $validator = Validator::make($candidate, QuestionType::rules($type));
            if ($validator->fails() || QuestionType::consistencyError($type, $candidate) !== null) {
                continue;
            }

            $clean = QuestionType::clean($type, $validator->validated() + ['context' => $candidate['context'] ?? null]);
            $key = mb_strtolower(preg_replace('/\s+/u', ' ', $clean['prompt']) ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $clean;
        }

        return $out;
    }

    /**
     * Provider field names to the stored ones.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function map(string $type, array $item): array
    {
        $q = [
            'prompt'      => $item['prompt'] ?? null,
            'explanation' => $item['explanation'] ?? null,
            'source_ref'  => is_string($item['source_ref'] ?? null) ? mb_substr($item['source_ref'], 0, 255) : null,
            'bloom'       => in_array($item['bloom'] ?? null, QuestionType::BLOOM, true) ? $item['bloom'] : null,
            'difficulty'  => in_array($item['difficulty'] ?? null, QuestionType::DIFFICULTIES, true) ? $item['difficulty'] : null,
        ];

        if ($type === QuestionType::SCENARIO) {
            $q['context'] = $item['context'] ?? null;
        }

        if (QuestionType::usesIndexes($type)) {
            $q['options'] = $item['options'] ?? null;
            $q['answer'] = ['indexes' => $item['correct_indexes'] ?? null];
        }

        if ($type === QuestionType::TRUE_FALSE) {
            $q['answer'] = ['value' => $item['correct'] ?? null];
        } elseif ($type === QuestionType::SHORT) {
            $q['answer'] = ['text' => $item['model_answer'] ?? null];
        } elseif ($type === QuestionType::FILL_BLANK) {
            $q['prompt'] = is_string($q['prompt']) ? QuestionType::normaliseBlanks($q['prompt']) : null;
            $q['answer'] = ['answers' => $item['blank_answers'] ?? null];
        } elseif ($type === QuestionType::MATCHING) {
            $q['options'] = $item['pairs'] ?? null;
        }

        return $q;
    }
}
