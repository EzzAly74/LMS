<?php

namespace App\Http\Requests\Api;

/**
 * Upload rules for file-type assignment questions - both the learner's
 * answer and the instructor's attachment (D-064, human answer 2026-09-28:
 * Office, PDF and images, one file, 10 MB).
 *
 * `mimetypes:` is the real gate: it checks the finfo-detected type of the
 * contents, so a renamed script fails. `mimes:` pins the declared extension
 * too. Storage then derives the extension from the contents again
 * (HasFile::safeExtensionFor, B-02). No SVG (scriptable), no archives (their
 * contents cannot be checked), no plain text.
 */
final class AssignmentFileRules
{
    public const MAX_KB = 10240;

    public const EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'png', 'jpg', 'jpeg'];

    public const MIME_TYPES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        // What libmagic reports for many legacy .doc / .xls / .ppt (OLE2) files.
        'application/vnd.ms-office',
        'image/png',
        'image/jpeg',
    ];

    /** @return list<string> */
    public static function rules(): array
    {
        return [
            'required',
            'file',
            'max:'.self::MAX_KB,
            'mimetypes:'.implode(',', self::MIME_TYPES),
            'mimes:'.implode(',', self::EXTENSIONS),
        ];
    }

    /** @return array<string, string> */
    public static function messages(string $field): array
    {
        return [
            "{$field}.mimetypes" => __('messages.assignment_file_type'),
            "{$field}.mimes"     => __('messages.assignment_file_type'),
            "{$field}.max"       => __('messages.assignment_file_size'),
        ];
    }
}
