<?php

use App\Http\Controllers\apis\AuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication Routes — /api/v1/auth/*
|--------------------------------------------------------------------------
*/

Route::prefix('auth')->group(function () {

    // User auth
    Route::prefix('user')->group(function () {
        // Throttled: see the 'login' limiter in AppServiceProvider — learner
        // credentials are checked against HR, so an open endpoint is a
        // brute-force channel into the HR system as well as into this one.
        Route::post('login', [AuthController::class, 'userLogin'])
            ->middleware('throttle:login');

        Route::middleware(['auth.user', 'role:User'])->group(function () {
            Route::post('logout',      [AuthController::class, 'userLogout']);
            Route::post('logout-all',  [AuthController::class, 'userLogoutAll']);
            Route::get('me',           [AuthController::class, 'userMe']);
            Route::put('profile',      [AuthController::class, 'userUpdateProfile']);
        });
    });

    // Admin auth
    Route::prefix('admin')->group(function () {
        Route::post('login', [AuthController::class, 'adminLogin'])
            ->middleware('throttle:login');

        Route::middleware(['auth.user', 'role:Admin'])->group(function () {
            Route::post('logout',  [AuthController::class, 'adminLogout']);
            Route::get('me',       [AuthController::class, 'adminMe']);
            Route::put('profile',  [AuthController::class, 'adminUpdateProfile']);
        });
    });
});
