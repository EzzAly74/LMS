<?php

use App\Http\Controllers\apis\Admin\AdminAiQuestionController as C;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| "Generate with AI" in the quiz and assignment forms (D-113)
|--------------------------------------------------------------------------
|
| Under each section's own gate: creating questions needs create-quizzes /
| create-assignments, reading a run needs view-*. Loaded before the quiz and
| assignment route files (alphabetical), so `ai-generations` is never read as
| an id. Start is rate limited on top of the daily cap.
*/

foreach (['quiz' => 'quizzes', 'assignment' => 'assignments'] as $target => $section) {
    Route::middleware(['auth.user', 'role:Admin', "section:{$section}"])
        ->prefix("admin/{$section}/ai-generations")
        ->group(function () use ($target) {
            Route::get('usage', [C::class, 'usage'])->defaults('target', $target);
            Route::post('', [C::class, 'store'])->defaults('target', $target)->ability('create')->middleware('throttle:10,1');
            Route::get('{generation}', [C::class, 'show'])->defaults('target', $target)->whereNumber('generation');
        });
}
