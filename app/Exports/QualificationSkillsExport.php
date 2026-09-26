<?php

namespace App\Exports;

use App\Models\QualificationSkill;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Qualifications export and import template (D-034; Figma 2066:99852 export
 * menu, 1983:44634 import menu).
 *
 * The template is the importable columns only - the New Qualification modal's
 * fields. An export adds the list's figures after them, for people; the
 * importer ignores those, so an exported file keeps the importable shape.
 */
class QualificationSkillsExport implements FromCollection, WithHeadings, WithMapping
{
    use Exportable;

    public const TEMPLATE_COLUMNS = ['name_en', 'name_ar', 'job_titles', 'learner_employee_ids'];

    public const FIGURE_COLUMNS = ['linked_courses', 'enrolled', 'job_titles_count', 'learners', 'certified', 'completion_percent'];

    /**
     * @param  Collection<int, QualificationSkill>  $rows  from AdminQualificationService::forExport()
     * @param  bool  $template  headings of the importable columns only, no rows
     */
    public function __construct(private readonly Collection $rows, private readonly bool $template = false) {}

    public function collection(): Collection
    {
        return $this->template ? collect() : $this->rows;
    }

    public function headings(): array
    {
        return $this->template ? self::TEMPLATE_COLUMNS : [...self::TEMPLATE_COLUMNS, ...self::FIGURE_COLUMNS];
    }

    /** @param  QualificationSkill  $row */
    public function map($row): array
    {
        return [
            $row->getTranslation('name', 'en', false),
            $row->getTranslation('name', 'ar', false),
            // "|" rather than a comma, so the value survives CSV without
            // quoting games on job titles that contain commas.
            $row->jobTitles->map(fn ($jt) => $jt->getLocalizedName())->implode(' | '),
            implode(' | ', $row->granted_employee_ids ?? []),
            (int) $row->courses_count,
            (int) $row->enrolled_count,
            (int) $row->job_titles_count,
            (int) $row->learners_count,
            (int) $row->certified_count,
            $row->completion_percent,
        ];
    }
}
