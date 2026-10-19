<?php

declare(strict_types=1);

namespace App\Http\Resources\Mobile;

use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of `GET mobile/courses`: the course's own catalogue data.
 *
 * No employee is known on this route, so nothing learner-specific is
 * returned (no CTA, enrolment state or next joinable cohort); use
 * `mobile/academy/courses/{id}` with an `Employee-Code` for those.
 * Translatable fields follow `Accept-Language` (SetLocale).
 */
class ActiveCourseItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Course $course */
        $course = $this->resource;
        $locale = app()->getLocale();

        return [
            'id'              => (int) $course->id,
            'title'           => (string) $course->getTranslation('title', $locale),
            'description'     => $course->getTranslation('description', $locale),
            'course_type'     => $course->course_type,
            'level'           => $course->level,
            'duration_weeks'  => $course->duration_weeks !== null ? (int) $course->duration_weeks : null,
            'image'           => $this->absoluteUrl($course->image),
            'hours'           => (int) ($course->hours ?? 0),
            'has_certificate' => true, // mandatory for every course (D-094)
            'category'        => $course->category
                ? [
                    'id'   => (int) $course->category->id,
                    'name' => $course->category->getTranslation('name', $locale),
                ]
                : null,
            'instructors'     => $course->instructors->map(fn ($i) => [
                'id'    => (int) $i->id,
                'name'  => (string) $i->name,
                'image' => $this->absoluteUrl($i->image),
            ])->values(),
            'qualifications'  => $course->qualificationSkills->map(fn ($q) => [
                'id'   => (int) $q->id,
                'name' => (string) $q->getTranslation('name', $locale),
            ])->values(),
            'rating'          => [
                'avg'   => round((float) ($course->rating_avg ?? 0), 2),
                'count' => (int) ($course->rating_count ?? 0),
            ],
        ];
    }

    private function absoluteUrl(?string $path): ?string
    {
        if ($path === null || $path === '') return null;
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }
        return asset('storage/' . ltrim($path, '/'));
    }
}
