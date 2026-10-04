<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Public Book a Demo form. Each address here is mailed (the requester and
 * every guest, NEW2B-5898), so addresses must be deliverable-looking:
 * `email:rfc,filter` refuses `name@domain` without a dot, which plain
 * `email` let through with no error (NEW2B-5867).
 */
class ContactRequestRequest extends FormRequest
{
    public const MAX_GUESTS = 20;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => trim($this->input('email'))]);
        }

        // Guests trimmed, lower-cased, blanks and repeats dropped, so one
        // address is mailed once.
        if (is_array($this->input('guests'))) {
            $guests = array_map(fn ($g) => is_string($g) ? strtolower(trim($g)) : $g, $this->input('guests'));
            $this->merge(['guests' => array_values(array_unique(array_filter($guests, fn ($g) => $g !== '' && $g !== null), SORT_REGULAR))]);
        }
    }

    public function rules(): array
    {
        return [
            'name'         => 'required|string|max:255',
            'email'        => 'required|string|email:rfc,filter|max:255',
            'phone'        => 'nullable|string|max:50',
            'job_title'    => 'required|string|max:255',
            'company_name' => 'required|string|max:255',
            'guests'       => 'nullable|array|max:'.self::MAX_GUESTS,
            'guests.*'     => 'string|email:rfc,filter|max:255',
        ];
    }

    public function attributes(): array
    {
        return [
            'guests.*' => __('messages.contact_guest_email'),
        ];
    }

    /** Guests to invite: never the requester themself. @return list<string> */
    public function guestsToInvite(): array
    {
        $own = strtolower((string) $this->validated('email'));

        return array_values(array_filter($this->validated('guests') ?? [], fn (string $g) => $g !== $own));
    }
}
