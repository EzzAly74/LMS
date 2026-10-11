<?php

namespace App\Exports\Sheets;

use App\Exports\CohortScheduleTemplateExport;
use App\Services\CohortScheduleImportService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * The schedule sheet of CohortScheduleTemplateExport. Date, time and content
 * columns are formatted as text, so spreadsheet apps keep what is typed
 * ("3,5" stays a list, not the number 3.5); the importer also accepts real
 * date / time cells.
 *
 * The formats are shown before upload (NEW2B-6183): a note on each date / time
 * header and an input prompt on every cell of those columns, in the language
 * of the download. The header row itself stays the column names the importer
 * reads.
 */
class CohortScheduleSessionsSheet implements FromCollection, WithHeadings, WithColumnFormatting, WithTitle, WithEvents
{
    /**
     * @param  list<array{date: string, from: ?string, to: ?string, location: ?string, content: list<int>}>  $existing
     */
    public function __construct(private readonly int $sessions, private readonly array $existing = []) {}

    public function title(): string
    {
        return 'schedule';
    }

    public function headings(): array
    {
        return CohortScheduleImportService::COLUMNS;
    }

    public function collection(): Collection
    {
        if ($this->existing === []) {
            $count = $this->sessions > 0
                ? min($this->sessions, CohortScheduleImportService::MAX_ROWS)
                : CohortScheduleTemplateExport::DEFAULT_ROWS;

            return collect(range(1, $count))->map(static fn (int $n) => [$n, null, null, null, null, null]);
        }

        $have  = count($this->existing);
        $blank = min(
            max(CohortScheduleTemplateExport::EXTRA_ROWS, $this->sessions - $have),
            max(0, CohortScheduleImportService::MAX_ROWS - $have),
        );

        return collect($this->existing)
            ->values()
            ->map(static fn (array $s, int $i) => [
                $i + 1, $s['date'], $s['from'], $s['to'], $s['location'],
                $s['content'] === [] ? null : implode(', ', $s['content']),
            ])
            ->concat($blank > 0 ? array_map(static fn (int $n) => [$n, null, null, null, null, null], range($have + 1, $have + $blank)) : []);
    }

    public function columnFormats(): array
    {
        return [
            'B' => NumberFormat::FORMAT_TEXT,
            'C' => NumberFormat::FORMAT_TEXT,
            'D' => NumberFormat::FORMAT_TEXT,
            'F' => NumberFormat::FORMAT_TEXT,
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                $last  = max(2, $sheet->getHighestRow());
                $hints = [
                    'B' => __('messages.schedule_date_format'),
                    'C' => __('messages.schedule_time_format'),
                    'D' => __('messages.schedule_time_format'),
                ];

                foreach ($hints as $column => $hint) {
                    $sheet->getComment("{$column}1")->getText()->createTextRun($hint);

                    $prompt = new DataValidation();
                    $prompt->setType(DataValidation::TYPE_NONE);
                    $prompt->setAllowBlank(true);
                    $prompt->setShowInputMessage(true);
                    $prompt->setPromptTitle((string) $sheet->getCell("{$column}1")->getValue());
                    $prompt->setPrompt($hint);
                    $sheet->setDataValidation("{$column}2:{$column}{$last}", $prompt);
                }
            },
        ];
    }
}
