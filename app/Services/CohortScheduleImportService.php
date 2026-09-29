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
                'status'             => ($cohort['open_early'] ?? false) ? 'open_for_enrollment' : 'scheduled',
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

    /**
     * Edit Cohort (same dialog as New Cohort): rename, change the capacity and,
     * optionally, upload the schedule again.
     *
     * The sheet is compared with the sessions the cohort already has, by date,
     * start and end time:
     *   - a row that matches an existing session is that session: it is kept,
     *     and only its location may change - and only before it starts;
     *   - every other row is a new session and must start in the future;
     *   - an existing session that the sheet leaves out is kept too, so the
     *     full schedule with extra rows and a sheet of only the new sessions
     *     both add exactly the new ones. Nothing is ever deleted here.
     * New sessions may not overlap each other or any existing session. Any
     * problem returns the full list and changes nothing (the name and capacity
     * included); otherwise everything is written in one transaction, under a
     * lock on the cohort so two uploads cannot both add the same sessions.
     *
     * @param  array{name: array{en: string, ar: string}, capacity: int, open_early: ?bool}  $cohort
     * @return array{section: ?CourseSection, added: int, updated: int, errors: list<array{row: int, column: ?string, message: string}>}
     */
    public function update(Course $course, CourseSection $section, array $cohort, ?UploadedFile $file): array
    {
        $rows = [];
        if ($file !== null) {
            $parsed = $this->parse($file, keepLines: true);
            if ($parsed['errors'] !== []) {
                return ['section' => null, 'added' => 0, 'updated' => 0, 'errors' => $parsed['errors']];
            }
            $rows = $parsed['sessions'];
        }

        return DB::transaction(function () use ($course, $section, $cohort, $rows, $file) {
            // Serialise edits of one cohort: the comparison below must see the
            // sessions another upload may have just added.
            CourseSection::query()->whereKey($section->id)->lockForUpdate()->first();

            $existing = DB::table('course_sessions')
                ->where('section_id', $section->id)
                ->orderBy('session_date')->orderBy('time_from')->orderBy('id')
                ->get(['id', 'title', 'session_date', 'time_from', 'time_to', 'location']);

            $now    = CarbonImmutable::now();
            $byKey  = [];
            foreach ($existing as $s) {
                $byKey[$this->key((string) $s->session_date, (string) $s->time_from, (string) $s->time_to)] = $s;
            }

            $errors = [];
            $new    = [];
            $moved  = [];
            foreach ($rows as $r) {
                $match = $byKey[$this->key($r['date'], $r['from'], $r['to'])] ?? null;
                if ($match !== null) {
                    if ((string) ($match->location ?? '') === (string) ($r['location'] ?? '')) {
                        continue; // unchanged
                    }
                    if ($this->starts($r['date'], $r['from']) <= $now) {
                        $errors[] = [$r['line'], 'location', __('messages.schedule_held_session_locked')];
                        continue;
                    }
                    $moved[$match->id] = $r['location'];
                    continue;
                }
                if ($this->starts($r['date'], $r['from']) <= $now) {
                    $errors[] = [$r['line'], 'date', __('messages.schedule_new_session_in_past')];
                    continue;
                }
                $new[] = $r;
            }

            // New sessions against the ones the cohort keeps (the sheet's own
            // rows were already checked against each other).
            foreach ($new as $r) {
                foreach ($existing as $s) {
                    if ((string) $s->session_date === $r['date'] && $s->time_from !== null && $s->time_to !== null
                        && $r['from'] < (string) $s->time_to && (string) $s->time_from < $r['to']) {
                        $errors[] = [$r['line'], 'start_time', __('messages.schedule_overlap_existing', [
                            'date' => $r['date'], 'from' => substr((string) $s->time_from, 0, 5), 'to' => substr((string) $s->time_to, 0, 5),
                        ])];
                        break;
                    }
                }
            }

            if ($errors === [] && $file !== null && $new === [] && $moved === []) {
                $errors[] = [0, null, __('messages.schedule_nothing_new')];
            }
            if ($errors !== []) {
                return ['section' => null, 'added' => 0, 'updated' => 0, 'errors' => $this->fail($errors)['errors']];
            }

            $stamp = now();
            foreach ($moved as $id => $location) {
                DB::table('course_sessions')->where('id', $id)->update(['location' => $location, 'updated_at' => $stamp]);
            }
            if ($new !== []) {
                DB::table('course_sessions')->insert(array_map(fn ($s) => [
                    'course_id'    => $course->id,
                    'section_id'   => $section->id,
                    'title'        => __('messages.schedule_session_title', ['n' => 0]), // numbered below
                    'session_date' => $s['date'],
                    'time_from'    => $s['from'],
                    'time_to'      => $s['to'],
                    'location'     => $s['location'],
                    'created_at'   => $stamp,
                    'updated_at'   => $stamp,
                ], $new));
            }

            $update = ['name' => $cohort['name'], 'capacity' => $cohort['capacity']];
            // Only meaningful before the cohort starts; after that the calendar decides.
            $started = $section->start_date !== null && CarbonImmutable::parse((string) $section->start_date)->startOfDay() <= $now->startOfDay();
            $status  = $started ? null : $this->enrolmentStatus((string) ($section->status ?? 'scheduled'), $cohort['open_early'] ?? null);
            if ($status !== null) {
                $update['status'] = $status;
            }
            if ($new !== []) {
                $this->renumber($section);
                $update += $this->derived($section);
            }
            $this->cohorts->update($section, $update);

            if ($new !== []) {
                $this->hours->refresh($course);
            }

            return ['section' => $section->refresh(), 'added' => count($new), 'updated' => count($moved), 'errors' => []];
        });
    }

    /**
     * The stored status for the "Open for enrolment early" switch (Q-073).
     * Only the two manual enrolment-window values move; a cohort made
     * `inactive` elsewhere stays so, and the calendar still derives
     * active / completed (Course::deriveCohortStatus). Null = no change.
     */
    private function enrolmentStatus(string $stored, ?bool $openEarly): ?string
    {
        if ($openEarly === null || $stored === 'inactive') {
            return null;
        }
        if ($openEarly) {
            return $stored === 'open_for_enrollment' ? null : 'open_for_enrollment';
        }

        return $stored === 'open_for_enrollment' ? 'scheduled' : null;
    }

    /**
     * "Download Schedule Template" when editing: the cohort's sessions as they
     * are, so the admin adds rows to them (or uploads only new rows).
     *
     * @return list<array{date: string, from: ?string, to: ?string, location: ?string}>
     */
    public function scheduleRows(CourseSection $section): array
    {
        return DB::table('course_sessions')
            ->where('section_id', $section->id)
            ->orderBy('session_date')->orderBy('time_from')->orderBy('id')
            ->get(['session_date', 'time_from', 'time_to', 'location'])
            ->map(fn ($s) => [
                'date'     => substr((string) $s->session_date, 0, 10),
                'from'     => $s->time_from !== null ? substr((string) $s->time_from, 0, 5) : null,
                'to'       => $s->time_to !== null ? substr((string) $s->time_to, 0, 5) : null,
                'location' => $s->location,
            ])
            ->all();
    }

    /**
     * Sessions added by an edit are numbered in date order. They all start in
     * the future, so only upcoming sessions can move; those keep a custom title
     * and only the generated "Session N" / "الجلسة N" ones are renumbered.
     */
    private function renumber(CourseSection $section): void
    {
        $sessions = DB::table('course_sessions')
            ->where('section_id', $section->id)
            ->orderBy('session_date')->orderBy('time_from')->orderBy('id')
            ->get(['id', 'title']);

        $patterns = array_map(
            static fn (string $locale) => '/^'.str_replace('\:n', '\d+', preg_quote(trans('messages.schedule_session_title', [], $locale), '/')).'$/u',
            ['en', 'ar'],
        );

        $n = 0;
        foreach ($sessions as $s) {
            $n++;
            $title = (string) $s->title;
            foreach ($patterns as $i => $pattern) {
                if (preg_match($pattern, $title)) {
                    $wanted = trans('messages.schedule_session_title', ['n' => $n], ['en', 'ar'][$i]);
                    if ($wanted !== $title) {
                        DB::table('course_sessions')->where('id', $s->id)->update(['title' => $wanted]);
                    }
                    break;
                }
            }
        }
    }

    /** The cohort's dates, session count and average length, from all its sessions (as on create). */
    private function derived(CourseSection $section): array
    {
        $sessions = DB::table('course_sessions')->where('section_id', $section->id)->get(['session_date', 'time_from', 'time_to']);
        $dates    = $sessions->map(fn ($s) => substr((string) $s->session_date, 0, 10));
        $lengths  = $sessions->filter(fn ($s) => $s->time_from !== null && $s->time_to !== null)
            ->map(fn ($s) => ($this->seconds((string) $s->time_to) - $this->seconds((string) $s->time_from)) / 3600);

        $out = [
            'start_date'         => $dates->min(),
            'end_date'           => $dates->max(),
            'number_of_sessions' => $sessions->count(),
        ];
        if ($lengths->isNotEmpty()) {
            $out['avg_session_time'] = min(24, max(0.25, round($lengths->avg(), 2)));
        }

        return $out;
    }

    private function key(string $date, string $from, string $to): string
    {
        return substr($date, 0, 10).'|'.substr($from, 0, 5).'|'.substr($to, 0, 5);
    }

    private function starts(string $date, string $from): CarbonImmutable
    {
        return CarbonImmutable::parse(substr($date, 0, 10).' '.$from);
    }

    /** @return array{sessions: list<array{date: string, from: string, to: string, seconds: int, location: ?string}>, errors: list<array{row: int, column: ?string, message: string}>} */
    private function parse(UploadedFile $file, bool $keepLines = false): array
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
            'sessions' => $keepLines ? $sessions : array_map(static fn ($s) => array_diff_key($s, ['line' => true]), $sessions),
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
