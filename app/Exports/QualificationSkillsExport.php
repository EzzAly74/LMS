<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Qualifications export (Figma 2066:99852 — the xlsx / csv menu).
 *
 * The column set deliberately matches the import template exactly, so a file
 * exported here can be edited and fed straight back through the importer
 * without reshaping. That round trip is the point of the feature.
 */
class QualificationSkillsExport implements FromCollection, WithHeadings, WithMapping
{
    use Exportable;

    public const COLUMNS = ['name_en', 'name_ar', 'job_titles'];

    public function __construct(private readonly Collection $rows) {}

    public function collection(): Collection
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return self::COLUMNS;
    }

    public function map($row): array
    {
        return [
            $row->getTranslation('name', 'en'),
            $row->getTranslation('name', 'ar'),
            // Pipe-separated rather than comma, so the value survives CSV
            // without quoting games on job titles that contain commas.
            $row->jobTitles->pluck('name')->implode(' | '),
        ];
    }
}
