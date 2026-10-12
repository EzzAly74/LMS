<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CourseLectureResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'course_id'          => $this->course_id,
            'section_id'         => $this->section_id,

            // Localized strings — frontend already handles bilingual objects,
            // so we always emit the *full* JSON (en + ar) for both editor and
            // display contexts. This way the edit dialog can prefill every
            // language without a second round-trip.
            'title'              => $this->getTranslations('title'),
            'instructions'       => $this->getTranslations('instructions'),

            'content_type'       => $this->content_type ?? 'video',
            'duration_minutes'   => $this->duration_minutes,

            'type'               => $this->type,
            // `video` holds a URL (link) or a stored file path (video/document);
            // it is null for article modules. Rich-text article bodies live in
            // the dedicated `content` field below.
            'video'              => $this->video,
            'content'            => $this->content,
            'file_name'          => $this->file_name,
            // Public URL when the lecture stores a real file; null otherwise.
            'file_url'           => $this->type === 'file' && $this->video
                ? $this->getFileUrl($this->video)
                : null,
            // The optional English article text and document file; the
            // fields above are the Arabic (main) version.
            'content_en'         => $this->content_en,
            'video_en'           => $this->video_en,
            'file_name_en'       => $this->file_name_en,
            'file_url_en'        => $this->type === 'file' && $this->video_en
                ? $this->getFileUrl($this->video_en)
                : null,

            'require_completion' => (bool) ($this->require_completion ?? false),

            'created_at'         => $this->created_at?->format('Y-m-d'),
            'updated_at'         => $this->updated_at?->format('Y-m-d'),
        ];
    }
}
