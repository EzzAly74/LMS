<?php

namespace App\Http\Resources\Admin\AiAssessments;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiAssessmentSourceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'kind'       => $this->kind,
            'name'       => $this->name,
            'size'       => $this->size,
            'units'      => $this->units,
            'chars'      => (int) ($this->chars ?? 0),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
