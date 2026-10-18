<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A "Generate with AI" run as the form's dialog reads it. `questions` are in
 * the quiz / assignment question shape, ready to add to the form; never the
 * source text or stored file paths.
 */
class AiGenerationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'target'        => $this->target,
            // Queued and running are the same to the dialog: not finished yet.
            'status'        => $this->isRunning() ? 'running' : $this->status,
            'error'         => $this->error,
            'error_message' => $this->error ? __('ai_questions.errors.'.$this->error) : null,
            'counts'        => (object) $this->counts(),
            'sources'       => $this->source_names,
            'questions'     => $this->status === 'succeeded' ? ($this->questions ?? []) : [],
            'created_at'    => $this->created_at?->toIso8601String(),
            'finished_at'   => $this->finished_at?->toIso8601String(),
        ];
    }
}
