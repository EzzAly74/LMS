<?php

namespace App\Http\Resources\Admin\AiAssessments;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A library row. Relies on the counts / course selected by the index query (no N+1). */
class AiAssessmentListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'title'            => $this->title,
            'type'             => $this->type,
            'content_language' => $this->content_language,
            'difficulty'       => $this->difficulty,
            'status'           => $this->status,
            'version'          => $this->version,
            'questions_count'  => (int) ($this->questions_count ?? 0),
            'approved_count'   => (int) ($this->approved_count ?? 0),
            'course'           => $this->whenLoaded('course', fn () => $this->course ? [
                'id' => $this->course->id, 'title' => $this->course->title,
            ] : null),
            'author'           => $this->whenLoaded('admin', fn () => $this->admin ? [
                'id' => $this->admin->id, 'name' => $this->admin->name,
            ] : null),
            'updated_at'       => $this->updated_at?->toIso8601String(),
            'created_at'       => $this->created_at?->toIso8601String(),
        ];
    }
}
