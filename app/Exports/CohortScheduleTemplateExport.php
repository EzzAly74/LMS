<?php

namespace App\Exports;

use App\Services\CohortScheduleImportService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * "Download Schedule Template" in the New Cohort modal (Figma 2393:123167).
 *
 * One pre-numbered row per planned session of the course, with the columns
 * CohortScheduleImportService reads: date (YYYY-MM-DD), start_time and
 * end_time (24-hour HH:MM), and an optional location. Date and time columns
 * are formatted as text, so spreadsheet apps keep what is typed instead of
 * converting it; the importer also accepts real date / time cells.
 *
 * When editing a cohort the sheet starts with its sessions as they are, then
 * blank numbered rows for the sessions still to plan (at least EXTRA_ROWS).
 */
class CohortScheduleTemplateExport implements FromCollection, WithHeadings, WithColumnFormatting
{
    use Exportable;

    /** Rows offered when the course has no planned session count. */
    public const DEFAULT_ROWS = 10;

    /** Blank rows after an edited cohort's sessions when its plan is already met. */
    public const EXTRA_ROWS = 5;

    /**
     * @param  list<array{date: string, from: ?string, to: ?string, location: ?string}>  $existing
     */
    public function __construct(private readonly int $sessions, private readonly array $existing = []) {}

    public function headings(): array
    {
        return CohortScheduleImportService::COLUMNS;
    }

    public function collection(): Collection
    {
        if ($this->existing === []) {
            $count = $this->sessions > 0 ? min($this->sessions, CohortScheduleImportService::MAX_ROWS) : self::DEFAULT_ROWS;

            return collect(range(1, $count))->map(static fn (int $n) => [$n, null, null, null, null]);
        }

        $have  = count($this->existing);
        $blank = min(max(self::EXTRA_ROWS, $this->sessions - $have), max(0, CohortScheduleImportService::MAX_ROWS - $have));

        return collect($this->existing)
            ->values()
            ->map(static fn (array $s, int $i) => [$i + 1, $s['date'], $s['from'], $s['to'], $s['location']])
            ->concat($blank > 0 ? array_map(static fn (int $n) => [$n, null, null, null, null], range($have + 1, $have + $blank)) : []);
    }

    public function columnFormats(): array
    {
        return ['B' => NumberFormat::FORMAT_TEXT, 'C' => NumberFormat::FORMAT_TEXT, 'D' => NumberFormat::FORMAT_TEXT];
    }
}
