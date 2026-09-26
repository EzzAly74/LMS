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
 * A template is live as soon as it is published from the builder (Figma draws
 * no draft state), so there is no status field.
 */
class AdminEvaluationTemplateRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $score = $this->score;

        return [
            'id'                => (int) $this->id,
            'name'              => $this->name,
            // null = all evaluable courses / all cohorts (D-054).
            'course'            => $this->course_id !== null ? ['id' => (int) $this->course_id, 'name' => $this->course_name] : null,
            'cohort'            => $this->section_id !== null ? ['id' => (int) $this->section_id, 'name' => $this->section_name] : null,
            // Read-only once answered (decided 2026-09-26).
            'locked'            => $this->has_responses !== null,
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
