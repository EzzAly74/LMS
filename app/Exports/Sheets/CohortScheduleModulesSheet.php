<?php

namespace App\Exports\Sheets;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Reference sheet of the schedule template: the course's uploaded modules,
 * whose IDs go in the schedule sheet's "content" column (D-079).
 * Not read on upload.
 */
class CohortScheduleModulesSheet implements FromCollection, WithHeadings, WithTitle
{
    /** @param  list<array{id: int, en: string, ar: string}>  $modules */
    public function __construct(private readonly array $modules) {}

    public function title(): string
    {
        return 'modules';
    }

    public function headings(): array
    {
        return ['module_id', 'title_en', 'title_ar'];
    }

    public function collection(): Collection
    {
        return collect($this->modules)->map(static fn (array $m) => [$m['id'], $m['en'], $m['ar']]);
    }
}
