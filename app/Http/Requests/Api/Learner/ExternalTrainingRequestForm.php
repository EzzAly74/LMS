<?php

namespace App\Http\Requests\Api\Learner;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * POST learner/external-training (create) and POST .../{id} (edit) - Figma
 * 2201:83481, rules decided by the human 2026-09-26 (Q-040, D-057).
 *
 * Title and provider as free text; start and end dates, end on or after start
 * and not in the future (it is training already completed); hours in steps of
 * 0.5; cost optional, EGP; one certificate, PDF / JPG / PNG, up to 10 MB,
 * checked by its content (`mimetypes` reads the bytes, B-02). The certificate
 * is required on create and optional on edit (keep the one uploaded).
 */
class ExternalTrainingRequestForm extends FormRequest
{
    public const MAX_TEXT = 191;
    public const MAX_KB = 10240;

    public function authorize(): bool
    {
        // Route middleware: auth.user + role:User. Ownership is checked in the controller.
        return true;
    }

    public function rules(): array
    {
        $creating = $this->route('externalTraining') === null;

        return [
            'title'       => ['required', 'string', 'max:'.self::MAX_TEXT],
            'provider'    => ['required', 'string', 'max:'.self::MAX_TEXT],
            'start_date'  => ['required', 'date_format:Y-m-d'],
            'end_date'    => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date', 'before_or_equal:today'],
            'hours'       => ['required', 'numeric', 'min:0.5', 'max:2000'],
            'cost'        => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'certificate' => [
                $creating ? 'required' : 'sometimes',
                'file',
                'max:'.self::MAX_KB,
                'mimetypes:application/pdf,image/jpeg,image/png',
                'mimes:pdf,jpg,jpeg,png',
            ],
        ];
    }

    public function after(): array
    {
        return [function (Validator $v) {
            $hours = $this->input('hours');
            if (is_numeric($hours) && fmod((float) $hours * 2, 1.0) !== 0.0) {
                $v->errors()->add('hours', __('messages.external_training_hours_step'));
            }
        }];
    }

    public function messages(): array
    {
        return [
            'certificate.mimetypes' => __('messages.submission_file_type'),
            'certificate.mimes'     => __('messages.submission_file_type'),
        ];
    }

    /** @return array{title:string, provider:string, start_date:string, end_date:string, hours:float, cost:?float} */
    public function fields(): array
    {
        $d = $this->validated();

        return [
            'title'      => (string) $d['title'],
            'provider'   => (string) $d['provider'],
            'start_date' => (string) $d['start_date'],
            'end_date'   => (string) $d['end_date'],
            'hours'      => (float) $d['hours'],
            'cost'       => isset($d['cost']) && $d['cost'] !== '' ? (float) $d['cost'] : null,
        ];
    }
}
