<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'name'         => $this->getTranslation('name', app()->getLocale()),
            // Each language as stored, no fallback: the Dashboard edit form
            // fills its EN and AR fields from these (NEW2B-6104).
            'name_en'      => $this->getTranslation('name', 'en', false) ?: null,
            'name_ar'      => $this->getTranslation('name', 'ar', false) ?: null,
            'logo'         => $this->logo ? $this->getFileUrl($this->logo) : null,
            'active'       => (bool) $this->active,
            'courses_count'=> $this->whenCounted('courses'),
            'created_at'   => $this->created_at?->format('Y-m-d'),
        ];
    }
}
