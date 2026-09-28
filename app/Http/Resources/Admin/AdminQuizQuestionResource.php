<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminQuizQuestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'position'          => (int) $this->position,
            'type'              => $this->type,
            'score'             => (int) $this->score,
            'question_en'       => $this->question_en ?? $this->legacyText('en'),
            'question_ar'       => $this->question_ar ?? $this->legacyText('ar'),
            'options_en'        => $this->options_en ?: $this->legacyOptions('en'),
            'options_ar'        => $this->options_ar ?: $this->legacyOptions('ar'),
            'correct_answer_en' => $this->correct_answer_en ?? $this->legacyKey('en'),
            'correct_answer_ar' => $this->correct_answer_ar ?? $this->legacyKey('ar'),
            'explanation_en'    => $this->explanation_en,
            'explanation_ar'    => $this->explanation_ar,
        ];
    }

    /*
     * B-136: questions written by the legacy exam flow keep their text in the
     * translatable `question` column and their options in
     * course_exam_question_answers (`is_correct` marks the key); the rich
     * columns are null. The edit form showed them blank, so saving forced the
     * admin to retype every question. The first save writes the rich columns.
     */
    private function legacyText(string $locale): ?string
    {
        $text = (string) $this->getTranslation('question', $locale, false);

        return $text === '' ? null : $text;
    }

    /** @return list<string> */
    private function legacyOptions(string $locale): array
    {
        if (! $this->relationLoaded('answers')) {
            return [];
        }

        return $this->answers
            ->map(fn ($a) => (string) $a->getTranslation('answer', $locale, false))
            ->filter(fn (string $v) => $v !== '')
            ->values()
            ->all();
    }

    private function legacyKey(string $locale): ?string
    {
        if (! $this->relationLoaded('answers')) {
            return null;
        }

        $key = $this->answers->first(fn ($a) => (bool) $a->is_correct);
        $text = $key ? (string) $key->getTranslation('answer', $locale, false) : '';

        return $text === '' ? null : $text;
    }
}
