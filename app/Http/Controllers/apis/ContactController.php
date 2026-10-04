<?php

namespace App\Http\Controllers\apis;

use App\Http\Requests\Api\ContactRequestRequest;
use App\Mail\ContactAutoReply;
use App\Mail\ContactGuestInvite;
use App\Mail\ContactNotification;
use App\Models\ContactRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;

class ContactController extends ApiController
{
    /**
     * Public company contact details (driven by .env — never hardcoded).
     */
    public function info(): JsonResponse
    {
        return $this->success(__('messages.retrieved'), [
            'email' => config('contact.email'),
            'phone' => config('contact.phone'),
        ]);
    }

    /**
     * Store a Book-a-Demo / Contact request, then queue the customer
     * auto-reply, an invite to each guest and the company notification (to
     * CONTACT_EMAIL).
     */
    public function store(ContactRequestRequest $request): JsonResponse
    {
        $locale = app()->getLocale();

        $contact = ContactRequest::create([
            ...$request->validated(),
            'locale' => $locale,
        ]);

        // Email delivery is decoupled (queued); never fail the submission if
        // the mail transport / queue hiccups — the request is already saved.
        // Each mail is its own attempt, so one bad address stops no other.
        $mails = [[$contact->email, new ContactAutoReply($contact, $locale)]];
        foreach ($request->guestsToInvite() as $guest) {
            $mails[] = [$guest, new ContactGuestInvite($contact, $locale)]; // NEW2B-5898
        }
        if ($companyEmail = config('contact.email')) {
            $mails[] = [$companyEmail, new ContactNotification($contact, $locale)];
        }
        foreach ($mails as [$to, $mail]) {
            try {
                Mail::to($to)->queue($mail);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $this->created(__('messages.created'), [
            'id'    => $contact->id,
            'name'  => $contact->name,
            'email' => $contact->email,
        ]);
    }
}
