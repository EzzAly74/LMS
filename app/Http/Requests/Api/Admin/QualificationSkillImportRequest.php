<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for POST admin/qualification-skills/import.
 *
 * `mimetypes:` checks the finfo-detected type of the contents, not the
 * extension, following the B-02 hardening — a renamed script must not reach the
 * spreadsheet parser. Size is capped so one upload cannot exhaust memory before
 * the row cap in QualificationSkillImportService is even reached.
 */
class QualificationSkillImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware enforces auth.user + role:Admin + permission:view-qualifications.
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:5120', // 5 MB
                'mimetypes:'.implode(',', [
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // xlsx
                    'application/vnd.ms-excel',                                          // xls
                    'text/csv',
                    'text/plain',
                ]),
                'mimes:xlsx,xls,csv,txt',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.mimetypes' => __('messages.import_file_type'),
            'file.mimes'     => __('messages.import_file_type'),
        ];
    }
}
