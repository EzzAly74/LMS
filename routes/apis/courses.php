<?php

use App\Http\Controllers\apis\CourseController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Course Routes — /api/v1/courses/*
|--------------------------------------------------------------------------
*/

// Authenticated users (students + admins)
Route::middleware(['auth.user', 'course.scope'])->group(function () {
    Route::get('courses',                  [CourseController::class, 'index']);
    Route::get('courses/tab-counts',       [CourseController::class, 'tabCounts']);
    Route::get('courses/{course}',         [CourseController::class, 'show']);
});

// Admin only
Route::middleware(['auth.user', 'role:Admin', 'section:courses'])->group(function () {
    Route::post('courses',            [CourseController::class, 'store'])->ability('create');
    Route::put('courses/{course}',    [CourseController::class, 'update']);
    Route::delete('courses/{course}', [CourseController::class, 'destroy']);
});
