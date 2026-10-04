<?php

namespace App\Notifications;

use App\Models\Course;
use App\Models\CourseSection;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A learner asked to be told when a course opens (catalogue "Get notified",
 * profile "Notify me", NEW2B-5780); a cohort they can join is now open.
 * Bell + email (human, 2026-10-04). Users carry no language, so the email
 * is Arabic then English; the bell row has both, like the other notifications.
 */
class CohortOpenedNotification extends Notification
{
    public function __construct(
        private readonly Course $course,
        private readonly CourseSection $cohort,
    ) {}

    public function via(object $notifiable): array
    {
        return filled($notifiable->email ?? null) ? ['database', 'mail'] : ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type'     => 'cohort_opened',
            'title_en' => __('messages.notifications.cohort_opened_title', [], 'en'),
            'title_ar' => __('messages.notifications.cohort_opened_title', [], 'ar'),
            'body_en'  => __('messages.notifications.cohort_opened_body', $this->values('en'), 'en'),
            'body_ar'  => __('messages.notifications.cohort_opened_body', $this->values('ar'), 'ar'),
            'meta'     => ['course_id' => (int) $this->course->id, 'cohort_id' => (int) $this->cohort->id],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage())
            ->subject(__('messages.notifications.cohort_opened_title', [], 'ar').' | '.__('messages.notifications.cohort_opened_title', [], 'en'))
            ->line(__('messages.notifications.cohort_opened_body', $this->values('ar'), 'ar'))
            ->line(__('messages.notifications.cohort_opened_body', $this->values('en'), 'en'));

        if ($base = config('app.website_url')) {
            $mail->action(__('messages.notifications.cohort_opened_action', [], 'en'), rtrim($base, '/').'/catalogue/'.$this->course->id);
        }

        return $mail;
    }

    /** @return array{course: string, date: string} */
    private function values(string $locale): array
    {
        return [
            'course' => (string) $this->course->getTranslation('title', $locale),
            'date'   => $this->cohort->start_date ? \Illuminate\Support\Carbon::parse($this->cohort->start_date)->locale($locale)->translatedFormat('j F Y') : '',
        ];
    }
}
