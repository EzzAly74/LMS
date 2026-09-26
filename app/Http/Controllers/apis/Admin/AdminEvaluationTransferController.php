<?php

namespace App\Http\Controllers\apis\Admin;

use App\Exports\EvaluationTemplatesExport;
use App\Http\Controllers\apis\ApiController;
use App\Http\Requests\Api\Admin\AdminEvaluationTemplatesRequest;
use App\Http\Requests\Api\Admin\AdminEvaluationImportRequest;
use App\Models\EvaluationCategory;
use App\Services\Admin\AdminEvaluationReportService;
use App\Services\Admin\EvaluationTemplateImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Evaluation templates Import / Export (Figma 2009:88432; D-034).
 *
 * Export covers the list as currently filtered - the same allow-listed filters
 * as GET admin/evaluations/templates - one row per question. The import
 * template is the same columns with no rows.
 */
class AdminEvaluationTransferController extends ApiController
{
    private const FORMATS = ['xlsx' => ExcelFormat::XLSX, 'csv' => ExcelFormat::CSV];

    /** Upper bound on templates in one export; far above any real template count. */
    private const MAX_EXPORT_TEMPLATES = 1000;

    public function __construct(
        private readonly AdminEvaluationReportService $reports,
        private readonly EvaluationTemplateImportService $importer,
    ) {}

    /** GET admin/evaluations/templates/export?format=xlsx|csv&<list filters> */
    public function export(AdminEvaluationTemplatesRequest $request): BinaryFileResponse
    {
        $format = $this->resolveFormat($request);
        $ids    = $this->reports->templates($request->filters(), self::MAX_EXPORT_TEMPLATES)->getCollection()->pluck('id');

        // In the list's order, with everything the rows need loaded up front.
        $templates = EvaluationCategory::query()
            ->whereIn('id', $ids)
            ->with(['evaluations' => fn ($q) => $q->orderBy('id'), 'course:id,title', 'section:id,name'])
            ->get()
            ->sortBy(fn ($t) => $ids->search($t->id))
            ->values();

        return Excel::download(
            new EvaluationTemplatesExport($templates),
            'evaluation-templates-'.now()->format('Y-m-d').'.'.$format,
            self::FORMATS[$format],
        );
    }

    /** GET admin/evaluations/templates/import-template?format=xlsx|csv */
    public function template(Request $request): BinaryFileResponse
    {
        $format = $this->resolveFormat($request);

        return Excel::download(
            new EvaluationTemplatesExport(collect()),
            'evaluation-templates-template.'.$format,
            self::FORMATS[$format],
        );
    }

    /**
     * POST admin/evaluations/templates/import
     *
     * 200 with a report either way: `created` > 0 and no errors, or nothing
     * written and every problem listed by row and column.
     */
    public function import(AdminEvaluationImportRequest $request): JsonResponse
    {
        $report = $this->importer->import($request->file('file'));

        return $this->success(__($report['errors'] === [] ? 'messages.created' : 'messages.import_rejected'), $report);
    }

    private function resolveFormat(Request $request): string
    {
        $format = strtolower((string) $request->query('format', 'xlsx'));

        return array_key_exists($format, self::FORMATS) ? $format : 'xlsx';
    }
}
