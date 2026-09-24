<?php

use App\Http\Controllers\apis\CertificateController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Certificate Routes — /api/v1/certificates
|--------------------------------------------------------------------------
*/

Route::middleware(['auth.user', 'role:Admin', 'permission:view-certificates'])->group(function () {
    Route::get('certificates',              [CertificateController::class, 'index']);
    Route::get('certificates/{courseId}',   [CertificateController::class, 'show']);
});
