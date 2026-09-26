<?php

namespace App\Services\Admin;

use App\Exports\QualificationSkillsExport;
use App\Http\Requests\Api\Admin\AdminQualificationRequest;
use App\Models\Admin;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Qualifications import (Figma 1983:44634 - "Download empty template",
 * "Import Excel (.xlsx)", "Import CSV (.csv)").
 *
 * Follows D-034, like the evaluation import: the whole file is validated
 * first, and if any row fails nothing is written and every problem comes back
 * as (row, column, message). Otherwise every qualification is created, with
 * its job titles and direct grants, in one transaction.
 *
 * B4 shipped this as "apply the good rows, report the bad ones", and matched
 * existing qualifications by English name and OVERWROTE their Arabic name.
 * Both contradicted D-034: a partial import cannot be taken back cleanly, and
 * a typo in one cell silently renamed a qualification learners already hold.
 * A name already in use (either language, ignoring case) is now an error.
 *
 * Columns (QualificationSkillsExport::TEMPLATE_COLUMNS):
 *   name_en, name_ar             required
 *   job_titles                   optional; names separated by "|" - English,
 *                                Arabic or HR name, ignoring case
 *   learner_employee_ids         optional; employee IDs separated by "|"
 * Any other column (an export's figures) is ignored.
 */
class QualificationSkillImportService
{
    public const MAX_ROWS = 5000;

    private const REQUIRED_COLUMNS = ['name_en', 'name_ar'];

    public function __construct(private readonly AdminQualificationService $qualifications) {}

    /** @return array{created:int, errors:list<array{row:int, column:?string, message:string}>} */
    public function import(UploadedFile $file, ?Admin $by = null): array
    {
        /*
         * A file can pass MIME validation and still be unparseable - a
         * truncated xlsx, or something renamed. PhpSpreadsheet throws then;
         * the contract here is a report, not a 500 with a stack trace.
         */
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
        $cell  = static fn (array $row, string $col): string => array_key_exists($col, $index) ? trim((string) ($row[$index[$col]] ?? '')) : '';
        $split = static fn (string $v): array => array_values(array_filter(array_map('trim', explode('|', $v)), static fn ($p) => $p !== ''));

        $parsed = [];
        $errors = [];
        foreach ($rows as $i => $row) {
            $line = $i + 2; // 1-based, after the header row - what the admin sees in Excel
            if (implode('', array_map(static fn ($v) => trim((string) $v), $row)) === '') {
                continue; // a blank line in the sheet
            }

            $name = ['en' => $cell($row, 'name_en'), 'ar' => $cell($row, 'name_ar')];
            foreach ($name as $locale => $value) {
                if ($value === '') {
                    $errors[] = [$line, "name_{$locale}", __('messages.import_cell_required')];
                } elseif (mb_strlen($value) > AdminQualificationRequest::MAX_NAME) {
                    $errors[] = [$line, "name_{$locale}", __('messages.import_cell_too_long')];
                }
            }

            $employeeIds = $split($cell($row, 'learner_employee_ids'));
            if (count($employeeIds) > AdminQualificationService::MAX_LEARNERS) {
                $errors[] = [$line, 'learner_employee_ids', __('messages.import_too_many_learners', ['max' => AdminQualificationService::MAX_LEARNERS])];
            }

            $parsed[] = [
                'line'         => $line,
                'name'         => $name,
                'job_titles'   => $split($cell($row, 'job_titles')),
                'employee_ids' => $employeeIds,
            ];
        }

        if ($parsed === []) {
            return $this->fail([[0, null, __('messages.import_empty')]]);
        }

        $this->checkNames($parsed, $errors);
        $jobTitleIds = $this->resolveJobTitles($parsed, $errors);
        $learnerIds  = $this->resolveLearners($parsed, $errors);

        if ($errors !== []) {
            return $this->fail($errors);
        }

        DB::transaction(function () use ($parsed, $jobTitleIds, $learnerIds, $by) {
            foreach ($parsed as $k => $p) {
                $this->qualifications->create([
                    'name'          => $p['name'],
                    'job_title_ids' => $jobTitleIds[$k],
                    'learner_ids'   => $learnerIds[$k],
                ], $by);
            }
        });

        return ['created' => count($parsed), 'errors' => []];
    }

    /** Names unique within the file and against the database, per language. */
    private function checkNames(array $parsed, array &$errors): void
    {
        $existing = ['en' => [], 'ar' => []];
        foreach (DB::table('qualification_skills')->pluck('name') as $raw) {
            $decoded = json_decode((string) $raw, true);
            foreach (['en', 'ar'] as $locale) {
                $value = is_array($decoded) ? ($decoded[$locale] ?? '') : '';
                if (is_string($value) && $value !== '') {
                    $existing[$locale][mb_strtolower(trim($value))] = true;
                }
            }
        }

        $seen = ['en' => [], 'ar' => []];
        foreach ($parsed as $p) {
            foreach (['en', 'ar'] as $locale) {
                $name = mb_strtolower($p['name'][$locale]);
                if ($name === '') {
                    continue;
                }
                if (isset($seen[$locale][$name])) {
                    $errors[] = [$p['line'], "name_{$locale}", __('messages.import_qualification_duplicate_in_file')];
                } elseif (isset($existing[$locale][$name])) {
                    $errors[] = [$p['line'], "name_{$locale}", __('messages.qualification_name_taken')];
                }
                $seen[$locale][$name] = true;
            }
        }
    }

    /**
     * Job title names -> ids, one lookup for the whole file. A name that
     * matches no job title, or more than one, is an error - never a new job
     * title, which is how a typo would otherwise add one.
     *
     * @return array<int, list<int>> keyed like $parsed
     */
    private function resolveJobTitles(array $parsed, array &$errors): array
    {
        $byName = [];
        foreach (DB::table('job_titles')->get(['id', 'name', 'name_en', 'name_ar']) as $jt) {
            $names = array_unique(array_filter(array_map(
                static fn ($v) => mb_strtolower(trim((string) $v)),
                [$jt->name, $jt->name_en, $jt->name_ar],
            ), static fn ($v) => $v !== ''));
            foreach ($names as $n) {
                $byName[$n][] = (int) $jt->id;
            }
        }

        $out = [];
        foreach ($parsed as $k => $p) {
            $ids = [];
            foreach ($p['job_titles'] as $name) {
                $matches = array_values(array_unique($byName[mb_strtolower($name)] ?? []));
                if ($matches === []) {
                    $errors[] = [$p['line'], 'job_titles', __('messages.import_unknown_job_title', ['name' => $name])];
                } elseif (count($matches) > 1) {
                    $errors[] = [$p['line'], 'job_titles', __('messages.import_ambiguous_job_title', ['name' => $name])];
                } else {
                    $ids[] = $matches[0];
                }
            }
            $out[$k] = array_values(array_unique($ids));
        }

        return $out;
    }

    /**
     * Employee IDs -> learner ids, one lookup for the whole file. The column
     * is not unique in `users`, so an ID shared by two people is an error
     * rather than a guess at which of them was meant.
     *
     * @return array<int, list<int>> keyed like $parsed
     */
    private function resolveLearners(array $parsed, array &$errors): array
    {
        $wanted = array_values(array_unique(array_merge(...array_map(static fn ($p) => $p['employee_ids'], $parsed))));

        $byCode = [];
        foreach (array_chunk($wanted, 1000) as $chunk) {
            foreach (DB::table('users')->whereIn('machine_code', $chunk)->get(['id', 'machine_code']) as $u) {
                $byCode[mb_strtolower(trim((string) $u->machine_code))][] = (int) $u->id;
            }
        }

        $out = [];
        foreach ($parsed as $k => $p) {
            $ids = [];
            foreach ($p['employee_ids'] as $code) {
                $matches = $byCode[mb_strtolower($code)] ?? [];
                if ($matches === []) {
                    $errors[] = [$p['line'], 'learner_employee_ids', __('messages.import_unknown_employee', ['id' => $code])];
                } elseif (count($matches) > 1) {
                    $errors[] = [$p['line'], 'learner_employee_ids', __('messages.import_ambiguous_employee', ['id' => $code])];
                } else {
                    $ids[] = $matches[0];
                }
            }
            $out[$k] = array_values(array_unique($ids));
        }

        return $out;
    }

    /** The importable columns, which the downloadable template carries. */
    public function templateColumns(): array
    {
        return QualificationSkillsExport::TEMPLATE_COLUMNS;
    }

    /** @param  list<array{0:int,1:?string,2:string}>  $errors */
    private function fail(array $errors): array
    {
        usort($errors, static fn ($a, $b) => $a[0] <=> $b[0]);

        return [
            'created' => 0,
            'errors'  => array_map(static fn ($e) => ['row' => $e[0], 'column' => $e[1], 'message' => $e[2]], $errors),
        ];
    }
}
