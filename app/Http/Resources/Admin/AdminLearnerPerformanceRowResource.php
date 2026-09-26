<?php

namespace App\Http\Resources\Admin;

use App\Support\LocalizedJson;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of "Quizzes / Assignments Performance" (Figma 2181:115043):
 * name + type, Course, Type (Pre/Post/Mid), Grades, Status
 * (Pass / Failed / Needs Review), Last Updated.
 */
class AdminLearnerPerformanceRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $score = $this->score !== null ? (float) $this->score : null;
        $max   = $this->max_score !== null ? (float) $this->max_score : null;

        return [
            'id'           => (int) $this->id,
            'kind'         => $this->kind,          // quiz | assignment
            // Quiz titles and course titles are translatable JSON; assignment
            // titles are plain text. pick() handles both.
            'name'         => LocalizedJson::pick($this->name),
            'course'       => LocalizedJson::pick($this->course_title),
            'type'         => $this->type,          // pre | mid | post | assignment
            'score'        => $score,
            'max_score'    => $max,
            // "100/105" in the design; null when not yet graded so the UI can
            // show the "Needs Review" state rather than a fabricated 0.
            'grade_label'  => $score !== null && $max !== null ? "{$score}/{$max}" : null,
            'status'       => $this->normaliseStatus(),
            'last_updated' => $this->last_updated,
        ];
    }

    /**
     * Collapse the two source vocabularies onto the three the design shows.
     *
     * user_exams carries passed/failed/completed; user_course_assignments has
     * no status column at all and is graded/ungraded by whether `score` is set.
     */
    private function normaliseStatus(): string
    {
        $raw = strtolower((string) ($this->status ?? ''));

        if ($this->score === null) {
            return 'needs_review';
        }

        return match ($raw) {
            'passed', 'completed', 'success', 'graded' => 'pass',
            'failed', 'fail'                           => 'failed',
            default                                    => 'needs_review',
        };
    }
}
