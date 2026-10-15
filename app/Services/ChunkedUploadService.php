<?php

namespace App\Services;

use App\Support\Mp4FastStart;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Module files in parts (D-111). A lesson video is far larger than one request
 * may be (PHP post_max_size / upload_max_filesize, the web server, a proxy),
 * so the Dashboard sends it in CHUNK-sized parts: start, append each part at
 * its offset (a lost part is sent again, an interrupted upload resumes from
 * `received`), then complete. Complete checks the size and the content
 * signature, makes an MP4 / MOV fast-start (Mp4FastStart) and stores the file
 * where the one-request upload put it (course-lectures/, public disk or S3).
 *
 * Parts live in storage/app/chunked-uploads/{id}/ (outside the web root) with
 * a meta.json naming the account and course that started it; only they can
 * continue it. Uploads not completed within a day are pruned
 * (`uploads:prune-chunks`, daily).
 */
final class ChunkedUploadService
{
    /** What the Dashboard sends per part: under every default request limit (PHP's 8 MB). */
    public const CHUNK = 4 * 1024 * 1024;

    /** A part may be at most this (the last part is smaller). */
    public const MAX_CHUNK = 6 * 1024 * 1024;

    /** The one-request upload's limit: 500 MB. */
    public const MAX_SIZE = 500 * 1024 * 1024;

    /** Uploads left unfinished longer than this are removed. */
    public const TTL_HOURS = 24;

    /** Allowed extensions and the first bytes each must start with (null: text). */
    private const SIGNATURES = [
        'mp4' => 'iso', 'm4v' => 'iso', 'mov' => 'iso',
        'webm' => ["\x1A\x45\xDF\xA3"], 'ogg' => ['OggS'],
        'pdf' => ['%PDF'],
        'doc' => ["\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"], 'xls' => ["\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"], 'ppt' => ["\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"],
        'docx' => ["PK\x03\x04"], 'xlsx' => ["PK\x03\x04"], 'pptx' => ["PK\x03\x04"],
        'jpg' => ["\xFF\xD8\xFF"], 'jpeg' => ["\xFF\xD8\xFF"], 'png' => ["\x89PNG"], 'gif' => ['GIF8'], 'webp' => 'riff-webp',
        'txt' => null,
    ];

    /** First box of an ISO media file (MP4 / MOV). */
    private const ISO_BOXES = ['ftyp', 'moov', 'mdat', 'wide', 'free', 'skip', 'pnot'];

    public static function extensions(): array
    {
        return array_keys(self::SIGNATURES);
    }

    /** @return array{id: string, chunk_size: int, received: int} */
    public function start(string $owner, int $courseId, string $name, int $size): array
    {
        $id = (string) Str::uuid();
        $dir = $this->dir($id);
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Cannot create the upload folder.');
        }
        touch($dir.'/data');
        file_put_contents($dir.'/meta.json', json_encode([
            'owner' => $owner, 'course_id' => $courseId, 'name' => $name,
            'ext' => self::extension($name), 'size' => $size, 'started_at' => now()->toIso8601String(),
        ]));

