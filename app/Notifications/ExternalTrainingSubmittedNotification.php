<?php

namespace App\Notifications;

use App\Models\ExternalTrainingRequest;
use Illuminate\Notifications\Notification;

/**
 * A learner submitted external training for review (D-057). Sent in-app to the
 * admins who review it, so a request does not wait unseen.
 */
class ExternalTrainingSubmittedNotification extends Notification
{
    public function __construct(private readonly ExternalTrainingRequest $request) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $learner = $this->request->user;
        $values  = ['learner' => (string) $learner?->name, 'title' => $this->request->title];

        return [
            'type'     => 'external_training_submitted',
            'title_en' => __('messages.notifications.external_training_submitted_title', [], 'en'),
            'title_ar' => __('messages.notifications.external_training_submitted_title', [], 'ar'),
            'body_en'  => __('messages.notifications.external_training_submitted_body', $values, 'en'),
            'body_ar'  => __('messages.notifications.external_training_submitted_body', $values, 'ar'),
            'meta'     => ['external_training_id' => $this->request->id],
        ];
    }
}
