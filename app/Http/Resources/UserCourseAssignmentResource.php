<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class UserCourseAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'assignment'       => new CourseAssignmentResource($this->whenLoaded('assignment')),
            'assignment_title' => $this->whenLoaded('assignment', fn () => $this->assignment->title),
            'course_title'     => $this->whenLoaded('assignment', fn () => optional($this->assignment->course)->title),
            'user'             => $this->whenLoaded('user', fn () => [
                'id'              => $this->user->id,
                'name'            => $this->user->getLocalizedName(),
                'machine_code'    => $this->user->machine_code,
                'department_name' => $this->user->department_name,
            ]),
            // B-10: this used to be a public-disk URL served straight off the
            // filesystem with no authorization, so any holder of the link could
            // read another learner's work. It now points at the authorized
            // download route, which checks author-or-staff.
            'user_file_url'    => $this->user_file
                ? route('assignment.submission.file', [
                    // Prefer the eager-loaded relation; fall back to a single
                    // column read so a caller that did not eager-load still
                    // works, without pulling the whole assignment row.
                    'course'     => $this->relationLoaded('assignment')
                        ? $this->assignment?->course_id
                        : $this->assignment()->value('course_id'),
                    'assignment' => $this->course_assignment_id,
                    'submission' => $this->id,
                ])
                : null,
            'feedback'         => $this->feedback,
            'score'            => $this->score,
            'status'           => $this->score !== null ? 'graded' : 'pending',
            'created_at'       => $this->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
