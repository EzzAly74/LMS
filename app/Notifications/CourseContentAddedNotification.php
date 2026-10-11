<?php

namespace App\Notifications;

use App\Models\Course;
use Illuminate\Notifications\Notification;

/**
 * New content in a course the learner is enrolled in (NEW2B-5803): a module,
 * or a published quiz. Bell only: one per item, so email would turn every
 * upload into a message. Users carry no language, so the row holds both.
 */
class CourseContentAddedNotification extends Notification
{
    /**
     * @param  'module'|'quiz'  $kind
     * @param  array{en: string, ar: string}  $title  the item's title per language
     */
    public function __construct(
        private readonly Course $course,
        private readonly string $kind,
        private readonly array $title,
        private readonly int $itemId,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type'     => 'course_content_added',
            'title_en' => __("messages.notifications.content_added_{$this->kind}_title", [], 'en'),
            'title_ar' => __("messages.notifications.content_added_{$this->kind}_title", [], 'ar'),
            'body_en'  => __('messages.notifications.content_added_body', $this->values('en'), 'en'),
            'body_ar'  => __('messages.notifications.content_added_body', $this->values('ar'), 'ar'),
            'meta'     => ['course_id' => (int) $this->course->id, 'kind' => $this->kind, 'item_id' => $this->itemId],
        ];
    }

    /** @return array{item: string, course: string} */
    private function values(string $locale): array
    {
        $other = $locale === 'ar' ? 'en' : 'ar';

        return [
            'item'   => $this->title[$locale] !== '' ? $this->title[$locale] : $this->title[$other],
            'course' => (string) $this->course->getTranslation('title', $locale),
        ];
    }
}
