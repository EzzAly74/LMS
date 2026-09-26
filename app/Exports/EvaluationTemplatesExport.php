<?php

namespace App\Exports;

use App\Models\EvaluationCategory;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Evaluation templates export and import template (D-034; Figma 2009:88432
 * Import / Export menus).
 *
 * One row per question; the template's own columns repeat on each of its rows,
 * which keeps the file flat enough to edit in any spreadsheet. The import reads
 * exactly these columns (course_name / cohort_name are for people and are
 * ignored on import - course_id / cohort_id decide), so the downloaded template
 * and an export share one definition.
 */
class EvaluationTemplatesExport implements FromCollection, WithHeadings
{
    use Exportable;

    public const COLUMNS = [
        'template_name_en', 'template_name_ar', 'course_id', 'course_name', 'cohort_id', 'cohort_name',
        'question_en', 'question_ar', 'type', 'required',
        'scale_min_label_en', 'scale_min_label_ar', 'scale_max_label_en', 'scale_max_label_ar',
    ];

    /** Stored type -> the word used in files. Legacy types export as they are. */
    public const TYPE_WORDS = ['five' => 'star', 'scale' => 'scale', 'ten' => 'ten', 'text' => 'text'];

    /** @param  Collection<int, EvaluationCategory>  $templates  with evaluations, course, section loaded */
    public function __construct(private readonly Collection $templates) {}

    public function headings(): array
    {
        return self::COLUMNS;
    }

    public function collection(): Collection
    {
        return $this->templates->flatMap(function (EvaluationCategory $t) {
            $base = [
                $t->getTranslation('name', 'en', false),
                $t->getTranslation('name', 'ar', false),
                $t->course_id,
                $t->course?->getTranslation('title', 'en'),
                $t->section_id,
                $t->section?->getTranslation('name', 'en'),
            ];

            return $t->evaluations->map(fn ($q) => array_merge($base, [
                $q->getTranslation('title', 'en', false),
                $q->getTranslation('title', 'ar', false),
                self::TYPE_WORDS[$q->type] ?? $q->type,
                $q->is_required ? 'yes' : 'no',
                $q->type === 'scale' ? $q->getTranslation('scale_label_min', 'en', false) : null,
                $q->type === 'scale' ? $q->getTranslation('scale_label_min', 'ar', false) : null,
                $q->type === 'scale' ? $q->getTranslation('scale_label_max', 'en', false) : null,
                $q->type === 'scale' ? $q->getTranslation('scale_label_max', 'ar', false) : null,
            ]));
        })->values();
    }
}
