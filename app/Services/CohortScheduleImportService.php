<?php

namespace App\Services;

use App\Models\Course;
use App\Models\CourseSection;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * New Cohort with its schedule (Figma 2393:123167 / 2393:122292, D2 step 8).
 *
 * The admin downloads CohortScheduleTemplateExport, fills one row per session
 * (date, start, end, optional location) and uploads it with the cohort's name
 * and capacity. Every row is checked before anything is written; any problem
 * returns the full list and creates nothing, like the other importers. On
 * success the cohort and its sessions are created in one transaction, the
 * cohort's dates, session count and average length are taken from the rows,
 * and the course's hours are recomputed from the schedule (D-062).
 */
class CohortScheduleImportService
{
    public const COLUMNS = ['session_no', 'date', 'start_time', 'end_time', 'location'];

    private const REQUIRED_COLUMNS = ['date', 'start_time', 'end_time'];

    public const MAX_ROWS = 500;

    private const MAX_LOCATION = 191;

    public function __construct(
        private readonly CourseSectionService $cohorts,
        private readonly CourseHoursService $hours,
    ) {}

    /**
     * @param  array{name: array{en: string, ar: string}, capacity: int}  $cohort
     * @return array{section: ?CourseSection, errors: list<array{row: int, column: ?string, message: string}>}
     */
    public function create(Course $course, array $cohort, UploadedFile $file): array
    {
        $parsed = $this->parse($file);
        if ($parsed['errors'] !== []) {
            return ['section' => null, 'errors' => $parsed['errors']];
        }
        $sessions = $parsed['sessions'];

        $section = DB::transaction(function () use ($course, $cohort, $sessions) {
            $dates    = array_column($sessions, 'date');
            $lengths  = array_map(static fn ($s) => $s['seconds'] / 3600, $sessions);
            $average  = round(array_sum($lengths) / count($lengths), 2);

            $section = $this->cohorts->create($course, [
                'name'               => $cohort['name'],
                'capacity'           => $cohort['capacity'],
                'start_date'         => min($dates),
                'end_date'           => max($dates),
                'number_of_sessions' => count($sessions),
                // Same bounds as the cohort form's avg_session_time rule.
                'avg_session_time'   => min(24, max(0.25, $average)),
            ]);

            $now = now();
            DB::table('course_sessions')->insert(array_map(static fn ($s, $i) => [
                'course_id'    => $course->id,
                'section_id'   => $section->id,
                'title'        => __('messages.schedule_session_title', ['n' => $i + 1]),
                'session_date' => $s['date'],
                'time_from'    => $s['from'],
                'time_to'      => $s['to'],
                'location'     => $s['location'],
                'created_at'   => $now,
                'updated_at'   => $now,
            ], $sessions, array_keys($sessions)));

            $this->hours->refresh($course);

            return $section;
        });

        return ['section' => $section->refresh(), 'errors' => []];
    }

