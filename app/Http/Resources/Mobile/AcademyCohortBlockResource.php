<?php

declare(strict_types=1);

namespace App\Http\Resources\Mobile;

use App\Models\Course;
use App\Models\CourseSection;
use App\Services\Mobile\AcademyService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * S-03 cohort/dates block. Shown both above the sticky CTA and inside
 * the cohort selector sheet.
 */
class AcademyCohortBlockResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var CourseSection $cohort */
        $cohort    = $this->resource;
        $locale    = app()->getLocale();
        $academy   = app(AcademyService::class);
        $now       = now();
        $enrolled  = (int) ($cohort->enrolled_count
            ?? \DB::table('users_courses')->where('group_id', $cohort->id)->count());
        // Every cohort has a limit (NEW2B-6050): its own, the course's, or the default.
        $capacity  = $academy->effectiveCapacity($cohort);
        $seatsLeft = max(0, $capacity - $enrolled);
        $deadline  = $academy->effectiveDeadline($cohort);

        return [
            'id'                  => (int) $cohort->id,
            'name'                => $cohort->getTranslation('name', $locale),
            'effective_status'    => Course::deriveCohortStatus(
                $cohort->status,
                $cohort->start_date instanceof \Carbon\Carbon ? $cohort->start_date : null,
                $cohort->end_date instanceof \Carbon\Carbon   ? $cohort->end_date   : null,
            ),
            'start_date'          => $cohort->start_date?->format('Y-m-d'),
            'end_date'            => $cohort->end_date?->format('Y-m-d'),
            'capacity'            => $capacity,
            'enrolled_count'      => $enrolled,
            'seats_left'          => $seatsLeft,
            'is_full'             => $enrolled >= $capacity,
            'enrolment_closes_at' => $deadline?->toDateString(),
            'days_until_deadline' => $academy->daysUntilDeadline($cohort, $now),
            'deadline_severity'   => $academy->deadlineSeverity($cohort, $now),
            'sessions'            => $cohort->relationLoaded('sessions')
                ? $cohort->sessions->map(fn ($s) => [
                    'id'           => (int) $s->id,
                    'title'        => $s->title,
                    'session_date' => $s->session_date instanceof \Carbon\Carbon
                        ? $s->session_date->format('Y-m-d')
                        : $s->session_date,
                    'time_from'    => $s->time_from,
                    'time_to'      => $s->time_to,
                    'location'     => $s->location,
                    // Modules this session covers, from the cohort's schedule
                    // sheet (D-079); ids of the course's `units`.
                    'content_ids'  => $s->relationLoaded('lectures')
                        ? $s->lectures->map(fn ($l) => (int) $l->id)->values()
                        : [],
                    ...$this->sessionTiming($s->session_date, $s->time_from, $s->time_to, $now),
                ])->values()
                : [],
        ];
    }

    /**
     * Schedule tab (Figma 2027:97810): Duration and Completed / Upcoming,
     * decided on the server clock so every viewer sees the same status.
     * A session is completed once its end (or, without times, its day) has passed.
     *
     * @return array{duration_minutes: ?int, status: ?string}
     */
    private function sessionTiming(mixed $date, ?string $from, ?string $to, Carbon $now): array
    {
        if ($date === null || $date === '') {
            return ['duration_minutes' => null, 'status' => null];
        }
        $day   = $date instanceof Carbon ? $date->copy()->startOfDay() : Carbon::parse((string) $date)->startOfDay();
        $start = $from ? $day->copy()->setTimeFromTimeString($from) : null;
        $end   = $to ? $day->copy()->setTimeFromTimeString($to) : null;

        $duration = $start && $end && $end->greaterThan($start) ? (int) $start->diffInMinutes($end) : null;
        $over     = $end ?? $day->copy()->endOfDay();

        return [
            'duration_minutes' => $duration,
            'status'           => $now->greaterThan($over) ? 'completed' : 'upcoming',
        ];
    }
}
