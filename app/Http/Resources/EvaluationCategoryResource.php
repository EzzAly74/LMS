<?php

namespace App\Http\Resources;

use App\Models\Evaluation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An evaluation template as the learner's form (GET courses/{course}/evaluate).
 * `questions` carries what the Website needs to draw each one (Figma
 * 2194:78325): the type, the localized title and scale end labels, whether
 * it is required, and the scale's top value (null for free text).
 */
class EvaluationCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $locale = app()->getLocale();

        return [
            'id'         => $this->id,
            'name'       => $this->getTranslation('name', $locale),
            'created_at' => $this->created_at?->format('Y-m-d'),
            'questions'  => $this->whenLoaded('evaluations', fn () => $this->evaluations->map(fn (Evaluation $q) => [
                'id'              => (int) $q->id,
                'type'            => (string) $q->type,
                'title'           => $q->getTranslation('title', $locale),
                'is_required'     => (bool) $q->is_required,
                'scale_max'       => Evaluation::SCALE_MAX[$q->type] ?? null,
                'scale_label_min' => $q->getTranslation('scale_label_min', $locale) ?: null,
                'scale_label_max' => $q->getTranslation('scale_label_max', $locale) ?: null,
            ])->values()),
        ];
    }
}
