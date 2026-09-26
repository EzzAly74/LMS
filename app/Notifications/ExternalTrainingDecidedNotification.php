<?php

namespace App\Notifications;

use App\Models\ExternalTrainingRequest;
use Illuminate\Notifications\Notification;

/**
 * The learner's external training request was approved or rejected (Figma
 * 2201:85172, "...has been accepted"; D-057). A rejection carries the reason,
 * which the learner is meant to see.
 */
class ExternalTrainingDecidedNotification extends Notification
{
    public function __construct(private readonly ExternalTrainingRequest $request) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $outcome = $this->request->status === ExternalTrainingRequest::APPROVED ? 'approved' : 'rejected';
        $values  = ['title' => $this->request->title, 'reason' => (string) $this->request->rejection_reason];

        return [
            'type'     => "external_training_{$outcome}",
            'title_en' => __("messages.notifications.external_training_{$outcome}_title", [], 'en'),
            'title_ar' => __("messages.notifications.external_training_{$outcome}_title", [], 'ar'),
            'body_en'  => __("messages.notifications.external_training_{$outcome}_body", $values, 'en'),
            'body_ar'  => __("messages.notifications.external_training_{$outcome}_body", $values, 'ar'),
            'meta'     => ['external_training_id' => $this->request->id, 'status' => $this->request->status],
        ];
    }
}
