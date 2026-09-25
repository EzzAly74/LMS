<?php

namespace App\Http\Traits;

use App\Support\UploadedImageOptimizer;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

trait HasFile
{
    public function uploadRequestFile($model, $request, $file_input_name, $request_file = null)
    {
        $main_file = ($file_input_name === null) ? $request_file : $request->file($file_input_name);

        if(config('filesystems.default') == 's3') {
            $path =  Storage::putFile($model, $main_file);
            if($path === false){
                throw new \Exception('S3 error');
            }
            return $path;
            // dd($path);
        } else {
            $file_name = Str::random(20).md5(microtime()).'.'.$this->safeExtensionFor($main_file);
            Storage::disk('public')->putFileAs($model, $main_file, $file_name);
            return "{$model}/".$file_name;
        }
    }

    /**
     * Store an uploaded *display* image, re-encoded as WebP (IMG-01).
     *
     * Use this, not uploadRequestFile(), for any image the site shows: an
     * avatar, or a course, category, blog, article or testimonial image. See
     * UploadedImageOptimizer for what the conversion does and when it keeps
     * the original instead (animated images, GD without WebP support).
     *
     * Storage mirrors uploadRequestFile(): the default disk when it is S3,
     * otherwise the public disk, under a server-generated name. The returned
     * value is the same disk-relative path the callers already persist.
     *
     * @param  string  $field  the request field, named in any validation error
     */
    public function uploadImageFile(string $directory, UploadedFile $file, string $field = 'image'): string
    {
        $webp = app(UploadedImageOptimizer::class)->toWebp($file, $field);

        if ($webp === null) {
            return $this->uploadRequestFile($directory, null, null, $file);
        }

        $path = "{$directory}/".Str::random(20).md5(microtime()).'.webp';

        $stored = config('filesystems.default') == 's3'
            ? Storage::put($path, $webp)
            : Storage::disk('public')->put($path, $webp);

        if ($stored === false) {
            throw new \RuntimeException('Could not store the uploaded image.');
        }

        return $path;
    }

    /**
     * Store a file on the private disk, which is not reachable over HTTP.
     *
     * B-10 (High): everything went to the `public` disk, served directly off
     * the filesystem by Apache and by the unauthenticated /storage/{path}
     * fallback. Learner assignment submissions were world-readable to anyone
     * with the URL, with no check for author, instructor or admin.
     *
     * Use this for anything that belongs to a person rather than to the public
     * site. The returned path is a disk-relative key, never a URL — callers
     * must serve it through an authorized route.
     */
    public function uploadPrivateFile(string $directory, $file): string
    {
        $name = Str::random(20).md5(microtime()).'.'.$this->safeExtensionFor($file);

        Storage::disk('private')->putFileAs($directory, $file, $name);

        return "{$directory}/".$name;
    }

    /**
     * Derive a storage extension from the file's *contents*, not from what the
     * client called it.
     *
     * B-02 (Critical): the basename was already server-generated, but the
     * extension came straight from `getClientOriginalExtension()`. Uploading
     * `shell.php` produced `<random>.php` on the public disk, and
     * `public/.htaccess` only routes `^storage` to Laravel when the file does
     * *not* exist — so Apache served it directly and mod_php would execute it.
     * The submission response even returns `user_file_url`, handing the
     * attacker the exact path. The same trick with `.html` or `.svg` gives
     * stored XSS on the app's own origin.
     *
     * `guessExtension()` maps the finfo-detected MIME type to an extension, so
     * a PHP script uploaded as `report.pdf` is stored as `.txt`, not `.pdf`,
     * and a real PDF is still stored as `.pdf`. When the type cannot be
     * guessed we fall back to a character-restricted client extension rather
     * than trusting it verbatim, and finally to `bin`.
     */
    protected function safeExtensionFor($file): string
    {
        $guessed = null;

        if (is_object($file) && method_exists($file, 'guessExtension')) {
            $guessed = $file->guessExtension();
        }

        if (is_string($guessed) && $guessed !== '') {
            return strtolower($guessed);
        }

        $client = is_object($file) && method_exists($file, 'getClientOriginalExtension')
            ? (string) $file->getClientOriginalExtension()
            : '';

        $client = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $client) ?? '');

        return $client !== '' ? substr($client, 0, 10) : 'bin';
    }

    //Full image path
    public function getFileUrl($field)
    {
        if($field == '') {
            return false;
        }
        if(config('filesystems.default') == 's3') {
            return Storage::disk('s3')->url($field);
            // return Storage::disk('s3')->temporaryUrl($field, Carbon::now()->addWeek());
        } else {
            return url(Storage::disk('public')->url($field));
        }
    }
}

