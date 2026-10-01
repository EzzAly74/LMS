<?php

use App\Http\Controllers\apis\Admin\AdminExternalTrainingController;
use App\Http\Controllers\apis\Learner\ExternalTrainingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| External Training (D-035, D-057)
|--------------------------------------------------------------------------
| Learner: submit, edit or withdraw while pending, download their own
| certificate (Website, Figma 2201:83481 / 2201:84534).
| Admin: list with tiles, review, approve / reject, download the certificate
| (Dashboard, Figma 2181:116177 / 2181:116391 / 2209:90462); a super admin
| may reopen a decision.
|
| Uploads carry a tighter limiter than throttle:api: each one writes a file.
*/
Route::middleware(['auth.user', 'role:User'])->prefix('learner/external-training')->group(function () {
    Route::get('/', [ExternalTrainingController::class, 'index'])->name('learner.external-training.index');
    Route::post('/', [ExternalTrainingController::class, 'store'])
        ->middleware('throttle:10,1')->name('learner.external-training.store');
    Route::get('{externalTraining}', [ExternalTrainingController::class, 'show'])
        ->whereNumber('externalTraining')->name('learner.external-training.show');
    // POST, not PUT: PHP does not parse multipart bodies on PUT.
    Route::post('{externalTraining}', [ExternalTrainingController::class, 'update'])
        ->whereNumber('externalTraining')->middleware('throttle:10,1')->name('learner.external-training.update');
    Route::delete('{externalTraining}', [ExternalTrainingController::class, 'destroy'])
        ->whereNumber('externalTraining')->name('learner.external-training.destroy');
    Route::get('{externalTraining}/certificate', [ExternalTrainingController::class, 'certificate'])
        ->whereNumber('externalTraining')->name('learner.external-training.certificate');
});

Route::middleware(['auth.user', 'role:Admin', 'section:external-training'])->prefix('admin/external-training')->group(function () {
    Route::get('/', [AdminExternalTrainingController::class, 'index'])->name('admin.external-training.index');
    Route::get('stats', [AdminExternalTrainingController::class, 'stats'])->name('admin.external-training.stats');
    Route::get('options', [AdminExternalTrainingController::class, 'options'])->name('admin.external-training.options');
    Route::get('{externalTraining}', [AdminExternalTrainingController::class, 'show'])
        ->whereNumber('externalTraining')->name('admin.external-training.show');
    Route::post('{externalTraining}/approve', [AdminExternalTrainingController::class, 'approve'])->ability('edit')
        ->whereNumber('externalTraining')->name('admin.external-training.approve');
    Route::post('{externalTraining}/reject', [AdminExternalTrainingController::class, 'reject'])->ability('edit')
        ->whereNumber('externalTraining')->name('admin.external-training.reject');
    Route::post('{externalTraining}/reopen', [AdminExternalTrainingController::class, 'reopen'])->ability('edit')
        ->whereNumber('externalTraining')->name('admin.external-training.reopen');
    Route::get('{externalTraining}/certificate', [AdminExternalTrainingController::class, 'certificate'])
        ->whereNumber('externalTraining')->name('admin.external-training.certificate');
    Route::get('{externalTraining}/certificate-link', [AdminExternalTrainingController::class, 'certificateLink'])
        ->whereNumber('externalTraining')->name('admin.external-training.certificate.link');
});

// The file behind a certificate link: the signature (made for an authorized
// reviewer, a few minutes long, D-071) is the authorization, not a token.
Route::get('external-training-files/{externalTraining}', [AdminExternalTrainingController::class, 'certificateFile'])
    ->whereNumber('externalTraining')->middleware(['signed', 'throttle:30,1'])->name('admin.external-training.certificate.file');
