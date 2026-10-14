<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Paginated submissions row resource for the Figma "Submissions" table:
 * Learner, Course, Instructor, Assignment, Cohort, Submitted, Score, Status.
 *
 * The detail screen consumes additional question/answer data which is loaded
 * through `AdminAssignmentSubmissionDetailResource`.
 */
class AdminAssignmentSubmissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $assignment   = $this->whenLoaded('assignment');
        $course       = $assignment ? optional($this->assignment->course) : null;
        // The course's instructors, who teach and grade (NEW2B-6291).
        $instructorNames = \App\Support\CourseInstructors::names($assignment ? $this->assignment->course : null);
        $cohortTitles = $assignment && $this->assignment->relationLoaded('cohorts')
            ? $this->assignment->cohorts->map(fn ($c) => optional($c->cohort)->name)->filter()->values()
            : collect();

        // A file-only (legacy) submission is graded into `score` (B-172).
        $max     = $this->max_score ?: ($assignment ? (int) $this->assignment->total_score : 0);
        $awarded = $this->total_score ?? $this->score;
        // Projected by paginateSubmissions; callers without it fall back to
        // the loaded answers, then to "none pending" (the old behaviour).
        $pendingAnswers = (int) ($this->pending_answers_count
            ?? ($this->relationLoaded('answers') ? $this->answers->whereNull('awarded_score')->count() : 0));
        $percent = ($max > 0 && $awarded !== null) ? (int) round(($awarded / $max) * 100) : null;

        return [
            'id'               => $this->id,
            'assignment'       => $this->whenLoaded('assignment', fn () => [
                'id'        => $this->assignment->id,
                'title'     => $this->assignment->title,
                'course_id' => $this->assignment->course_id,
            ]),
            // In the request locale; assignments store EN and AR as two columns.
            'assignment_title' => $assignment
                ? (app()->getLocale() === 'ar'
                    ? ($this->assignment->title_ar ?: $this->assignment->title)
                    : ($this->assignment->title ?: $this->assignment->title_ar))
                : null,
            // Pre / Mid / Post (D-065): the Type column of the list (Figma 1983:42584).
            'assignment_type'  => $assignment ? $this->assignment->type : null,
            'course_title'     => $course ? $course->title : null,
            'instructor_name'  => $instructorNames,
            'cohort_titles'    => $cohortTitles,
            // The submitting learner's own cohort in the course (Course Details tab).
            'learner_cohort'   => $this->resource->getAttribute('learner_cohort'),
            'user'             => $this->whenLoaded('user', fn () => [
                'id'              => $this->user->id,
                'name'            => $this->user->getLocalizedName(),
                'machine_code'    => $this->user->machine_code,
                'department_name' => $this->user->department_name,
            ]),
            // Authorized route, not the public disk the file is no longer on (D-031).
            'user_file_url'    => $this->user_file && $assignment
                ? route('assignment.submission.file', [
                    'course'     => $this->assignment->course_id,
                    'assignment' => $this->course_assignment_id,
                    'submission' => $this->id,
                ])
                : null,
            'total_score'      => $awarded !== null ? (int) $awarded : null,
            'max_score'        => (int) $max,
            'score_percent'    => $percent,
            // Against the assignment's pass score (Q-031), half the total when
            // it sets none (D-090), as the learner side decides it; null while
            // ungraded.
            'passed'           => $assignment && $awarded !== null && $pendingAnswers === 0
                ? $awarded >= $this->assignment->effectivePassScore()
                : null,
            'feedback'         => $this->feedback,
            // B-129: an answer still awaiting a person's score keeps it pending.
            'status'           => $awarded !== null && $pendingAnswers === 0 ? 'graded' : 'pending',
            'submitted_at'     => $this->submitted_at?->format('Y-m-d H:i:s'),
            'reviewed_at'      => $this->reviewed_at?->format('Y-m-d H:i:s'),
            'created_at'       => $this->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
