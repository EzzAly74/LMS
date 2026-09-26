<?php

namespace App\Notifications;

use App\Models\Course;
use App\Models\Instructor;
use Illuminate\Notifications\Notification;

/**
 * "Updated course Evaluation - the course Evaluation for "X" has dropped to 3.0"
 * (Figma 2171:111083; Q-034, approved under D-048).
 *
 * Sent when a course's evaluation score (/5, D-054) falls below the pass limit,
 * to admins who can see evaluations and to the course's instructors, as the
 * builder's "Business rule" note promises. In-app only until mail is set up.
 */
class CourseEvaluationDroppedNotification extends Notification
{
    public function __construct(
        private readonly Course $course,
        private readonly float $score,
        private readonly float $threshold,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $audience = $notifiable instanceof Instructor ? 'instructor' : 'admin';
        $score    = number_format($this->score, 1);

        $en = ['course' => $this->course->getTranslation('title', 'en'), 'score' => $score];
        $ar = ['course' => $this->course->getTranslation('title', 'ar') ?: $en['course'], 'score' => $score];

        return [
            'type'     => 'evaluation_dropped',
            'title_en' => __("messages.notifications.evaluation_dropped_{$audience}_title", [], 'en'),
            'title_ar' => __("messages.notifications.evaluation_dropped_{$audience}_title", [], 'ar'),
            'body_en'  => __("messages.notifications.evaluation_dropped_{$audience}_body", $en, 'en'),
            'body_ar'  => __("messages.notifications.evaluation_dropped_{$audience}_body", $ar, 'ar'),
            'meta'     => ['course_id' => $this->course->id, 'score' => $this->score, 'threshold' => $this->threshold],
        ];
    }
}
