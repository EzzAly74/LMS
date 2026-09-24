<?php

use App\Http\Controllers\apis\JobTitleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Job Title Routes — /api/v1/job-titles/*
|--------------------------------------------------------------------------
*/

// Public — for select dropdowns
Route::get('job-titles/active', [JobTitleController::class, 'activeList']);

// Authenticated readers
Route::middleware('auth.user')->group(function () {
    Route::get('job-titles',              [JobTitleController::class, 'index']);
    Route::get('job-titles/{job_title}',  [JobTitleController::class, 'show']);
});

// Admin only
Route::middleware(['auth.user', 'role:Admin', 'permission:view-job-titles'])->group(function () {
    Route::put('job-titles/{job_title}/qualifications', [JobTitleController::class, 'syncQualifications']);

    // Job-title detail learners table (Figma 2325:117118) - no endpoint existed.
    Route::get('admin/job-titles/{job_title}/learners', [JobTitleController::class, 'learners'])
        ->name('admin.job-titles.learners');
});
