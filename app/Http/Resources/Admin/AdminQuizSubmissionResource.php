<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Paginated submissions row resource for the Figma quiz "Submissions" table:
 * Learner, Course, Instructor, Quiz, Cohort, Submitted, Score, Status.
 */
class AdminQuizSubmissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $quiz         = $this->whenLoaded('exam');
        $course       = $quiz ? optional($this->exam->course) : null;
        $instructor   = $quiz ? $this->exam->creator : null;
        $cohortTitles = $quiz && $this->exam->relationLoaded('cohorts')
            ? $this->exam->cohorts->map(fn ($c) => optional($c->cohort)->name)->filter()->values()
            : collect();

        // A legacy exam keeps its score in user_degree and its full mark on
        // the exam (B-172), as the learner profile reads them.
        $max     = $this->max_score ?: ($quiz ? ($this->exam->total_score ?: $this->exam->degree) : 0) ?: 0;
        $awarded = $this->submission_status !== null ? $this->total_score : $this->user_degree;
        // B-134: projected by paginateSubmissions; absent elsewhere, then none pending.
        $pendingAnswers = (int) ($this->pending_answers_count ?? 0);
        $percent = ($max > 0 && $awarded !== null) ? (int) round(($awarded / $max) * 100) : null;

        return [
            'id'              => $this->id,
            'quiz'            => $this->whenLoaded('exam', fn () => [
                'id'        => $this->exam->id,
                'title'     => $this->exam->title,
                'course_id' => $this->exam->course_id,
                'type'      => $this->exam->type,
            ]),
            // Figma 1983:42584 Type column.
            'quiz_type'       => $quiz ? $this->exam->type : null,
            'quiz_title'      => $quiz ? $this->exam->title : null,
            'course_title'    => $course ? $course->title : null,
            'instructor_name' => $instructor?->getLocalizedName() ?: null,
            'cohort_titles'   => $cohortTitles,
            // The submitting learner's own cohort in the course (Course Details tab).
            'learner_cohort'  => $this->resource->getAttribute('learner_cohort'),
            'user'            => $this->whenLoaded('user', fn () => [
                'id'              => $this->user->id,
                'name'            => $this->user->getLocalizedName(),
                'machine_code'    => $this->user->machine_code ?? null,
                'department_name' => $this->user->department_name ?? null,
            ]),
            'total_score'     => $awarded !== null ? (int) $awarded : null,
            'max_score'       => (int) $max,
            'score_percent'   => $percent,
            // QuizVerdict (D-087): the quiz's pass score, else half the full
            // mark; null while an answer still waits for a person.
            'passed'          => $quiz && $awarded !== null && $pendingAnswers === 0
                ? \App\Support\QuizVerdict::passes((float) $awarded, $this->exam->pass_score !== null ? (float) $this->exam->pass_score : null, (float) $max)
                : null,
            'attempts'        => (int) ($this->attempts_count ?? 1),
            'status'          => $awarded !== null && $pendingAnswers === 0 ? 'graded' : 'pending',
            'submitted_at'    => $this->submitted_at?->format('Y-m-d H:i:s'),
            'reviewed_at'     => $this->reviewed_at?->format('Y-m-d H:i:s'),
            'created_at'      => $this->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
