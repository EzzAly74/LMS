<?php

namespace App\Http\Controllers\apis\Admin;

use App\Http\Controllers\apis\ApiController;
use App\Http\Requests\Api\Admin\ReportOptionsRequest;
use App\Http\Requests\Api\Admin\ReportRequest;
use App\Models\Admin;
use App\Models\ReportExportLog;
use App\Reports\Column;
use App\Reports\Filter;
use App\Reports\Report;
use App\Reports\ReportContext;
use App\Reports\ReportExporter;
use App\Reports\ReportOptions;
use App\Reports\ReportRegistry;
use App\Services\Admin\CourseScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        return $this->success(__('messages.retrieved'), [
            'columns' => array_map(fn (Column $c) => $c->toArray(), $report->columns($context)),
            'rows' => $page['rows'],
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
        ReportExportLog::query()->create([
            'report_type' => $report->key(),
            'format' => $format,
            'filters' => $context->filters + ($context->search !== null ? ['search' => $context->search] : []),
            'row_count' => $rows,
            'locale' => $context->locale,
            'exported_by_admin_id' => $request->user()?->getKey(),
            'exported_at' => now(),
        ]);

        $admin = $request->user() instanceof Admin ? $request->user() : null;

        return $format === 'csv'
            ? $this->exporter->csv($report, $context, $admin)
            : $this->exporter->xlsx($report, $context, $admin);
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
