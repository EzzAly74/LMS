<?php

namespace App\Http\Resources\Admin;

use App\Models\CourseAssignmentQuestion;
use App\Models\UserCourseAssignmentAnswer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full submission payload for the "View details" page (Figma 2393:120281 /
 * 2393:121481 / 2393:121817) - the assignment, every question, and the
 * learner's answer for each question.
 *
 * Files are never exposed by path: each carries its name, size, date and an
 * authorized download URL (D-031).
 */
class AdminAssignmentSubmissionDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $assignment = $this->whenLoaded('assignment');
        $course     = $assignment ? $this->assignment->course : null;
        $instructor = $assignment ? optional($this->assignment->creator) : null;

        $max     = $this->max_score ?? ($assignment ? (int) $this->assignment->total_score : 0);
        $awarded = $this->total_score;
        $percent = ($max > 0 && $awarded !== null) ? (int) round(($awarded / $max) * 100) : null;

        $answers = $this->relationLoaded('answers') ? $this->answers : collect();
        // B-129: an answer still waiting for a person's score (open / file)
        // keeps the whole submission pending, whatever `total_score` says -
        // the learner flow writes a running total from the first answer on.
        $pending = $answers->whereNull('awarded_score')->count();

        return [
            'id'               => $this->id,
            'assignment'       => $assignment ? [
                'id'           => $this->assignment->id,
                'title'        => $this->assignment->title,
                'title_ar'     => $this->assignment->title_ar,
                'status'       => $this->assignment->status,
                'cohort_scope' => $this->assignment->cohort_scope,
                'pass_score'   => $this->assignment->pass_score !== null ? (int) $this->assignment->pass_score : null,
                'total_score'  => (int) $this->assignment->total_score,
            ] : null,
            'course_title'     => $course?->title,
            // Delivery badge of the header ("Hybrid", Figma 2393:120281).
            'course_type'      => $course?->course_type,
            'category_name'    => $course?->relationLoaded('category') ? $course->category?->name : null,
            'instructor_name'  => $instructor && isset($instructor->name) ? $instructor->name : null,
            'user'             => $this->whenLoaded('user', fn () => [
                'id'              => $this->user->id,
                'name'            => $this->user->getLocalizedName(),
                'machine_code'    => $this->user->machine_code,
                'department_name' => $this->user->department_name,
            ]),
            // Legacy whole-assignment upload. This read the public disk, where
            // new submissions are no longer stored (D-031), so the link was
            // dead; it is now the authorized download route.
            'user_file_url'    => $this->user_file && $assignment
                ? route('assignment.submission.file', [
                    'course'     => $this->assignment->course_id,
                    'assignment' => $this->course_assignment_id,
                    'submission' => $this->id,
                ])
                : null,
            'total_score'      => $awarded !== null ? (int) $awarded : null,
            'max_score'        => (int) $max,
            'score_percent'    => $pending === 0 ? $percent : null,
            'pending_answers'  => $pending,
            'feedback'         => $this->feedback,
            'status'           => $awarded !== null && $pending === 0 ? 'graded' : 'pending',
            'submitted_at'     => $this->submitted_at?->format('Y-m-d H:i:s'),
            'reviewed_at'      => $this->reviewed_at?->format('Y-m-d H:i:s'),
            'updated_at'       => $this->updated_at?->format('Y-m-d H:i:s'),
            'created_at'       => $this->created_at?->format('Y-m-d H:i:s'),
            'answers'          => $answers->map(fn (UserCourseAssignmentAnswer $answer) => $this->answer($answer))->values(),
        ];
    }

    private function answer(UserCourseAssignmentAnswer $answer): array
    {
        $q = $answer->question;

        return [
            'id'            => $answer->id,
            'awarded_score' => $answer->awarded_score !== null ? (int) $answer->awarded_score : null,
            'is_correct'    => $answer->is_correct,
            'answer'        => $answer->answer,
            'feedback'      => $answer->feedback,
            'file'          => $answer->file_path !== null ? [
                'name'         => $answer->file_name,
                'size'         => (int) $answer->file_size,
                'uploaded_at'  => $answer->file_uploaded_at?->format('Y-m-d H:i:s'),
                'download_url' => route('admin.assignments.answer-file', ['submission' => $this->id, 'answer' => $answer->id]),
            ] : null,
            'question'      => $q ? $this->question($q) : null,
        ];
    }

    private function question(CourseAssignmentQuestion $q): array
    {
        return [
            'id'                => $q->id,
            'position'          => (int) $q->position,
            'type'              => $q->type,
            'score'             => (int) $q->score,
            'question_en'       => $q->question_en,
            'question_ar'       => $q->question_ar,
            'options_en'        => $q->options_en ?? [],
            'options_ar'        => $q->options_ar ?? [],
            'correct_answer_en' => $q->correct_answer_en,
            'correct_answer_ar' => $q->correct_answer_ar,
            'explanation_en'    => $q->explanation_en,
            'explanation_ar'    => $q->explanation_ar,
            'attachment'        => AssignmentAttachmentPayload::for($q),
        ];
    }
}
