<?php

namespace App\Listeners;

use App\Events\AssignmentSubmitted;
use App\Events\QuizSubmitted;
use App\Notifications\PendingGradeNotification;

/**
 * Fires "manual grading required" for the course's instructors whenever a
 * submitted quiz/assignment contains at least one 'open' (short-answer)
 * question — those can't be auto-graded.
 */
class NotifyInstructorsPendingGrading
{
    public function handleQuiz(QuizSubmitted $event): void
    {
        $userExam = $event->userExam;
        $exam     = $userExam->exam;

        if (! $exam || ! $exam->richQuestions()->where('type', 'open')->exists()) {
            return;
        }

        $course = $exam->course;
        if (! $course) {
            return;
        }

        foreach ($course->instructors as $instructor) {
            $instructor->notify(new PendingGradeNotification(
                $userExam->user,
                $exam->getTranslation('title', 'en'),
                'quiz',
                ['course_id' => $course->id, 'exam_id' => $exam->id, 'user_exam_id' => $userExam->id],
            ));
        }
    }

    public function handleAssignment(AssignmentSubmitted $event): void
    {
        $submission = $event->submission;
        $assignment = $submission->assignment;

        // Open and file answers are scored by a person (MANUAL_TYPES).
        if (! $assignment || ! $assignment->questions()->whereIn('type', \App\Models\CourseAssignmentQuestion::MANUAL_TYPES)->exists()) {
            return;
        }

        $course = $assignment->course;
        if (! $course) {
            return;
        }

        foreach ($course->instructors as $instructor) {
            $instructor->notify(new PendingGradeNotification(
                $submission->user,
                $assignment->title,
                'assignment',
                ['course_id' => $course->id, 'course_assignment_id' => $assignment->id, 'submission_id' => $submission->id],
            ));
        }
    }
}
