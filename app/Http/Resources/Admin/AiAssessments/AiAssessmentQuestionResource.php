<?php

namespace App\Http\Resources\Admin\AiAssessments;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiAssessmentQuestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'position'    => $this->position,
            'type'        => $this->type,
            'context'     => $this->context,
            'prompt'      => $this->prompt,
            'options'     => $this->options,
            'answer'      => $this->answer,
            'explanation' => $this->explanation,
            'source_ref'  => $this->source_ref,
            'bloom'       => $this->bloom,
            'difficulty'  => $this->difficulty,
            'status'      => $this->status,
        ];
    }
}
