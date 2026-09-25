<?php

use App\Http\Controllers\apis\Admin\LearnerQualificationController;
use App\Http\Controllers\apis\Admin\QualificationSkillTransferController;
use App\Http\Controllers\apis\QualificationSkillController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Qualification Skill Routes — /api/v1/qualification-skills/*
|--------------------------------------------------------------------------
*/

// Public list (for frontend / Angular dropdowns)
Route::get('qualification-skills/active', [QualificationSkillController::class, 'activeList']);

// Authenticated readers (any logged-in user can browse skills)
Route::middleware('auth.user')->group(function () {
    Route::get('qualification-skills',                         [QualificationSkillController::class, 'index']);
    Route::get('qualification-skills/{qualification_skill}',   [QualificationSkillController::class, 'show']);
});

// Admin only
/*
|--------------------------------------------------------------------------
| Import / export (Stage B / B4)
|--------------------------------------------------------------------------
| Figma 2066:99852 (export menu) and 1983:44634 (import menu: template / xlsx /
| csv). Export and the template share one column definition, so a file exported
| here can be edited and re-imported unchanged.
|
| Export and import are expensive routes, so they carry a tighter limiter than
| the group-wide throttle:api (B-06).
*/
Route::middleware(['auth.user', 'role:Admin', 'permission:view-qualifications'])->prefix('admin')->group(function () {
    Route::get('qualification-skills/export',          [QualificationSkillTransferController::class, 'export'])
        ->middleware('throttle:10,1')->name('admin.qualifications.export');
    Route::get('qualification-skills/import-template', [QualificationSkillTransferController::class, 'template'])
        ->middleware('throttle:10,1')->name('admin.qualifications.template');
    Route::post('qualification-skills/import',         [QualificationSkillTransferController::class, 'import'])
        ->middleware('throttle:10,1')->name('admin.qualifications.import');
});

Route::middleware(['auth.user', 'role:Admin', 'permission:view-qualifications'])->group(function () {
    Route::post('qualification-skills',                          [QualificationSkillController::class, 'store']);
    Route::put('qualification-skills/{qualification_skill}',     [QualificationSkillController::class, 'update']);
    Route::delete('qualification-skills/{qualification_skill}',  [QualificationSkillController::class, 'destroy']);
});

/*
| Direct learner grants (D-045) — Figma 2066:100876 and the "Assign
| Qualification" bulk action on the learners list.
|
| Gated on view-qualifications rather than view-users: the thing being granted
| is a qualification, and an admin who may manage qualifications is the one
| who should be able to award them.
|
| The bulk route carries a tighter limiter. It is one INSERT, so this is not
| about query cost — it bounds how fast a mistake can be repeated.
*/
Route::middleware(['auth.user', 'role:Admin', 'permission:view-qualifications'])->prefix('admin')->group(function () {
    Route::get('learners/{learner}/qualifications',
        [LearnerQualificationController::class, 'index'])->name('admin.learners.qualifications.index');

    Route::post('learners/{learner}/qualifications',
        [LearnerQualificationController::class, 'store'])->name('admin.learners.qualifications.store');

    Route::delete('learners/{learner}/qualifications/{qualification_skill}',
        [LearnerQualificationController::class, 'destroy'])->name('admin.learners.qualifications.destroy');

    Route::post('qualification-skills/{qualification_skill}/learners',
        [LearnerQualificationController::class, 'bulkStore'])
        ->middleware('throttle:20,1')
        ->name('admin.qualifications.learners.bulk');
});
