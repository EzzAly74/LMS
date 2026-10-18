<?php

namespace App\AiAssessments\Export;

use App\AiAssessments\QuestionType;
use App\Models\AiAssessment;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * What an export prints, independent of the format: the three writers only
 * lay this out, so Word, PDF and Excel can never disagree on an answer key.
 *
 * Labels come from lang/{content_language}/ai_assessments.php: an Arabic
 * assessment exports in Arabic even when the trainer's UI is English.
 *
 * Matching: the right column is shuffled (seeded by the question id, so the
 * same question always prints the same order) and lettered; the key maps each
 * number to its letter.
 */
final class ExportDocument
{
    public readonly bool $rtl;
    public readonly string $title;
    public readonly string $subtitle;
    public readonly ?string $course;
    /** @var list<array<string, mixed>> */
    public readonly array $items;

    public function __construct(AiAssessment $assessment, public readonly bool $withAnswers)
    {
        $lang = $assessment->content_language;
        $this->rtl = $lang === 'ar';
        $this->title = $assessment->title;
        $this->subtitle = implode(' · ', [
            $this->t('types.'.$assessment->type, $lang),
            $this->t('variants.'.($withAnswers ? 'trainer' : 'learner'), $lang),
            trans('ai_assessments.export.questions_count', ['n' => $assessment->questions->count()], $lang),
        ]);
        $this->course = $assessment->course?->title;

        $letters = trans('ai_assessments.export.option_letters', [], $lang);
        $items = [];
        foreach ($assessment->questions as $i => $q) {
            $items[] = $this->item($q, $i + 1, $letters, $lang);
        }
        $this->items = $items;
    }

    public function label(string $key, string $lang): string
    {
        return $this->t($key, $lang);
    }

    /**
     * @param  list<string>  $letters
     * @return array<string, mixed>
     */
    private function item($q, int $number, array $letters, string $lang): array
    {
        $item = [
            'number'      => $number,
            'type'        => $q->type,
            'type_label'  => $this->t('question_types.'.$q->type, $lang),
            'context'     => $q->context,
            'prompt'      => $q->prompt,
            'hint'        => $q->type === QuestionType::MULTI ? $this->t('select_all', $lang)
                : ($q->type === QuestionType::MATCHING ? $this->t('match_instruction', $lang) : null),
            'options'     => [],      // [[letter, text]]
            'pairs'       => null,    // ['left' => [[n, text]], 'right' => [[letter, text]]]
            'lines'       => 0,       // writing lines for the learner
            'answer'      => '',
            'explanation' => $q->explanation,
            'source'      => $q->source_ref,
            'bloom'       => $q->bloom ? $this->t('blooms.'.$q->bloom, $lang) : null,
            'difficulty'  => $q->difficulty ? $this->t('difficulties.'.$q->difficulty, $lang) : null,
            'status'      => $this->t('statuses.'.$q->status, $lang),
        ];

        $answer = $q->answer ?? [];

        if (QuestionType::usesIndexes($q->type)) {
            foreach (array_values($q->options ?? []) as $i => $text) {
                $item['options'][] = [$letters[$i] ?? (string) ($i + 1), (string) $text];
            }
            $item['answer'] = implode(', ', array_map(
                fn (int $i) => ($letters[$i] ?? $i + 1).') '.($q->options[$i] ?? ''),
                $answer['indexes'] ?? [],
            ));
        } elseif ($q->type === QuestionType::TRUE_FALSE) {
            $item['options'] = [[$letters[0], $this->t('true', $lang)], [$letters[1], $this->t('false', $lang)]];
            $item['answer'] = $this->t(($answer['value'] ?? false) ? 'true' : 'false', $lang);
        } elseif ($q->type === QuestionType::SHORT) {
            $item['lines'] = 3;
            $item['answer'] = (string) ($answer['text'] ?? '');
        } elseif ($q->type === QuestionType::FILL_BLANK) {
            $answers = $answer['answers'] ?? [];
            $item['answer'] = count($answers) === 1
                ? (string) $answers[0]
                : implode('  ', array_map(fn ($a, $i) => ($i + 1).') '.$a, $answers, array_keys($answers)));
        } elseif ($q->type === QuestionType::MATCHING) {
            $pairs = array_values($q->options ?? []);
            // Seeded, local generator: stable per question, global RNG untouched.
            $order = (new Randomizer(new Mt19937((int) $q->id)))->shuffleArray(array_keys($pairs));

            $left = [];
            $right = [];
            $key = [];
            foreach ($pairs as $i => $pair) {
                $left[] = [(string) ($i + 1), $pair['left']];
            }
            foreach ($order as $position => $pairIndex) {
                $right[] = [$letters[$position] ?? (string) ($position + 1), $pairs[$pairIndex]['right']];
                $key[$pairIndex] = $letters[$position] ?? (string) ($position + 1);
            }
            ksort($key);
            $item['pairs'] = ['left' => $left, 'right' => $right];
            $item['answer'] = implode($lang === 'ar' ? '، ' : ', ', array_map(
                fn ($i, $letter) => ($i + 1).'-'.$letter, array_keys($key), $key,
            ));
        }

        return $item;
    }

    private function t(string $key, string $lang): string
    {
        return (string) trans('ai_assessments.export.'.$key, [], $lang);
    }
}
