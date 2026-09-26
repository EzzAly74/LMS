<?php

use App\Http\Controllers\apis\CourseRatingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Course Rating Routes — /api/v1/courses/{course}/ratings
|--------------------------------------------------------------------------
*/

Route::middleware(['auth.user', 'role:User'])->group(function () {
    Route::post('courses/{course}/ratings', [CourseRatingController::class, 'store']);
});
