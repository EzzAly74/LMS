<?php

namespace App\Http\Controllers\apis\Admin;

use App\Exports\QualificationSkillsExport;
use App\Http\Controllers\apis\ApiController;
use App\Http\Requests\Api\Admin\QualificationSkillImportRequest;
use App\Models\QualificationSkill;
use App\Services\Admin\QualificationSkillImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Qualifications import / export (Figma 2066:99852 export menu,
 * 1983:44634 import menu). Neither existed.
 */
class QualificationSkillTransferController extends ApiController
{
    public function __construct(private readonly QualificationSkillImportService $importer) {}

    /** Allowed download formats, mapped to Maatwebsite writer types. */
    private const FORMATS = [
        'xlsx' => ExcelFormat::XLSX,
        'csv'  => ExcelFormat::CSV,
    ];

    /** GET admin/qualification-skills/export?format=xlsx|csv */
    public function export(Request $request): BinaryFileResponse
    {
        $format = $this->resolveFormat($request);

        // Eager-load so the export does not query per row.
        $rows = QualificationSkill::query()->with('jobTitles')->orderBy('id')->get();

        return Excel::download(
            new QualificationSkillsExport($rows),
            'qualifications-'.now()->format('Y-m-d').'.'.$format,
            self::FORMATS[$format],
        );
    }

    /**
     * GET admin/qualification-skills/import-template?format=xlsx|csv
     *
     * The same columns as the export, with no rows — the "template" entry in
     * the import menu. Built from one source of truth so the template can never
     * drift from what the importer accepts.
     */
    public function template(Request $request): BinaryFileResponse
    {
        $format = $this->resolveFormat($request);

        return Excel::download(
            new QualificationSkillsExport(collect()),
            'qualifications-template.'.$format,
            self::FORMATS[$format],
        );
    }

    /** POST admin/qualification-skills/import */
    public function import(QualificationSkillImportRequest $request): JsonResponse
    {
        $report = $this->importer->import($request->file('file'));

        // Always 200 with a per-row report: the Figma flow has an explicit
        // partial-failure state, so "some rows applied, some rejected" is a
        // successful outcome to be rendered, not an error to be thrown away.
        return $this->success(__('messages.updated'), $report);
    }

    private function resolveFormat(Request $request): string
    {
        $format = strtolower((string) $request->query('format', 'xlsx'));

        return array_key_exists($format, self::FORMATS) ? $format : 'xlsx';
    }
}
