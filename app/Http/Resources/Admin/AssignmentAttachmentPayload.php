<?php

namespace App\Http\Resources\Admin;

use App\Models\CourseAssignmentQuestion;

/**
 * The instructor's attachment on a file question, as the admin screens show
 * it (name, size, date, authorized download URL) - never its storage path.
 */
final class AssignmentAttachmentPayload
{
    /** @return array{name:?string, size:int, uploaded_at:?string, download_url:string}|null */
    public static function for(CourseAssignmentQuestion $q): ?array
    {
        if ($q->attachment_path === null) {
            return null;
        }

        return [
            'name'         => $q->attachment_name,
            'size'         => (int) $q->attachment_size,
            'uploaded_at'  => $q->attachment_uploaded_at?->format('Y-m-d H:i:s'),
            'download_url' => route('admin.assignments.question-attachment', [
                'assignment' => $q->course_assignment_id,
                'question'   => $q->id,
            ]),
        ];
    }
}