        return ['id' => $id, 'chunk_size' => self::CHUNK, 'received' => 0];
    }

    /**
     * Append one part. Returns the bytes received so far, or null when the
     * upload is not this account's (or does not exist). An $offset that is
     * not the end of what was received writes nothing and returns the
     * current size, so the client resumes from there.
     *
     * @param  resource  $body
     * @return array{received: int, accepted: bool}|null
     */
    public function append(string $id, string $owner, int $courseId, int $offset, $body): ?array
    {
        $meta = $this->meta($id, $owner, $courseId);
        if ($meta === null) {
            return null;
        }
        $data = $this->dir($id).'/data';
        $fh = fopen($data, 'ab');
        if ($fh === false || ! flock($fh, LOCK_EX)) {
            throw new \RuntimeException('Cannot write the upload.');
        }
        try {
            clearstatcache(true, $data);
            $received = (int) filesize($data);
            if ($offset !== $received) {
                return ['received' => $received, 'accepted' => false];
            }
            // At most one part, and never past the announced size.
            $limit = min(self::MAX_CHUNK, (int) $meta['size'] - $received);
            $written = stream_copy_to_stream($body, $fh, $limit + 1);
            if ($written === false || $written > $limit) {
                ftruncate($fh, $received);

                return ['received' => $received, 'accepted' => false];
            }
            fflush($fh);

            return ['received' => $received + $written, 'accepted' => $written > 0];
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /** @return array{received: int, size: int}|null */
    public function status(string $id, string $owner, int $courseId): ?array
    {
        $meta = $this->meta($id, $owner, $courseId);
        if ($meta === null) {
            return null;
        }
        clearstatcache(true, $this->dir($id).'/data');

        return ['received' => (int) filesize($this->dir($id).'/data'), 'size' => (int) $meta['size']];
    }

    /**
     * Check and store the whole file.
     *
     * @return array{path: string, name: string, size: int}|array{error: string}|null
     */
    public function complete(string $id, string $owner, int $courseId, string $folder = 'course-lectures'): ?array
    {
        $meta = $this->meta($id, $owner, $courseId);
        if ($meta === null) {
            return null;
        }
        $data = $this->dir($id).'/data';
        clearstatcache(true, $data);
        if ((int) filesize($data) !== (int) $meta['size']) {
            return ['error' => 'incomplete'];
        }
        if (! $this->signatureMatches($data, (string) $meta['ext'])) {
            $this->discard($id);

            return ['error' => 'content'];
        }
        if (in_array($meta['ext'], ['mp4', 'm4v', 'mov'], true)) {
            Mp4FastStart::apply($data);
        }

        $name = Str::random(20).md5(microtime()).'.'.$meta['ext'];
        if (config('filesystems.default') === 's3') {
            $path = Storage::putFileAs($folder, new File($data), $name);
            if ($path === false) {
                throw new \RuntimeException('S3 error');
            }
        } else {
            $disk = Storage::disk('public');
            $disk->makeDirectory($folder);
            // Same disk: a rename, not a 500 MB copy.
            if (! @rename($data, $disk->path($folder.'/'.$name))) {
                $disk->putFileAs($folder, new File($data), $name);
            }
            $path = $folder.'/'.$name;
        }
        $this->discard($id);

        return ['path' => $path, 'name' => (string) $meta['name'], 'size' => (int) $meta['size']];
    }

    /** Remove uploads older than TTL_HOURS; returns how many. */
    public function prune(): int
    {
        $root = storage_path('app/chunked-uploads');
        if (! is_dir($root)) {
            return 0;
        }
        $removed = 0;
        $cutoff = now()->subHours(self::TTL_HOURS)->getTimestamp();
        foreach (glob($root.'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $meta = $dir.'/meta.json';
            $at = is_file($meta) ? filemtime($meta) : filemtime($dir);
            $last = is_file($dir.'/data') ? max($at, (int) filemtime($dir.'/data')) : $at;
            if ($last < $cutoff) {
                $this->discard(basename($dir));
                $removed++;
            }
        }

        return $removed;
    }

    public static function extension(string $name): string
    {
        return strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', pathinfo($name, PATHINFO_EXTENSION)));
    }

    private function dir(string $id): string
    {
        return storage_path('app/chunked-uploads/'.$id);
    }

    /** @return array<string, mixed>|null */
    private function meta(string $id, string $owner, int $courseId): ?array
    {
        if (! Str::isUuid($id)) {
            return null;
        }
        $file = $this->dir($id).'/meta.json';
        $meta = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (! is_array($meta) || ($meta['owner'] ?? null) !== $owner || (int) ($meta['course_id'] ?? 0) !== $courseId) {
            return null;
        }

        return $meta;
    }

    private function discard(string $id): void
    {
        if (! Str::isUuid($id)) {
            return;
        }
        $dir = $this->dir($id);
        foreach (['data', 'meta.json'] as $f) {
            @unlink($dir.'/'.$f);
        }
        @rmdir($dir);
    }

    private function signatureMatches(string $path, string $ext): bool
    {
        if (! array_key_exists($ext, self::SIGNATURES)) {
            return false;
        }
        $head = (string) file_get_contents($path, false, null, 0, 16);
        $rule = self::SIGNATURES[$ext];

        if ($rule === null) {
            // Text: no NUL byte in the first 8 KB.
            return ! str_contains((string) file_get_contents($path, false, null, 0, 8192), "\0");
        }
        if ($rule === 'iso') {
            return strlen($head) >= 8 && in_array(substr($head, 4, 4), self::ISO_BOXES, true);
        }
        if ($rule === 'riff-webp') {
            return str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP';
        }
        foreach ($rule as $sig) {
            if (str_starts_with($head, $sig)) {
                return true;
            }
        }

        return false;
    }
}
