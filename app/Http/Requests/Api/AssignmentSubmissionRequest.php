<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class AssignmentSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization (enrolment + assignment ownership) is enforced in
        // CourseAssignmentController::submit, which has the route-model-bound
        // Course and CourseAssignment available.
        return true;
    }

    public function rules(): array
    {
        return [
            // B-02 (Critical): this was `required|file|max:20480` — any file
            // type at all. Combined with the client-supplied extension in
            // HasFile and the public disk, that was RCE via `.php` (Apache
            // serves `/storage/**` directly when the file exists) or stored
            // XSS via `.html` / `.svg`.
            //
            // `mimetypes:` checks the finfo-detected type of the file's
            // contents, so renaming `shell.php` to `report.pdf` fails here.
            // `mimes:` additionally pins the declared extension. Both are
            // applied: mimetypes is the real gate, mimes catches the
            // extension/content mismatch early with a clearer message.
            //
            // SVG is deliberately excluded: it is an executable document that
            // can carry <script>, and assignment submissions have no need of it.
            'file' => [
                'required',
                'file',
                'max:20480', // 20 MB, unchanged
                'mimetypes:'.implode(',', [
                    'application/pdf',
                    'application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/vnd.ms-excel',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'application/vnd.ms-powerpoint',
                    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                    'text/plain',
                    'text/csv',
                    'image/png',
                    'image/jpeg',
                    'application/zip',
                    'application/x-zip-compressed',
                ]),
                'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,png,jpg,jpeg,zip',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.mimetypes' => __('messages.submission_file_type'),
            'file.mimes'     => __('messages.submission_file_type'),
        ];
    }
}
