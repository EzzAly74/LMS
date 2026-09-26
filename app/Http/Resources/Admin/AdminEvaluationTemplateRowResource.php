<?php

namespace App\Http\Resources\Admin;

use App\Services\Admin\AdminEvaluationReportService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the Evaluation Templates list (Figma 2009:88432) and of its
 * "Recently Created" cards.
 *
 * `score` is null for a template nobody has answered ("Unscored"), never 0.
 * The legacy schema has no publish state, course scope or cohort scope for a
 * template (every template is put to every evaluable course), so none is
 * returned rather than one being invented (D-054).
 */
class AdminEvaluationTemplateRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $score = $this->score;

        return [
            'id'                => (int) $this->id,
            'name'              => $this->name,
            'questions'         => (int) $this->questions,
            'submissions'       => (int) $this->submissions,
            'learners_scored'   => (int) $this->learners_scored,
            'learners_eligible' => (int) $this->learners_eligible,
            'score'             => $score,
            'passed'            => app(AdminEvaluationReportService::class)->passed($score),
            'last_scored_at'    => $this->last_scored_at,
            'created_at'        => $this->created_at,
            'updated_at'        => $this->updated_at,
        ];
    }
}
