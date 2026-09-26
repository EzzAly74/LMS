<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the Dashboard Qualifications list (Figma 2066:100159). The
 * figures are attached by AdminQualificationService::list(); see there for
 * what each one counts (D-056).
 */
class AdminQualificationRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'name'               => $this->getTranslation('name', app()->getLocale()),
            'courses_count'      => (int) ($this->courses_count ?? 0),
            'enrolled_count'     => (int) ($this->enrolled_count ?? 0),
            'job_titles_count'   => (int) ($this->job_titles_count ?? 0),
            'learners_count'     => (int) ($this->learners_count ?? 0),
            'certified_count'    => (int) ($this->certified_count ?? 0),
            'completion_percent' => $this->completion_percent,
            'created_at'         => $this->created_at?->toDateTimeString(),
        ];
    }
}
