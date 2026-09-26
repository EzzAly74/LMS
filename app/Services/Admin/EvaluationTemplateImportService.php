<?php

namespace App\Services\Admin;

use App\Http\Requests\Api\Admin\AdminEvaluationTemplateRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Evaluation templates import (D-034, chosen by the human 2026-09-26:
 * templates with their questions, all or nothing).
 *
 * The whole file is validated first. If any row fails, nothing is written and
 * the report lists every problem as (row, column, message) - a half-imported
 * questionnaire would be put to learners with questions missing, and cannot be
 * cleanly taken back once someone answers it. Otherwise every template is
 * created in one transaction.
 *
 * Rows are grouped into templates by their two names. A name already used by
 * another template (either language, ignoring case) is an error, never an
 * overwrite.
 */
class EvaluationTemplateImportService
{
    public const MAX_ROWS = 5000;

    private const REQUIRED_COLUMNS = ['template_name_en', 'template_name_ar', 'question_en', 'question_ar', 'type', 'required'];

    private const TYPES = ['star' => 'five', 'scale' => 'scale'];

    private const YES = ['yes', 'y', 'true', '1', 'نعم'];

    private const NO = ['no', 'n', 'false', '0', 'لا', ''];

    public function __construct(private readonly EvaluationTemplateService $templates) {}

    /** @return array{created:int, questions:int, errors:list<array{row:int, column:?string, message:string}>} */
    public function import(UploadedFile $file): array
    {
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

        $header = array_map(static fn ($h) => strtolower(trim((string) $h)), array_shift($rows));
        $missing = array_diff(self::REQUIRED_COLUMNS, $header);
        if ($missing !== []) {
            return $this->fail([[1, null, __('messages.import_missing_columns', ['columns' => implode(', ', $missing)])]]);
        }
        if (count($rows) > self::MAX_ROWS) {
            return $this->fail([[0, null, __('messages.import_too_many_rows', ['max' => self::MAX_ROWS])]]);
        }

        $index  = array_flip($header);
        $cell   = static fn (array $row, string $col): string => array_key_exists($col, $index) ? trim((string) ($row[$index[$col]] ?? '')) : '';
        $errors = [];
        $groups = [];

        foreach ($rows as $i => $row) {
            $line = $i + 2; // 1-based, after the header row
            if (implode('', array_map(static fn ($v) => trim((string) $v), $row)) === '') {
                continue; // a blank line in the sheet
            }

            $nameEn = $cell($row, 'template_name_en');
            $nameAr = $cell($row, 'template_name_ar');
            foreach (['template_name_en' => $nameEn, 'template_name_ar' => $nameAr, 'question_en' => $cell($row, 'question_en'), 'question_ar' => $cell($row, 'question_ar')] as $col => $val) {
                if ($val === '') {
                    $errors[] = [$line, $col, __('messages.import_cell_required')];
                } elseif (mb_strlen($val) > (str_starts_with($col, 'template') ? 191 : 500)) {
                    $errors[] = [$line, $col, __('messages.import_cell_too_long')];
                }
            }

            $typeWord = strtolower($cell($row, 'type'));
            $type     = self::TYPES[$typeWord] ?? null;
            if ($type === null) {
                $errors[] = [$line, 'type', __('messages.import_evaluation_type')];
            }

            $requiredWord = mb_strtolower($cell($row, 'required'));
            $required     = in_array($requiredWord, self::YES, true) ? true : (in_array($requiredWord, self::NO, true) ? false : null);
            if ($required === null) {
                $errors[] = [$line, 'required', __('messages.import_yes_no')];
            }

            $labels = [];
            if ($type === 'scale') {
                foreach (['scale_min_label_en', 'scale_min_label_ar', 'scale_max_label_en', 'scale_max_label_ar'] as $col) {
                    $labels[$col] = $cell($row, $col);
                    if ($labels[$col] === '') {
                        $errors[] = [$line, $col, __('messages.import_cell_required')];
                    } elseif (mb_strlen($labels[$col]) > AdminEvaluationTemplateRequest::MAX_LABEL) {
                        $errors[] = [$line, $col, __('messages.import_cell_too_long')];
                    }
                }
            }

            $courseId = $cell($row, 'course_id');
            $cohortId = $cell($row, 'cohort_id');
            foreach (['course_id' => $courseId, 'cohort_id' => $cohortId] as $col => $val) {
                if ($val !== '' && ! ctype_digit($val)) {
                    $errors[] = [$line, $col, __('messages.import_id_number')];
                }
            }

            $key = mb_strtolower($nameEn).'|'.mb_strtolower($nameAr);
            $groups[$key] ??= [
                'first_line' => $line,
                'name'       => ['en' => $nameEn, 'ar' => $nameAr],
                'course_id'  => $courseId,
                'cohort_id'  => $cohortId,
                'questions'  => [],
            ];
            if ($groups[$key]['course_id'] !== $courseId || $groups[$key]['cohort_id'] !== $cohortId) {
                $errors[] = [$line, 'course_id', __('messages.import_scope_mismatch')];
            }
            $groups[$key]['questions'][] = [
                'title'    => ['en' => $cell($row, 'question_en'), 'ar' => $cell($row, 'question_ar')],
                'type'     => $type,
                'required' => (bool) $required,
                'scale_label_min' => $type === 'scale' ? ['en' => $labels['scale_min_label_en'], 'ar' => $labels['scale_min_label_ar']] : null,
                'scale_label_max' => $type === 'scale' ? ['en' => $labels['scale_max_label_en'], 'ar' => $labels['scale_max_label_ar']] : null,
            ];
        }

        if ($groups === [] && $errors === []) {
            return $this->fail([[0, null, __('messages.import_empty')]]);
        }

        $this->checkTemplates($groups, $errors);

        if ($errors !== []) {
            return $this->fail($errors);
        }

        $questions = 0;
        DB::transaction(function () use ($groups, &$questions) {
            foreach ($groups as $g) {
                $this->templates->create([
                    'name'       => $g['name'],
                    'course_id'  => $g['course_id'] === '' ? null : (int) $g['course_id'],
                    'section_id' => $g['cohort_id'] === '' ? null : (int) $g['cohort_id'],
                    'questions'  => $g['questions'],
                ]);
                $questions += count($g['questions']);
            }
        });

        return ['created' => count($groups), 'questions' => $questions, 'errors' => []];
    }

