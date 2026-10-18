<?php

namespace App\Http\Controllers\apis\Admin;

use App\Http\Controllers\apis\ApiController;
use App\Http\Requests\Api\Admin\ReportExportAllRequest;
use App\Http\Requests\Api\Admin\ReportOptionsRequest;
use App\Http\Requests\Api\Admin\ReportRequest;
use App\Models\Admin;
use App\Models\ReportExportLog;
use App\Reports\Column;
use App\Reports\Filter;
use App\Reports\PreviewColumn;
use App\Reports\Report;
use App\Reports\ReportContext;
use App\Reports\ReportExporter;
use App\Reports\ReportOptions;
use App\Reports\ReportRegistry;
use App\Services\Admin\CourseScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ZipArchive;
use Symfony\Component\HttpFoundation\Response;

/**
 * The NAS reports (D-112): the catalogue, one page of a report, its filter
 * choices and its export. Every route needs view-reports (section:reports);
 * a report may ask for more (Report::allows). Every row is limited to the
 * reader's course scope (D-074).
 */
class AdminReportController extends ApiController
{
    public function __construct(
        private readonly ReportRegistry $registry,
        private readonly ReportOptions $options,
        private readonly ReportExporter $exporter,
    ) {}

    /** GET admin/reports */
    public function index(Request $request): JsonResponse
    {
        $admin = $request->user();
        $context = new ReportContext([], app()->getLocale(), app(CourseScope::class)->courseIds($admin));
        $lastExported = ReportExportLog::query()
            ->selectRaw('report_type, MAX(exported_at) AS last_at')
            ->groupBy('report_type')
            ->pluck('last_at', 'report_type');

        $reports = array_values(array_map(fn (Report $report) => [
            'key' => $report->key(),
            'name' => $report->name(),
            'description' => $report->description(),
            'page_description' => $report->pageDescription(),
            'row_unit' => $report->rowUnit(),
            'columns' => array_map(fn (Column $c) => $c->toArray(), $report->columns($context)),
            'filters' => array_map(fn (Filter $f) => $f->toArray(), $report->filters()),
            'last_exported_at' => $lastExported[$report->key()] ?? null,
        ], array_filter($this->registry->all(), fn (Report $r) => $this->allows($r, $admin))));

        return $this->success(__('messages.retrieved'), $reports);
    }

    /** GET admin/reports/options/{source} */
    public function options(ReportOptionsRequest $request): JsonResponse
    {
        return $this->success(__('messages.retrieved'), $this->options->options(
            $request->source(),
            $request->context(),
            $request->search(),
            $request->courseIds(),
        ));
    }

    /** GET admin/reports/{report} */
    public function show(ReportRequest $request): JsonResponse
    {
        $report = $request->report();
        if ($denied = $this->refuse($report, $request->user())) {
            return $denied;
        }

        $context = $request->context();
        $perPage = $request->perPage();
        $page = $report->page($context, $request->pageNumber(), $perPage);
        $preview = $request->boolean('preview');

        return $this->success(__('messages.retrieved'), [
            'columns' => $preview
                ? array_map(fn (PreviewColumn $c) => $c->toArray(), $report->preview($context))
                : array_map(fn (Column $c) => $c->toArray(), $report->columns($context)),
            'rows' => $preview ? $report->previewRows($page['rows'], $context) : $page['rows'],
            // The filters actually applied, the defaults included (the template).
            'filters' => $context->filters,
            'total' => $page['total'],
            'current_page' => $request->pageNumber(),
            'per_page' => $perPage,
            'last_page' => max(1, (int) ceil($page['total'] / $perPage)),
        ]);
    }

    /** GET admin/reports/{report}/export?format=xlsx|csv */
    public function export(ReportRequest $request): Response
    {
        $report = $request->report();
        if ($denied = $this->refuse($report, $request->user())) {
            return $denied;
        }

        $context = $request->context();
        $rows = $report->count($context);
        if ($rows > ReportExporter::MAX_ROWS) {
            return $this->error(__('messages.reports.too_many_rows', [
                'count' => number_format($rows), 'max' => number_format(ReportExporter::MAX_ROWS),
            ]), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $format = $request->fileFormat();
        $admin = $request->user() instanceof Admin ? $request->user() : null;
        $this->logExport($report, $format, $context, $rows, $admin);

        return $format === 'csv'
            ? $this->exporter->csv($report, $context, $admin)
            : $this->exporter->xlsx($report, $context, $admin);
    }

    /**
     * GET admin/reports/export-all?format=xlsx|csv (Figma 2201:81876, "Export
     * All"): every report the reader may open, unfiltered (an evaluation
     * report on its default template), one file each, in a zip. Refused when
     * a report is over the export limit: that one is exported on its own with
     * filters. The temporary files are removed whatever happens.
     */
    public function exportAll(ReportExportAllRequest $request): Response
    {
        $admin = $request->user() instanceof Admin ? $request->user() : null;
        $format = $request->fileFormat();
        $courseIds = app(CourseScope::class)->courseIds($request->user());
        $locale = app()->getLocale();

        $jobs = [];
        $tooBig = [];
        foreach (array_filter($this->registry->all(), fn (Report $r) => $this->allows($r, $admin)) as $report) {
            $context = new ReportContext(ReportRequest::withDefaults($report, [], $courseIds), $locale, $courseIds);
            $rows = $report->count($context);
            $rows > ReportExporter::MAX_ROWS ? $tooBig[] = $report->name() : $jobs[] = [$report, $context, $rows];
        }
        if ($tooBig !== []) {
            return $this->error(__('messages.reports.too_many_rows_all', [
                'reports' => implode(', ', $tooBig), 'max' => number_format(ReportExporter::MAX_ROWS),
            ]), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $dir = storage_path('app/reports-tmp/'.Str::uuid());
        File::ensureDirectoryExists($dir);
        $zipPath = $dir.'.zip';
        try {
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                return $this->error(__('messages.server_error'), Response::HTTP_INTERNAL_SERVER_ERROR);
            }
            foreach ($jobs as [$report, $context, $rows]) {
                $file = $dir.'/'.$this->exporter->filename($report, $format);
                $this->exporter->writeFile($report, $context, $admin, $format, $file);
                $zip->addFile($file, basename($file));
                $this->logExport($report, $format, $context, $rows, $admin);
            }
            $zip->close();
        } finally {
            File::deleteDirectory($dir);
        }

        return response()->download($zipPath, 'NAS-reports-'.now()->format('Y-m-d-His').'.zip', ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend();
    }

    private function logExport(Report $report, string $format, ReportContext $context, int $rows, ?Admin $admin): void
    {
        ReportExportLog::query()->create([
            'report_type' => $report->key(),
            'format' => $format,
            'filters' => $context->filters + ($context->search !== null ? ['search' => $context->search] : []),
            'row_count' => $rows,
            'locale' => $context->locale,
            'exported_by_admin_id' => $admin?->getKey(),
            'exported_at' => now(),
        ]);
    }

    private function refuse(?Report $report, mixed $admin): ?JsonResponse
    {
        if ($report === null) {
            return $this->notFound();
        }

        return $this->allows($report, $admin) ? null : $this->error(__('messages.forbidden'), Response::HTTP_FORBIDDEN);
    }

    private function allows(Report $report, mixed $admin): bool
    {
        return $admin instanceof Admin && $report->allows($admin);
    }
}
