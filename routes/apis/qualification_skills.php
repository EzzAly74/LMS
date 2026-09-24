<?php

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
