<?php

namespace App\Exports\Sheets;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Reference sheet of the schedule template: the course's Content tab -
 * modules, quizzes and assignments with their number there, which goes in
 * the schedule sheet's "content" column (D-079, D-109). Not read on upload.
 */
class CohortScheduleModulesSheet implements FromCollection, WithHeadings, WithTitle
{
    /** @param  list<array{id: int|string, en: string, ar: string, type?: string}>  $modules  id = Content-tab number */
    public function __construct(private readonly array $modules) {}

    public function title(): string
    {
        return 'modules';
    }

    public function headings(): array
    {
        return ['number', 'title_en', 'title_ar', 'type'];
    }

    public function collection(): Collection
    {
        return collect($this->modules)->map(static fn (array $m) => [$m['id'], $m['en'], $m['ar'], $m['type'] ?? 'module']);
    }
}