    /** Per-template checks: size, scope, and names unique in the file and the database. */
    private function checkTemplates(array $groups, array &$errors): void
    {
        $seen = ['en' => [], 'ar' => []];
        $courseIds = array_filter(array_map(static fn ($g) => $g['course_id'], $groups), static fn ($v) => $v !== '' && ctype_digit($v));
        $evaluable = DB::table('courses')->whereIn('id', $courseIds)->where('is_evaluate', 1)->pluck('id')->map(fn ($v) => (string) $v)->all();

        // Two bulk lookups instead of queries per template: cohort -> course,
        // and every existing template name in both languages.
        $cohortIds = array_filter(array_map(static fn ($g) => $g['cohort_id'], $groups), static fn ($v) => $v !== '' && ctype_digit($v));
        $cohortCourse = DB::table('course_sections')->whereIn('id', $cohortIds)->pluck('course_id', 'id')
            ->mapWithKeys(fn ($course, $id) => [(string) $id => (string) $course])->all();
        $existing = ['en' => [], 'ar' => []];
        foreach (DB::table('evaluation_categories')->pluck('name') as $raw) {
            $decoded = json_decode((string) $raw, true);
            foreach (['en', 'ar'] as $locale) {
                $value = is_array($decoded) ? ($decoded[$locale] ?? '') : (string) $raw;
                if (is_string($value) && $value !== '') {
                    $existing[$locale][mb_strtolower(trim($value))] = true;
                }
            }
        }

        foreach ($groups as $g) {
            $line = $g['first_line'];

            if (count($g['questions']) > AdminEvaluationTemplateRequest::MAX_QUESTIONS) {
                $errors[] = [$line, 'question_en', __('messages.import_too_many_questions', ['max' => AdminEvaluationTemplateRequest::MAX_QUESTIONS])];
            }

            if ($g['course_id'] !== '' && ctype_digit($g['course_id']) && ! in_array($g['course_id'], $evaluable, true)) {
                $errors[] = [$line, 'course_id', __('messages.import_course_not_evaluable')];
            }
            if ($g['cohort_id'] !== '' && ctype_digit($g['cohort_id'])) {
                if ($g['course_id'] === '' || ($cohortCourse[$g['cohort_id']] ?? null) !== $g['course_id']) {
                    $errors[] = [$line, 'cohort_id', __('messages.evaluation_cohort_mismatch')];
                }
            }

            foreach (['en', 'ar'] as $locale) {
                $name = mb_strtolower($g['name'][$locale]);
                if ($name === '') {
                    continue;
                }
                if (isset($seen[$locale][$name])) {
                    $errors[] = [$line, "template_name_{$locale}", __('messages.import_duplicate_in_file')];
                }
                $seen[$locale][$name] = true;

                if (isset($existing[$locale][$name])) {
                    $errors[] = [$line, "template_name_{$locale}", __('messages.evaluation_name_taken')];
                }
            }
        }
    }

    /** @param  list<array{0:int,1:?string,2:string}>  $errors */
    private function fail(array $errors): array
    {
        usort($errors, static fn ($a, $b) => $a[0] <=> $b[0]);

        return [
            'created'   => 0,
            'questions' => 0,
            'errors'    => array_map(static fn ($e) => ['row' => $e[0], 'column' => $e[1], 'message' => $e[2]], $errors),
        ];
    }
}
