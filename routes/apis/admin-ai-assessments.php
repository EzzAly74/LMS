<?php

use App\Http\Controllers\apis\Admin\AdminAiAssessmentController as C;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| AI Assessment builder — /api/v1/admin/ai-assessments (D-113)
|--------------------------------------------------------------------------
|
| `section:ai-assessments` checks {action}-ai-assessments and course scope;
| the controller then limits every id to the caller's own assessments (H2).
| Rate limits on top of the 60/min api limit: AI calls 10/min (plus the
| daily cap), uploads 30/min, exports 20/min.
*/

Route::middleware(['auth.user', 'role:Admin', 'section:ai-assessments'])
    ->prefix('admin/ai-assessments')
    ->whereNumber(['assessment', 'source', 'question'])
    ->group(function () {
        Route::get('', [C::class, 'index']);
        Route::get('courses', [C::class, 'courses']);
        Route::get('usage', [C::class, 'usage']);
        Route::post('', [C::class, 'store'])->ability('create');

        Route::get('{assessment}', [C::class, 'show']);
        Route::put('{assessment}', [C::class, 'update']);
        Route::delete('{assessment}', [C::class, 'destroy']);
        Route::post('{assessment}/duplicate', [C::class, 'duplicate'])->ability('create');

        Route::post('{assessment}/sources', [C::class, 'uploadSource'])->ability('edit')->middleware('throttle:30,1');
        Route::post('{assessment}/sources/text', [C::class, 'pasteSource'])->ability('edit')->middleware('throttle:30,1');
        Route::delete('{assessment}/sources/{source}', [C::class, 'deleteSource'])->ability('edit');

        Route::post('{assessment}/generate', [C::class, 'generate'])->ability('edit')->middleware('throttle:10,1');
        Route::post('{assessment}/questions/{question}/regenerate', [C::class, 'regenerateQuestion'])->ability('edit')->middleware('throttle:10,1');

        Route::put('{assessment}/questions/order', [C::class, 'reorder']);
        Route::put('{assessment}/questions/{question}', [C::class, 'updateQuestion']);
        Route::patch('{assessment}/questions/{question}/status', [C::class, 'setQuestionStatus']);
        Route::delete('{assessment}/questions/{question}', [C::class, 'deleteQuestion'])->ability('edit');

        Route::get('{assessment}/export', [C::class, 'export'])->middleware('throttle:20,1');
    });
