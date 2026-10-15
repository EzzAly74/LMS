<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the course "Content" tab (Figma 342:11813, D-109): a module, a
 * quiz or an assignment. `code` is what the schedule sheet's "content"
 * column takes (12, Q5, A3).
 *
 * @property array{kind: string, id: int, code: int|string, title: string, content_type: ?string, duration_minutes: ?int, status: ?string, due_date: ?string} $resource
 */
class CourseContentItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'kind'             => $this->resource['kind'],
            'id'               => $this->resource['id'],
            'code'             => (string) $this->resource['code'],
            'title'            => $this->resource['title'],
            'content_type'     => $this->resource['content_type'],
            'duration_minutes' => $this->resource['duration_minutes'],
            'status'           => $this->resource['status'],
            'due_date'         => $this->resource['due_date'],
        ];
    }
}
