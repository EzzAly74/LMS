<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Collection;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Throwable;

/**
 * Re-encodes an uploaded display image as WebP before it is stored (IMG-01).
 *
 * Every image an admin uploads from the Dashboard for the site to *display*
 * (avatars, course, category, blog, article, about, testimonial and
 * instructor images) goes through here via HasFile::uploadImageFile(). What
 * it does, and why:
 *
 *  - **WebP, quality 82.** The same format the Website's static images use
 *    (C-02 / F1). Uploads were stored byte-for-byte, so a 3 MB phone photo was
 *    served as a 3 MB avatar.
 *  - **Longest edge capped at MAX_EDGE, never enlarged.** Nothing on either
 *    frontend draws an uploaded image wider than that.
 *  - **Metadata dropped.** GD writes none, so EXIF, including a phone photo's
 *    GPS position, no longer reaches a public URL.
 *  - **EXIF orientation applied first.** Browsers rotate a JPEG by its
 *    orientation tag; the re-encoded file has no tag, so without this a
 *    portrait phone photo would be stored sideways. Intervention does this
 *    itself only when the `exif` extension is loaded, which it is not on the
 *    dev machine and is unverified in production, so the tag is read here.
 *  - **Pixel ceiling checked before decoding.** GD holds 4 bytes per pixel, so
 *    a small, highly compressible file can claim dimensions that exhaust
 *    `memory_limit` (a decompression bomb). getimagesize() reads only the
 *    header, and the upload is rejected with a 422 above MAX_PIXELS.
 *
 * It returns null, and the caller stores the original unchanged, in two cases:
 * an animated GIF or WebP (GD encodes a single frame, so converting would
 * silently drop the animation), and a PHP build whose GD lacks WebP support.
 * The second case is logged, because it means no upload is being converted.
 *
 * Deliberately NOT used for downloadable files (lecture attachments,
 * assignment files, LMS resources), where a learner expects the format that
 * was uploaded, or for the certificate template, which is a server-side
 * rendering source drawn on at fixed pixel coordinates and should not take a
 * lossy generation before its own JPEG encode.
 */
final class UploadedImageOptimizer
{
    public const MAX_EDGE = 2048;

    /**
     * 4096 x 4096. GD needs ~64 MB for that, plus the scaled copy, which fits
     * the 128 MB memory_limit with the framework's own footprint. Any file
     * under the upload size limits that exceeds it is either a bomb or a
     * photo far larger than anything the site displays.
     */
    public const MAX_PIXELS = 16_777_216;

    public const QUALITY = 82;

    public function __construct(private readonly ImageManager $images) {}

    /**
     * @param  string  $field  the request field, for the validation message
     * @return string|null WebP bytes, or null to store the original
     *
     * @throws ValidationException when the image is too large or unreadable
     */
    public function toWebp(UploadedFile $file, string $field = 'image'): ?string
    {
        if (! self::webpSupported()) {
            Log::warning('Image upload stored unconverted: this PHP build has no GD WebP support.');

            return null;
        }

        $path = $file->getRealPath();
        $size = $path === false ? false : @getimagesize($path);
        if ($size === false) {
            throw ValidationException::withMessages([$field => __('validation.image', ['attribute' => $field])]);
        }

        [$width, $height] = $size;
        if ($width * $height > self::MAX_PIXELS) {
            throw ValidationException::withMessages([
                $field => __('validation.dimensions', ['attribute' => $field]),
            ]);
        }

        try {
            $image = $this->images->read($path);
        } catch (Throwable) {
            throw ValidationException::withMessages([$field => __('validation.image', ['attribute' => $field])]);
        }

        if ($image->isAnimated()) {
            return null;
        }

        $this->alignOrientation($image, $path, $size[2] ?? null);
        $image->scaleDown(self::MAX_EDGE, self::MAX_EDGE);

        return (string) $image->encode(new WebpEncoder(quality: self::QUALITY));
    }

    public static function webpSupported(): bool
    {
        return function_exists('imagewebp')
            && function_exists('gd_info')
            && (gd_info()['WebP Support'] ?? false) === true;
    }

    /**
     * Apply a JPEG's EXIF orientation when Intervention could not.
     *
     * With the `exif` extension, Intervention reads the tag on decode, rotates,
     * and records Orientation 1. So a present tag means it has been handled;
     * only when Intervention has no EXIF data at all is the tag read here. It
     * is then handed to Intervention's own orient() so the eight-case rotation
     * table is the library's, not a second copy.
     */
    private function alignOrientation(ImageInterface $image, string $path, ?int $type): void
    {
        if ($type !== IMAGETYPE_JPEG || $image->exif('IFD0.Orientation') !== null) {
            return;
        }

        $orientation = self::jpegOrientation($path);
        if ($orientation === null || $orientation === 1) {
            return;
        }

        $image->setExif(new Collection(['IFD0' => ['Orientation' => $orientation]]));
        $image->orient();
    }

    /**
     * Read the Orientation tag (0x0112) from a JPEG's IFD0, or null.
     *
     * Walks the JPEG segments to APP1 "Exif\0\0", then the TIFF header (either
     * byte order) and IFD0's entries. Only the first 64 KB is read: APP1 must
     * precede the image data and cannot exceed 64 KB. Every offset is bounds-
     * checked, so a truncated or hostile header yields null, never a warning.
     */
    public static function jpegOrientation(string $path): ?int
    {
        $data = @file_get_contents($path, false, null, 0, 65536);
        if (! is_string($data) || strncmp($data, "\xFF\xD8", 2) !== 0) {
            return null;
        }

        $len = strlen($data);
        $pos = 2;
        while ($pos + 4 <= $len && $data[$pos] === "\xFF") {
            $marker = ord($data[$pos + 1]);
            $segment = unpack('n', $data, $pos + 2)[1];
            if ($marker === 0xDA || $segment < 2) {
                return null;                                   // start of scan: no APP1
            }
            if ($marker === 0xE1 && substr($data, $pos + 4, 6) === "Exif\0\0") {
                return self::orientationFromTiff(substr($data, $pos + 10, $segment - 8));
            }
            $pos += 2 + $segment;
        }

        return null;
    }

    private static function orientationFromTiff(string $tiff): ?int
    {
        $len = strlen($tiff);
        if ($len < 8) {
            return null;
        }

        $order = substr($tiff, 0, 2);
        if ($order !== 'II' && $order !== 'MM') {
            return null;
        }
        $short = $order === 'II' ? 'v' : 'n';
        $long = $order === 'II' ? 'V' : 'N';

        $ifd = unpack($long, $tiff, 4)[1];
        if ($ifd + 2 > $len) {
            return null;
        }

        $entries = unpack($short, $tiff, $ifd)[1];
        for ($i = 0; $i < $entries; $i++) {
            $entry = $ifd + 2 + $i * 12;
            if ($entry + 12 > $len) {
                return null;
            }
            if (unpack($short, $tiff, $entry)[1] === 0x0112) {
                $value = unpack($short, $tiff, $entry + 8)[1];

                return $value >= 1 && $value <= 8 ? $value : null;
            }
        }

        return null;
    }
}
