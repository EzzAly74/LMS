<?php

use App\Http\Controllers\apis\Admin\AdminReportController;
use App\Reports\ReportOptions;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin Reports Routes - /api/v1/admin/reports (D-112)
|--------------------------------------------------------------------------
|
| The NAS reports: the catalogue, a page of one report, the filter choices
| and the export. view-reports for all (section:reports); exports are
| expensive, so they get their own limit. The legacy routes in
| routes/apis/reports.php are not part of this module.
*/

Route::middleware(['auth.user', 'role:Admin', 'section:reports'])->prefix('admin')->group(function () {
    Route::get('reports', [AdminReportController::class, 'index'])->name('admin.reports.index');
    Route::get('reports/options/{source}', [AdminReportController::class, 'options'])
        ->whereIn('source', ReportOptions::SOURCES)
        ->middleware('throttle:120,1')
        ->name('admin.reports.options');
    Route::get('reports/export-all', [AdminReportController::class, 'exportAll'])
        ->middleware('throttle:10,1')
        ->name('admin.reports.export-all');
    Route::get('reports/{report}/export', [AdminReportController::class, 'export'])
        ->where('report', '[a-z0-9-]+')
        ->middleware('throttle:10,1')
        ->name('admin.reports.export');
    Route::get('reports/{report}', [AdminReportController::class, 'show'])
        ->where('report', '[a-z0-9-]+')
        ->name('admin.reports.show');
});
