<?php

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

Route::middleware(['auth.user', 'role:Admin', 'permission:view-users'])->prefix('admin')->group(function () {

    /*
     * Learner detail (Figma 2181:115043). New surface: the learner-side
     * `learner/profile/*` and `my/*` endpoints are scoped to the logged-in
     * user, so an admin cannot reuse them.
     *
     * Three endpoints rather than one payload because the design gives the two
     * tables independent pagers.
     */
    Route::middleware('permission:view-users')->group(function () {
        Route::get('learners/{learner}',             [AdminLearnerProfileController::class, 'show'])
            ->name('admin.learners.show');
        Route::get('learners/{learner}/courses',     [AdminLearnerProfileController::class, 'courses'])
            ->name('admin.learners.courses');
        Route::get('learners/{learner}/performance', [AdminLearnerProfileController::class, 'performance'])
            ->name('admin.learners.performance');
    });


    // Lookup endpoints (declared before the resource routes so the URI
    // segments don't get matched as integer ids).
    Route::get('users/summary',         [AdminUserController::class, 'summary']);
    Route::get('users/filter-options',  [AdminUserController::class, 'filterOptions']);

    // List + create
    Route::get('users',                 [AdminUserController::class, 'index']);
    Route::post('users',                [AdminUserController::class, 'store']);

    // Source-scoped item routes
    Route::get('users/{source}/{id}',    [AdminUserController::class, 'show'])
        ->whereIn('source', ['user', 'instructor', 'admin'])
        ->whereNumber('id');
    // Accept both PUT (JSON) and POST (`_method=PUT`, multipart) so the
    // frontend can upload a new avatar without juggling two endpoints.
    Route::match(['put', 'post'], 'users/{source}/{id}', [AdminUserController::class, 'update'])
        ->whereIn('source', ['user', 'instructor', 'admin'])
        ->whereNumber('id');
    Route::delete('users/{source}/{id}', [AdminUserController::class, 'destroy'])
        ->whereIn('source', ['user', 'instructor', 'admin'])
        ->whereNumber('id');

    // Reverse a soft-deactivation (status -> active).
    Route::patch('users/{source}/{id}/reactivate', [AdminUserController::class, 'reactivate'])
        ->whereIn('source', ['user', 'instructor', 'admin'])
        ->whereNumber('id');
});
