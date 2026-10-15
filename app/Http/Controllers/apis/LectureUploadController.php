<?php

namespace App\Http\Controllers\apis;

use App\Http\Traits\HasFile;
use App\Models\Course;
use App\Services\ChunkedUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Module files in parts (D-111, ChunkedUploadService): a lesson video of any
 * size up to 500 MB, whatever the server's per-request limit.
 *
 *   POST courses/{course}/lectures/uploads                 {name, size} -> {id, chunk_size, received}
 *   PUT  courses/{course}/lectures/uploads/{id}?offset=N   raw bytes    -> {received}  (409 + received: resume there)
 *   GET  courses/{course}/lectures/uploads/{id}                         -> {received, size}
 *   POST courses/{course}/lectures/uploads/{id}/complete                -> {path, url, name, size} (as lectures/upload)
 *
 * Admin + courses section + course scope (route middleware); an upload is
 * continued only by the account and course that started it (else 404).
 */
class LectureUploadController extends ApiController
{
    use HasFile;

    public function __construct(private readonly ChunkedUploadService $uploads) {}

    public function start(Course $course, Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1', 'max:'.ChunkedUploadService::MAX_SIZE],
        ], [
            'size.max' => __('messages.upload_too_large', ['mb' => intdiv(ChunkedUploadService::MAX_SIZE, 1024 * 1024)]),
        ]);
        if (! in_array(ChunkedUploadService::extension($data['name']), ChunkedUploadService::extensions(), true)) {
            return $this->invalid(['name' => [__('messages.upload_type')]]);
        }

        return $this->success(__('messages.created'), $this->uploads->start($this->owner($request), (int) $course->id, $data['name'], (int) $data['size']), 201);
    }

    public function append(Course $course, string $upload, Request $request): JsonResponse
    {
        $offset = filter_var($request->query('offset'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($offset === false) {
            return $this->invalid(['offset' => [__('validation.integer', ['attribute' => 'offset'])]]);
        }
        $body = $request->getContent(true);
        $out = $this->uploads->append($upload, $this->owner($request), (int) $course->id, (int) $offset, $body);
        if ($out === null) {
            return $this->notFound();
        }
        if (! $out['accepted']) {
            return response()->json([
                'status' => 'error', 'message' => __('messages.upload_resume'), 'result' => ['received' => $out['received']],
            ], 409);
        }

        return $this->success(__('messages.updated'), ['received' => $out['received']]);
    }

    public function status(Course $course, string $upload, Request $request): JsonResponse
    {
        $out = $this->uploads->status($upload, $this->owner($request), (int) $course->id);

        return $out === null ? $this->notFound() : $this->success(__('messages.retrieved'), $out);
    }

    public function complete(Course $course, string $upload, Request $request): JsonResponse
    {
        $out = $this->uploads->complete($upload, $this->owner($request), (int) $course->id);
        if ($out === null) {
            return $this->notFound();
        }
        if (isset($out['error'])) {
            return $out['error'] === 'incomplete'
                ? $this->error(__('messages.upload_incomplete'), 409)
                : $this->invalid(['file' => [__('messages.upload_content')]]);
        }

        return $this->success(__('messages.created'), [
            'path' => $out['path'],
            'url'  => $this->getFileUrl($out['path']),
            'name' => $out['name'],
            'size' => $out['size'],
        ]);
    }

    /** The account the upload belongs to: guard model and id. */
    private function owner(Request $request): string
    {
        $user = $request->user();

        return class_basename($user).':'.$user->getKey();
    }

    /** @param  array<string, list<string>>  $errors */
    private function invalid(array $errors): JsonResponse
    {
        return $this->error((string) collect($errors)->flatten()->first(), 422, $errors);
    }
}
