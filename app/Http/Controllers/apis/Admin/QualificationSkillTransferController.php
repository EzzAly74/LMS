<?php

namespace App\Http\Controllers\apis\Admin;

use App\Exports\QualificationSkillsExport;
use App\Http\Controllers\apis\ApiController;
use App\Http\Requests\Api\Admin\AdminQualificationListRequest;
use App\Http\Requests\Api\Admin\QualificationSkillImportRequest;
use App\Models\Admin;
use App\Services\Admin\AdminQualificationService;
use App\Services\Admin\QualificationSkillImportService;
use Illuminate\Http\JsonResponse;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Qualifications import / export (Figma 2066:99852 export menu,
 * 1983:44634 import menu; D-034).
 */
class QualificationSkillTransferController extends ApiController
{
    /** An export is synchronous; this bounds the work one request can do (B-21). */
    public const MAX_EXPORT = 5000;

    private const FORMATS = [
        'xlsx' => ExcelFormat::XLSX,
        'csv'  => ExcelFormat::CSV,
    ];

    public function __construct(
        private readonly QualificationSkillImportService $importer,
        private readonly AdminQualificationService $qualifications,
    ) {}

    /** GET admin/qualification-skills/export?format=xlsx|csv&search= - the list as filtered. */
    public function export(AdminQualificationListRequest $request): BinaryFileResponse
    {
        $format = $request->fileFormat();
        $rows   = $this->qualifications->forExport($request->search(), self::MAX_EXPORT);

        return Excel::download(
            new QualificationSkillsExport($rows),
            'qualifications-'.now()->format('Y-m-d').'.'.$format,
            self::FORMATS[$format],
        );
    }

    /** GET admin/qualification-skills/import-template?format=xlsx|csv - "Download empty template". */
    public function template(AdminQualificationListRequest $request): BinaryFileResponse
    {
        $format = $request->fileFormat();

        return Excel::download(
            new QualificationSkillsExport(collect(), template: true),
            'qualifications-template.'.$format,
            self::FORMATS[$format],
        );
    }

    /**
     * POST admin/qualification-skills/import
     *
     * 200 with a report either way: a rejected file is an outcome to show row
     * by row, not an error to throw away. Nothing is written unless `errors`
     * is empty.
     */
    public function import(QualificationSkillImportRequest $request): JsonResponse
    {
        $by     = $request->user() instanceof Admin ? $request->user() : null;
        $report = $this->importer->import($request->file('file'), $by);

        return $this->success(
            $report['errors'] === [] ? __('messages.created') : __('messages.import_rejected'),
            $report,
        );
    }
}
