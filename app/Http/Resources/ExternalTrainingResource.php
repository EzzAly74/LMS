<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An external training request as its learner sees it (Website profile card,
 * form, completed list). The certificate is a route, never a storage path.
 */
class ExternalTrainingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $locale = app()->getLocale();

        return [
            'id'               => $this->id,
            'title'            => $this->title,
            'provider'         => $this->provider,
            'start_date'       => $this->start_date?->toDateString(),
            'end_date'         => $this->end_date?->toDateString(),
            'hours'            => (float) $this->hours,
            'cost'             => $this->cost !== null ? (float) $this->cost : null,
            'currency'         => $this->currency,
            'status'           => $this->status,
            'rejection_reason' => $this->rejection_reason,
            'qualification'    => $this->qualification
                ? ['id' => $this->qualification->id, 'name' => $this->qualification->getTranslation('name', $locale)]
                : null,
            'course'           => $this->course
                ? ['id' => $this->course->id, 'title' => $this->course->getTranslation('title', $locale)]
                : null,
            'certificate'      => [
                'name' => $this->certificate_name,
                'mime' => $this->certificate_mime,
                'size' => $this->certificate_size,
            ],
            'submitted_at'     => $this->created_at?->toDateTimeString(),
            'decided_at'       => $this->decided_at?->toDateTimeString(),
        ];
    }
}
