<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * POST courses/{course}/sections/scheduled - New Cohort with its schedule
 * (Figma 2393:123167: both names required, capacity defaults to 30, the
 * completed schedule as .xls / .xlsx up to 8 MB).
 *
 * The file is checked three ways: finfo MIME type, extension, and the
 * container's magic bytes (xlsx is a ZIP, xls an OLE2 compound file), so a
 * renamed script never reaches the spreadsheet parser.
 */
class CohortWithScheduleRequest extends FormRequest
{
    public const MAX_KB = 8192;

    private const SIGNATURES = ["PK\x03\x04", "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"];

    public function authorize(): bool
    {
        // Route middleware enforces auth.user + role:Admin + permission:view-courses.
        return true;
    }

    public function rules(): array
    {
        return [
            'name'     => ['required', 'array'],
            'name.en'  => ['required', 'string', 'max:255'],
            'name.ar'  => ['required', 'string', 'max:255'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'schedule' => [
                'required', 'file', 'max:'.self::MAX_KB,
                'mimetypes:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel,application/zip,application/x-ole-storage,application/CDFV2',
                'mimes:xlsx,xls',
            ],
        ];
    }

    public function after(): array
    {
        return [function (Validator $v) {
            if ($v->errors()->has('schedule') || ! $this->hasFile('schedule')) {
                return;
            }
            $head = (string) @file_get_contents($this->file('schedule')->getRealPath(), false, null, 0, 8);
            $ok   = false;
            foreach (self::SIGNATURES as $sig) {
                $ok = $ok || str_starts_with($head, $sig);
            }
            if (! $ok) {
                $v->errors()->add('schedule', __('messages.schedule_file_type'));
            }
        }];
    }

    public function messages(): array
    {
        return [
            'schedule.mimetypes' => __('messages.schedule_file_type'),
            'schedule.mimes'     => __('messages.schedule_file_type'),
        ];
    }

    /** @return array{name: array{en: string, ar: string}, capacity: int} */
    public function cohort(): array
    {
        $d = $this->validated();

        return [
            'name'       => ['en' => trim($d['name']['en']), 'ar' => trim($d['name']['ar'])],
            'capacity'   => (int) ($d['capacity'] ?? 30),
        ];
    }
}
