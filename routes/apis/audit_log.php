<?php

use App\Http\Controllers\apis\AuditLogController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Audit Log Routes — /api/v1/audit-log
|--------------------------------------------------------------------------
*/

Route::middleware(['auth.user', 'role:Admin', 'section:audit-log'])->group(function () {
    Route::get('audit-log', [AuditLogController::class, 'index']);
});
