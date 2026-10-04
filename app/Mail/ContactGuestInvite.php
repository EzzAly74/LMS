<?php

namespace App\Mail;

use App\Models\ContactRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to each guest the requester added on Book a Demo (NEW2B-5898): who
 * invited them, and that the meeting invitation follows once the team has
 * arranged the time with the requester. Queued, like the other contact mail.
 */
class ContactGuestInvite extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public ContactRequest $contact,
        public string $lang,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->lang === 'ar'
                ? "{$this->contact->name} أضافك إلى عرض توضيحي لمنصة NAS LMS"
                : "{$this->contact->name} added you to a NAS LMS demo",
            replyTo: [$this->contact->email],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.contact.guest-invite',
            with: [
                'contact' => $this->contact,
                'locale'  => $this->lang,
            ],
        );
    }
}
