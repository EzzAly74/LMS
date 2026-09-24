<?php

namespace App\Services\Admin;

use App\Models\JobTitle;
use App\Models\QualificationSkill;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Qualifications importer (Figma 1983:44634 — the "template / xlsx / csv" menu).
 *
 * The Figma flow shows a validation report and a partial-failure state, so this
 * reports per-row outcomes rather than succeeding or failing as a whole. Valid
 * rows are applied; invalid rows are returned with their row number and reason.
 *
 * Applying is wrapped in a transaction so a mid-file crash cannot leave half an
 * import committed. A row that merely fails validation is not a crash — it is
 * skipped and reported.
 *
 * Column set is identical to QualificationSkillsExport, so an exported file can
 * be edited and re-imported unchanged.
 */
class QualificationSkillImportService
{
    /** Hard cap on rows per import, so one upload cannot exhaust memory (B-21 in spirit). */
    public const MAX_ROWS = 2000;

    /**
     * @return array{created:int, updated:int, skipped:int, errors:list<array{row:int, message:string}>}
     */
    public function import(UploadedFile $file): array
    {
        /*
         * A file can pass MIME validation and still be unparseable — a
         * truncated xlsx, a CSV with a stray BOM, or something renamed to
         * .xlsx. PhpSpreadsheet throws in that case, which without this would
         * surface as a 500 and a stack trace. The contract for this endpoint is
         * a validation report, so a parse failure is reported as one.
         */
        try {
            $sheets = Excel::toArray(null, $file);
        } catch (\Throwable $e) {
            report($e);

            return $this->result(errors: [['row' => 0, 'message' => __('messages.import_unreadable')]]);
        }

        $rows = $sheets[0] ?? [];

        if ($rows === []) {
            return $this->result(errors: [['row' => 0, 'message' => __('messages.import_empty')]]);
        }

        $header = array_map(
            static fn ($h) => strtolower(trim((string) $h)),
            array_shift($rows),
        );

        $missing = array_diff(['name_en', 'name_ar'], $header);
        if ($missing !== []) {
            return $this->result(errors: [[
                'row'     => 1,
                'message' => __('messages.import_missing_columns', ['columns' => implode(', ', $missing)]),
            ]]);
        }

        if (count($rows) > self::MAX_ROWS) {
            return $this->result(errors: [[
                'row'     => 0,
                'message' => __('messages.import_too_many_rows', ['max' => self::MAX_ROWS]),
            ]]);
        }

        $index = array_flip($header);

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors  = [];

        // Resolve every referenced job title once rather than per row.
        $jobTitlesByName = JobTitle::query()->get()->keyBy(
            static fn ($jt) => mb_strtolower(trim((string) $jt->name)),
        );

        DB::transaction(function () use ($rows, $index, $jobTitlesByName, &$created, &$updated, &$skipped, &$errors) {
            foreach ($rows as $offset => $row) {
                // +2: one for the header, one because spreadsheets are 1-based,
                // so this matches what the admin sees in Excel.
                $rowNumber = $offset + 2;

                $nameEn = trim((string) ($row[$index['name_en']] ?? ''));
                $nameAr = trim((string) ($row[$index['name_ar']] ?? ''));

                if ($nameEn === '' && $nameAr === '') {
                    $skipped++;
                    continue; // silently skip blank trailing rows
                }

                if ($nameEn === '' || $nameAr === '') {
                    $errors[] = ['row' => $rowNumber, 'message' => __('messages.import_name_required')];
                    $skipped++;
                    continue;
                }

                $existing = QualificationSkill::query()
                    ->whereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(name, "$.en"))) = ?', [mb_strtolower($nameEn)])
                    ->first();

                if ($existing === null) {
                    $skill = new QualificationSkill();
                    $created++;
                } else {
                    $skill = $existing;
                    $updated++;
                }

                $skill->setTranslation('name', 'en', $nameEn);
                $skill->setTranslation('name', 'ar', $nameAr);
                $skill->save();

                if (! isset($index['job_titles'])) {
                    continue;
                }

                $raw = trim((string) ($row[$index['job_titles']] ?? ''));
                if ($raw === '') {
                    continue;
                }

                $ids     = [];
                $unknown = [];
                foreach (preg_split('/[|,]/', $raw) as $name) {
                    $name = mb_strtolower(trim($name));
                    if ($name === '') {
                        continue;
                    }
                    if ($jobTitlesByName->has($name)) {
                        $ids[] = $jobTitlesByName->get($name)->id;
                    } else {
                        $unknown[] = $name;
                    }
                }

                // An unknown job title is reported, not invented: creating one
                // on the fly would let a typo silently add a job title.
                if ($unknown !== []) {
                    $errors[] = [
                        'row'     => $rowNumber,
                        'message' => __('messages.import_unknown_job_titles', ['names' => implode(', ', $unknown)]),
                    ];
                }

                if ($ids !== []) {
                    $skill->jobTitles()->syncWithoutDetaching($ids);
                }
            }
        });

        return $this->result($created, $updated, $skipped, $errors);
    }

    /** The importable column set, also used to build the downloadable template. */
    public function templateColumns(): array
    {
        return \App\Exports\QualificationSkillsExport::COLUMNS;
    }

    private function result(int $created = 0, int $updated = 0, int $skipped = 0, array $errors = []): array
    {
        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors'  => $errors,
        ];
    }
}
