<?php

namespace App\Http\Resources\Admin\AiAssessments;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The editor's view of one assessment: details, configuration, sources
 * (metadata only: never the stored path, the extracted text or the provider
 * URI) and questions. While a run is generating, only that run's questions
 * are listed, so the editor shows them appearing.
 */
class AiAssessmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $questions = $this->questions;
        if ($this->status === 'generating' && $this->current_run_id) {
            $questions = $questions->where('run_id', $this->current_run_id)->values();
        }

        return [
            'id'                => $this->id,
            'title'             => $this->title,
            'type'              => $this->type,
            'content_language'  => $this->content_language,
            'difficulty'        => $this->difficulty,
            'audience'          => $this->audience,
            'objectives'        => $this->objectives,
            'bloom_levels'      => $this->bloom_levels ?? [],
            'question_counts'   => (object) ($this->question_counts ?? []),
            'version'           => $this->version,
            'status'            => $this->status,
            'last_error'        => $this->last_error,
            'last_error_message' => $this->last_error ? __('ai_assessments.errors.'.$this->last_error) : null,
            'generation_started_at' => $this->generation_started_at?->toIso8601String(),
            'course'            => $this->course ? ['id' => $this->course->id, 'title' => $this->course->title] : null,
            'sources'           => AiAssessmentSourceResource::collection($this->sources),
            'questions'         => AiAssessmentQuestionResource::collection($questions),
            'updated_at'        => $this->updated_at?->toIso8601String(),
        ];
    }
}
