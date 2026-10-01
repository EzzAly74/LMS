<?php

use App\Http\Controllers\apis\Admin\AdminLearnerController;
use App\Http\Controllers\apis\Admin\AdminLearnerProfileController;
use App\Http\Controllers\apis\Admin\AdminUserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin Users Routes — /api/v1/admin/users
|--------------------------------------------------------------------------
|
| New endpoints serving the platform-wide Users overview defined by the
| 2026 Figma redesign. The legacy user endpoints in routes/apis/users.php
| (and the legacy admin endpoints in routes/apis/admins.php) remain
| untouched and continue to serve the existing API surface.
|
| The detail / update / destroy routes accept a `source` segment indicating
| which underlying table the row lives in:
|   - user        →  users
|   - instructor  →  instructors
|   - admin       →  admins
*/

// The Learners section (Learning > Learners, D-073): website learners, kept
// apart from Users, which manages Dashboard accounts.
Route::middleware(['auth.user', 'role:Admin', 'section:learners'])->prefix('admin')->group(function () {
    /*
     * Learner detail (Figma 2181:115043). New surface: the learner-side
     * `learner/profile/*` and `my/*` endpoints are scoped to the logged-in
     * user, so an admin cannot reuse them.
     *
     * Three endpoints rather than one payload because the design gives the two
     * tables independent pagers.
     */
    Route::get('learners',                       [AdminLearnerController::class, 'index']);
    Route::get('learners/filter-options',        [AdminLearnerController::class, 'filterOptions']);
    Route::get('learners/{learner}',             [AdminLearnerProfileController::class, 'show'])
        ->name('admin.learners.show');
    Route::get('learners/{learner}/courses',     [AdminLearnerProfileController::class, 'courses'])
        ->name('admin.learners.courses');
    Route::get('learners/{learner}/performance', [AdminLearnerProfileController::class, 'performance'])
        ->name('admin.learners.performance');
});

Route::middleware(['auth.user', 'role:Admin', 'section:users'])->prefix('admin')->group(function () {
    // Lookup endpoints (declared before the resource routes so the URI
    // segments don't get matched as integer ids).
    Route::get('users/summary',         [AdminUserController::class, 'summary']);
    Route::get('users/filter-options',  [AdminUserController::class, 'filterOptions']);

    // List + create
    Route::get('users',                 [AdminUserController::class, 'index']);
    Route::post('users',                [AdminUserController::class, 'store'])->ability('create');

    // Source-scoped item routes
    Route::get('users/{source}/{id}',    [AdminUserController::class, 'show'])
        ->whereIn('source', ['admin'])
        ->whereNumber('id');
    // Accept both PUT (JSON) and POST (`_method=PUT`, multipart) so the
    // frontend can upload a new avatar without juggling two endpoints.
    Route::match(['put', 'post'], 'users/{source}/{id}', [AdminUserController::class, 'update'])->ability('edit')
        ->whereIn('source', ['admin'])
        ->whereNumber('id');
    Route::delete('users/{source}/{id}', [AdminUserController::class, 'destroy'])
        ->whereIn('source', ['admin'])
        ->whereNumber('id');

    // Reverse a soft-deactivation (status -> active).
    Route::patch('users/{source}/{id}/reactivate', [AdminUserController::class, 'reactivate'])
        ->whereIn('source', ['admin'])
        ->whereNumber('id');
});
