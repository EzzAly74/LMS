<?php

use App\Http\Controllers\apis\Admin\AdminEvaluationReportController;
use App\Http\Controllers\apis\Admin\AdminEvaluationTemplateController;
use App\Http\Controllers\apis\Admin\AdminEvaluationTransferController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Evaluation template builder, import and export (D4, D-054)
|--------------------------------------------------------------------------
| Figma 2409:132793 / 2409:133222 (builder) and the Import / Export menus of
| 2009:88432. These replace the legacy evaluation-categories / evaluations
| CRUD, which no frontend called and which could edit a template after
| learners had answered it (retired per Q-060).
|
| Import and export are expensive, so they carry the same tighter limiter as
| the qualifications import (B-06). Static segments come before {template}.
*/
Route::middleware(['auth.user', 'role:Admin', 'section:evaluations'])->prefix('admin')->group(function () {
    Route::get('evaluations/templates/export', [AdminEvaluationTransferController::class, 'export'])
        ->middleware('throttle:10,1')->name('admin.evaluations.export');
    Route::get('evaluations/templates/import-template', [AdminEvaluationTransferController::class, 'template'])
        ->middleware('throttle:10,1')->name('admin.evaluations.import-template');
    Route::post('evaluations/templates/import', [AdminEvaluationTransferController::class, 'import'])->ability('create')
        ->middleware('throttle:10,1')->name('admin.evaluations.import');

    Route::get('evaluations/templates/options', [AdminEvaluationTemplateController::class, 'options'])
        ->name('admin.evaluations.templates.options');
    Route::post('evaluations/templates', [AdminEvaluationTemplateController::class, 'store'])->ability('create')
        ->name('admin.evaluations.templates.store');
    Route::get('evaluations/templates/{template}', [AdminEvaluationTemplateController::class, 'show'])
        ->whereNumber('template')->name('admin.evaluations.templates.show');
    Route::put('evaluations/templates/{template}', [AdminEvaluationTemplateController::class, 'update'])
        ->whereNumber('template')->name('admin.evaluations.templates.update');
});

/*
|--------------------------------------------------------------------------
| Evaluation reporting (Stage B / B3)
|--------------------------------------------------------------------------
| Read-only aggregates for the 2026 evaluation screens. Gated on the new
| `view-evaluations` permission — the Dashboard's evaluations route is
| currently annotated "legacy, un-gated" (finding DB-06), so no key existed.
|
| A submission has no id: user_course_evaluations holds one row per
| (learner, course, question), so the detail route addresses it by the natural
| key rather than by a surrogate.
*/
Route::middleware(['auth.user', 'role:Admin', 'section:evaluations'])->prefix('admin')->group(function () {
    Route::get('courses/{course}/evaluation-summary', [AdminEvaluationReportController::class, 'courseSummary'])
        ->name('admin.evaluations.course-summary');

    // Static segments first, so none is ever captured as {template}.
    Route::get('evaluations/templates', [AdminEvaluationReportController::class, 'templates'])
        ->name('admin.evaluations.templates');

    Route::get('evaluations/filter-options', [AdminEvaluationReportController::class, 'filterOptions'])
        ->name('admin.evaluations.filter-options');

    Route::get('evaluations/learner-options', [AdminEvaluationReportController::class, 'learnerOptions'])
        ->name('admin.evaluations.learner-options');

    Route::get('evaluations/scores', [AdminEvaluationReportController::class, 'scores'])
        ->name('admin.evaluations.scores');

    Route::get('evaluations/scores/{learner}/{course}', [AdminEvaluationReportController::class, 'submission'])
        ->name('admin.evaluations.submission');

    Route::get('evaluations/{template}/results', [AdminEvaluationReportController::class, 'templateResults'])
        ->name('admin.evaluations.template-results');
});