    /** @return array{sessions: list<array{date: string, from: string, to: string, seconds: int, location: ?string}>, errors: list<array{row: int, column: ?string, message: string}>} */
    private function parse(UploadedFile $file): array
    {
        // A file can pass the MIME check and still be unparseable; the
        // contract is a report, not a 500.
        try {
            $sheets = Excel::toArray(null, $file);
        } catch (\Throwable $e) {
            report($e);

            return $this->fail([[0, null, __('messages.import_unreadable')]]);
        }

        $rows = $sheets[0] ?? [];
        if ($rows === []) {
            return $this->fail([[0, null, __('messages.import_empty')]]);
        }

        $header  = array_map(static fn ($h) => strtolower(trim((string) $h)), array_shift($rows));
        $missing = array_diff(self::REQUIRED_COLUMNS, $header);
        if ($missing !== []) {
            return $this->fail([[1, null, __('messages.import_missing_columns', ['columns' => implode(', ', $missing)])]]);
        }
        if (count($rows) > self::MAX_ROWS) {
            return $this->fail([[0, null, __('messages.import_too_many_rows', ['max' => self::MAX_ROWS])]]);
        }

        $index = array_flip($header);
        $cell  = static fn (array $row, string $col): mixed => array_key_exists($col, $index) ? ($row[$index[$col]] ?? null) : null;

        $errors   = [];
        $sessions = [];
        foreach ($rows as $i => $row) {
            $line = $i + 2; // 1-based, after the header row

            // The template pre-numbers every planned session; a row with
            // nothing but its number is a session the admin left out.
            $filled = array_filter(
                array_map(static fn ($c) => $cell($row, $c), ['date', 'start_time', 'end_time', 'location']),
                static fn ($v) => trim((string) $v) !== '',
            );
            if ($filled === []) {
                continue;
            }

            $date = $this->date($cell($row, 'date'));
            $from = $this->time($cell($row, 'start_time'));
            $to   = $this->time($cell($row, 'end_time'));

            foreach (['date' => $date, 'start_time' => $from, 'end_time' => $to] as $col => $val) {
                if ($val === null) {
                    $raw = trim((string) $cell($row, $col));
                    $errors[] = [$line, $col, $raw === '' ? __('messages.import_cell_required') : __('messages.schedule_'.($col === 'date' ? 'date' : 'time').'_format')];
                }
            }

            $location = trim((string) $cell($row, 'location'));
            if (mb_strlen($location) > self::MAX_LOCATION) {
                $errors[] = [$line, 'location', __('messages.import_cell_too_long')];
            }

            if ($date === null || $from === null || $to === null) {
                continue;
            }
            if ($to <= $from) {
                $errors[] = [$line, 'end_time', __('messages.schedule_end_before_start')];
                continue;
            }

            $sessions[] = [
                'line'     => $line,
                'date'     => $date,
                'from'     => $from,
                'to'       => $to,
                'seconds'  => $this->seconds($to) - $this->seconds($from),
                'location' => $location === '' ? null : $location,
            ];
        }

        if ($sessions === [] && $errors === []) {
            return $this->fail([[0, null, __('messages.schedule_no_sessions')]]);
        }

        // Two sessions of one cohort cannot overlap.
        usort($sessions, static fn ($a, $b) => [$a['date'], $a['from']] <=> [$b['date'], $b['from']]);
        for ($k = 1; $k < count($sessions); $k++) {
            $prev = $sessions[$k - 1];
            $cur  = $sessions[$k];
            if ($prev['date'] === $cur['date'] && $cur['from'] < $prev['to']) {
                $errors[] = [$cur['line'], 'start_time', __('messages.schedule_overlap', ['row' => $prev['line']])];
            }
        }

        if ($errors !== []) {
            return $this->fail($errors);
        }

        return [
            'sessions' => array_map(static fn ($s) => array_diff_key($s, ['line' => true]), $sessions),
            'errors'   => [],
        ];
    }

    /** YYYY-MM-DD text, or an Excel date serial, to Y-m-d; null when it is not a real date. */
    private function date(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return $value > 0 ? CarbonImmutable::instance(ExcelDate::excelToDateTimeObject($value))->toDateString() : null;
        }

        $text = trim((string) $value);
        if (! preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $text, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
    }

    /** HH:MM (24-hour) text, or an Excel time fraction, to H:i:s; null otherwise. */
    private function time(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            if ($value < 0 || $value >= 1) {
                return null;
            }
            $minutes = (int) round($value * 24 * 60);

            return sprintf('%02d:%02d:00', intdiv($minutes, 60) % 24, $minutes % 60);
        }

        if (! preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', trim((string) $value), $m)) {
            return null;
        }

        return sprintf('%02d:%02d:00', $m[1], $m[2]);
    }

    private function seconds(string $time): int
    {
        [$h, $m, $s] = array_map('intval', explode(':', $time));

        return $h * 3600 + $m * 60 + $s;
    }

    private function fail(array $errors): array
    {
        usort($errors, static fn ($a, $b) => $a[0] <=> $b[0]);

        return [
            'sessions' => [],
            'errors'   => array_map(static fn ($e) => ['row' => $e[0], 'column' => $e[1], 'message' => $e[2]], $errors),
        ];
    }
}
