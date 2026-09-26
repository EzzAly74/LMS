<?php

use App\Http\Controllers\apis\Admin\AdminEvaluationReportController;
use App\Http\Controllers\apis\EvaluationCategoryController;
use App\Http\Controllers\apis\EvaluationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Evaluation Routes — /api/v1/evaluation-categories & /api/v1/evaluations
|--------------------------------------------------------------------------
*/

Route::middleware(['auth.user', 'role:Admin', 'permission:view-evaluations'])->group(function () {

    // Evaluation categories
    Route::get('evaluation-categories/all',                    [EvaluationCategoryController::class, 'all']);
    Route::get('evaluation-categories',                        [EvaluationCategoryController::class, 'index']);
    Route::get('evaluation-categories/{evaluationCategory}',   [EvaluationCategoryController::class, 'show']);
    Route::post('evaluation-categories',                       [EvaluationCategoryController::class, 'store']);
    Route::put('evaluation-categories/{evaluationCategory}',   [EvaluationCategoryController::class, 'update']);
    Route::delete('evaluation-categories/{evaluationCategory}',[EvaluationCategoryController::class, 'destroy']);

    // Evaluations
    Route::get('evaluations',              [EvaluationController::class, 'index']);
    Route::get('evaluations/{evaluation}', [EvaluationController::class, 'show']);
    Route::post('evaluations',             [EvaluationController::class, 'store']);
    Route::put('evaluations/{evaluation}', [EvaluationController::class, 'update']);
    Route::delete('evaluations/{evaluation}', [EvaluationController::class, 'destroy']);
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
Route::middleware(['auth.user', 'role:Admin', 'permission:view-evaluations'])->prefix('admin')->group(function () {
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
