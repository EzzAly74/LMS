<?php

declare(strict_types=1);

namespace App\Http\Resources\Mobile;

use App\Models\Course;
use App\Models\CourseSection;
use App\Services\Mobile\AcademyService;
use App\Support\SessionTitle;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * S-03 cohort/dates block: the "Next cohort" card (Figma 2286:167907 -
 * starts, round, ends, sessions, seats) and the Schedule screen
 * (Figma 2286:168110 - each session's date, time, duration, location and
 * Completed / Upcoming). Also the cohort selector sheet.
 *
 * With the sessions loaded, the dates, count and status come from the
 * session rows, the schedule learners attend (NEW2B-6202, NEW2B-6206);
 * the stored cohort dates are only the fallback.
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

        /** @var Collection<int, \App\Models\CourseSession>|null $sessions */
        $sessions = $cohort->relationLoaded('sessions') ? $cohort->sessions : null;
        $days     = $sessions?->pluck('session_date')->filter()
            ->map(fn ($d) => $d instanceof Carbon ? $d->copy()->startOfDay() : Carbon::parse((string) $d)->startOfDay());
        $start = $days?->isNotEmpty() ? $days->min() : ($cohort->start_date instanceof Carbon ? $cohort->start_date : null);
        $end   = $days?->isNotEmpty() ? $days->max() : ($cohort->end_date instanceof Carbon ? $cohort->end_date : null);
        $held  = $sessions?->filter(fn ($s) => $this->sessionTiming($s->session_date, $s->time_from, $s->time_to, $now)['status'] === 'completed')->count();

        return [
            'id'                  => (int) $cohort->id,
            'name'                => $cohort->getTranslation('name', $locale),
            // The course's nth cohort by start date (the card's "Round 3, Cohort C").
            'round'               => $cohort->getAttribute('round') !== null ? (int) $cohort->getAttribute('round') : null,
            'effective_status'    => Course::deriveCohortStatus(
                $cohort->status,
                $start,
                $end,
                $sessions?->count() ?: null,
                $held,
            ),
            'start_date'          => $start?->format('Y-m-d'),
            'end_date'            => $end?->format('Y-m-d'),
            'sessions_count'      => $sessions?->count(),
            'capacity'            => $capacity,
            'enrolled_count'      => $enrolled,
            'seats_left'          => $seatsLeft,
            'is_full'             => $enrolled >= $capacity,
            'enrolment_closes_at' => $deadline?->toDateString(),
            'days_until_deadline' => $academy->daysUntilDeadline($cohort, $now),
            'deadline_severity'   => $academy->deadlineSeverity($cohort, $now),
            'sessions'            => $sessions
                ? $sessions->values()->map(fn ($s, int $i) => [
                    'id'           => (int) $s->id,
                    // Generated "Session N" titles in the reader's language.
                    'title'        => SessionTitle::localize($s->title, $i + 1, $locale) ?? $s->title,
                    'session_date' => $s->session_date instanceof Carbon
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
