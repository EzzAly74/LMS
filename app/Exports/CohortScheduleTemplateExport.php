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
 */
class CohortScheduleTemplateExport implements FromCollection, WithHeadings, WithColumnFormatting
{
    use Exportable;

    /** Rows offered when the course has no planned session count. */
    public const DEFAULT_ROWS = 10;

    public function __construct(private readonly int $sessions) {}

    public function headings(): array
    {
        return CohortScheduleImportService::COLUMNS;
    }

    public function collection(): Collection
    {
        $count = $this->sessions > 0 ? min($this->sessions, CohortScheduleImportService::MAX_ROWS) : self::DEFAULT_ROWS;

        return collect(range(1, $count))->map(static fn (int $n) => [$n, null, null, null, null]);
    }

    public function columnFormats(): array
    {
        return ['B' => NumberFormat::FORMAT_TEXT, 'C' => NumberFormat::FORMAT_TEXT, 'D' => NumberFormat::FORMAT_TEXT];
    }
}
