<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
|
| This project is API-only (confirmed by the product owner, 2026-09-24, Q-005:
| the Blade admin and Blade front-end are not live and are not needed).
|
| The former Blade surface was removed in Phase 4 / Stage A because it carried
| the highest-severity defects in the audit, all of which are closed by its
| deletion rather than by a fix:
|
|   B-04 (Critical) admin `quickChange` / `deleteSelectedItems` — arbitrary
|                   column write and arbitrary mass delete on any model.
|   B-07 (High)     unauthenticated `/2b/attendance/getUser` employee
|                   enumeration, and unescaped course titles in built HTML.
|   B-29 (Low)      the same unescaped-HTML sink as B-07.
|   B-11 (High)     `routes/test.php`, included unconditionally, exposing
|                   `GET /test/hr` (the full HR employee roster) with no auth.
|   B-18 (Medium)   unthrottled Blade `POST /login` and `POST /admin/login`.
|
| See _audit/05-plan.md §3 (A1, A8, A11) and _audit/04-decisions.md D-041.
|
| Only two things remain here: the legacy Swagger URL aliases, and the public
| storage fallback used when the `storage:link` symlink is absent.
|
*/

/*
|--------------------------------------------------------------------------
| API Documentation (legacy aliases)
|--------------------------------------------------------------------------
| The canonical endpoints are provided by L5-Swagger:
|   - /api/documentation   → Swagger UI
|   - /docs                → OpenAPI JSON spec
| These aliases preserve the previously-published URLs.
*/
Route::redirect('/api/docs', '/api/documentation');
Route::redirect('/storage/api-docs/openapi.yaml', '/docs');

/*
|--------------------------------------------------------------------------
| Public storage fallback
|--------------------------------------------------------------------------
|
| Serves files from the public disk when the `storage:link` symlink does not
| exist. Still run `php artisan storage:link` on the server for best perf —
| this is a fallback, not a replacement.
|
| NOTE (B-10, plan item A12): this route performs no authorization, so every
| file on the public disk is world-readable. Hardening it — moving learner
| submissions to a private disk behind an authorized route — is tracked as
| A12 and is deliberately NOT done here, because it changes asset URLs
| consumed by both frontends and needs its own consumer search.
|
*/
Route::get('/storage/{path}', function (string $path) {
    abort_if(str_contains($path, '..'), 404);

    $disk = Storage::disk('public');
    abort_unless($disk->exists($path), 404);

    // BinaryFileResponse → correct MIME guess + HTTP range support
    // (so this also works for audio/video, not just images).
    return response()->file($disk->path($path));
})->where('path', '.*')->name('storage.public');
