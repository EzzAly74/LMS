<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Notification;

class PendingGradeNotification extends Notification
{
    /**
     * @param  User|null  $student  named in each language's body; when absent
     *                              the body names the notification itself.
     * @param  string  $assessmentType  'quiz' | 'assignment' — carried in meta for deep-linking only.
     * @param  array<string, mixed>  $meta  Extra ids for deep-linking (course_id, assessment_id, submission_id).
     */
    public function __construct(
        private readonly ?User $student,
        private readonly string $assessmentTitle,
        private readonly string $assessmentType,
        private readonly array $meta = [],
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $params = fn (string $locale) => [
            'student' => $this->student?->getLocalizedName($locale) ?: __('messages.notifications.pending_grade_title', [], $locale),
            'title'   => $this->assessmentTitle,
        ];

        return [
            'type'     => 'pending_grade',
            'title_en' => __('messages.notifications.pending_grade_title', [], 'en'),
            'title_ar' => __('messages.notifications.pending_grade_title', [], 'ar'),
            'body_en'  => __('messages.notifications.pending_grade_body', $params('en'), 'en'),
            'body_ar'  => __('messages.notifications.pending_grade_body', $params('ar'), 'ar'),
            'meta'     => array_merge(['assessment_type' => $this->assessmentType], $this->meta),
        ];
    }
}
