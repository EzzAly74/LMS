<?php

namespace Tests\Feature\Support;

use App\Support\UploadedImageOptimizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Intervention\Gif\Builder as GifBuilder;
use Tests\TestCase;

/**
 * IMG-01: display images uploaded from the Dashboard are re-encoded as WebP.
 *
 * Fixtures are built with GD at test time, so each test states exactly what
 * it feeds in: dimensions, format, EXIF orientation, animation.
 */
class UploadedImageOptimizerTest extends TestCase
{
    private function optimizer(): UploadedImageOptimizer
    {
        return app(UploadedImageOptimizer::class);
    }

    /** A real image file of the given size and GD format, as an upload. */
    private function upload(int $width, int $height, string $format = 'png', ?string $bytes = null): UploadedFile
    {
        if ($bytes === null) {
            $gd = imagecreatetruecolor($width, $height);
            // Two colours, so a rotation is observable in the pixels too.
            imagefilledrectangle($gd, 0, 0, intdiv($width, 2), $height, (int) imagecolorallocate($gd, 200, 30, 30));
            ob_start();
            $format === 'jpg' ? imagejpeg($gd, null, 90) : imagepng($gd);
            $bytes = (string) ob_get_clean();
        }

        $path = tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, "upload.{$format}", null, null, true);
    }

    /**
     * Insert a minimal EXIF APP1 segment carrying one Orientation tag right
     * after the JPEG's SOI marker - exactly where a camera writes it.
     */
    private function withOrientation(string $jpeg, int $orientation, bool $bigEndian = false): string
    {
        $s = $bigEndian ? 'n' : 'v';
        $l = $bigEndian ? 'N' : 'V';
        $tiff = ($bigEndian ? 'MM' : 'II').pack($s, 42).pack($l, 8)   // header, IFD0 at 8
            .pack($s, 1)                                               // one entry
            .pack($s, 0x0112).pack($s, 3).pack($l, 1)                  // Orientation, SHORT, count 1
            .pack($s, $orientation).pack($s, 0)                        // value, padded to 4 bytes
            .pack($l, 0);                                              // no next IFD
        $app1 = "Exif\0\0".$tiff;

        return "\xFF\xD8\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpeg, 2);
    }

    private function jpegBytes(int $width, int $height): string
    {
        $gd = imagecreatetruecolor($width, $height);
        ob_start();
        imagejpeg($gd, null, 90);

        return (string) ob_get_clean();
    }

    /** @return array{0:int,1:int,2:string} width, height, mime of WebP bytes */
    private function inspect(string $bytes): array
    {
        $info = getimagesizefromstring($bytes);
        $this->assertNotFalse($info, 'output is not a readable image');

        return [$info[0], $info[1], $info['mime']];
    }

    public function test_this_php_build_can_encode_webp(): void
    {
        // Every other test depends on it. If this fails, uploads are being
        // stored unconverted and a warning is being logged.
        $this->assertTrue(UploadedImageOptimizer::webpSupported());
    }

    public function test_a_png_becomes_webp_at_its_own_size(): void
    {
        $webp = $this->optimizer()->toWebp($this->upload(300, 200));

        $this->assertNotNull($webp);
        $this->assertSame('RIFF', substr($webp, 0, 4));
        $this->assertSame('WEBP', substr($webp, 8, 4));
        $this->assertSame([300, 200, 'image/webp'], $this->inspect($webp));
    }

    public function test_a_large_image_is_scaled_down_to_the_edge_cap_keeping_its_ratio(): void
    {
        $webp = $this->optimizer()->toWebp($this->upload(2560, 1280));

        [$w, $h] = $this->inspect((string) $webp);
        $this->assertSame(UploadedImageOptimizer::MAX_EDGE, $w);
        $this->assertSame(1024, $h);
    }

    public function test_a_small_image_is_never_enlarged(): void
    {
        [$w, $h] = $this->inspect((string) $this->optimizer()->toWebp($this->upload(64, 48)));

        $this->assertSame([64, 48], [$w, $h]);
    }

    public function test_a_jpeg_rotated_by_exif_is_stored_upright(): void
    {
        // Orientation 6 = the camera was turned 90 degrees clockwise: the
        // stored pixels are 40x20 landscape, the photo is 20x40 portrait.
        $jpeg = $this->withOrientation($this->jpegBytes(40, 20), 6);

        $this->assertSame(6, UploadedImageOptimizer::jpegOrientation($this->upload(0, 0, 'jpg', $jpeg)->getRealPath()));

        [$w, $h] = $this->inspect((string) $this->optimizer()->toWebp($this->upload(0, 0, 'jpg', $jpeg)));
        $this->assertSame([20, 40], [$w, $h]);
    }

    public function test_orientation_is_read_from_big_endian_exif_too(): void
    {
        $jpeg = $this->withOrientation($this->jpegBytes(40, 20), 8, bigEndian: true);

        $this->assertSame(8, UploadedImageOptimizer::jpegOrientation($this->upload(0, 0, 'jpg', $jpeg)->getRealPath()));
        $this->assertSame([20, 40], array_slice($this->inspect((string) $this->optimizer()->toWebp($this->upload(0, 0, 'jpg', $jpeg))), 0, 2));
    }

    public function test_an_upright_jpeg_keeps_its_dimensions(): void
    {
        $jpeg = $this->withOrientation($this->jpegBytes(40, 20), 1);

        $this->assertSame([40, 20], array_slice($this->inspect((string) $this->optimizer()->toWebp($this->upload(0, 0, 'jpg', $jpeg))), 0, 2));
        $this->assertSame([40, 20], array_slice($this->inspect((string) $this->optimizer()->toWebp($this->upload(40, 20, 'jpg'))), 0, 2));
    }

    public function test_a_truncated_or_hostile_exif_header_yields_no_orientation_and_no_error(): void
    {
        $jpeg = $this->jpegBytes(10, 10);
        $cases = [
            'no exif'           => $jpeg,
            'truncated app1'    => "\xFF\xD8\xFF\xE1\x00\x10Exif\0\0II",
            'ifd past the end'  => "\xFF\xD8\xFF\xE1\x00\x16Exif\0\0II*\0".pack('V', 9999).'xxxx',
            'bad byte order'    => "\xFF\xD8\xFF\xE1\x00\x16Exif\0\0XX*\0".pack('V', 8).'xxxx',
            'not a jpeg at all' => "\x89PNG\r\n\x1a\n",
        ];

        foreach ($cases as $label => $bytes) {
            $path = tempnam(sys_get_temp_dir(), 'exif');
            file_put_contents($path, $bytes);
            $this->assertNull(UploadedImageOptimizer::jpegOrientation($path), $label);
        }
    }

    public function test_the_output_carries_no_exif_metadata(): void
    {
        $jpeg = $this->withOrientation($this->jpegBytes(40, 20), 6);

        $webp = (string) $this->optimizer()->toWebp($this->upload(0, 0, 'jpg', $jpeg));

        // A WebP file keeps EXIF in an 'EXIF' chunk; GD never writes one.
        $this->assertStringNotContainsString('EXIF', $webp);
        $this->assertStringNotContainsString('Exif', $webp);
    }

    public function test_an_animated_gif_is_left_alone_so_its_animation_survives(): void
    {
        $frame = function (int $r): string {
            $gd = imagecreatetruecolor(8, 8);
            imagefill($gd, 0, 0, (int) imagecolorallocate($gd, $r, 0, 0));
            ob_start();
            imagegif($gd);

            return (string) ob_get_clean();
        };
        $gif = GifBuilder::canvas(8, 8)->addFrame($frame(255), 0.2)->addFrame($frame(10), 0.2)->setLoops(0)->encode();

        $this->assertNull($this->optimizer()->toWebp($this->upload(0, 0, 'gif', $gif)));
    }

    public function test_a_still_gif_is_converted(): void
    {
        $gd = imagecreatetruecolor(12, 9);
        ob_start();
        imagegif($gd);
        $gif = (string) ob_get_clean();

        $this->assertSame([12, 9, 'image/webp'], $this->inspect((string) $this->optimizer()->toWebp($this->upload(0, 0, 'gif', $gif))));
    }

    public function test_a_decompression_bomb_is_rejected_before_it_is_decoded(): void
    {
        // A PNG header claiming 5000x5000 (25 MP, ~100 MB for GD) with no real
        // pixel data. getimagesize() reads only the header; decoding it would
        // be the exhaustion this guards against.
        $ihdr = pack('N', 5000).pack('N', 5000)."\x08\x06\x00\x00\x00";
        $png = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr));

        try {
            $this->optimizer()->toWebp($this->upload(0, 0, 'png', $png), 'logo');
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('logo', $e->errors());
        }
    }

    public function test_an_unreadable_image_is_a_validation_error_not_a_crash(): void
    {
        $this->expectException(ValidationException::class);

        $this->optimizer()->toWebp($this->upload(0, 0, 'png', "\x89PNG\r\n\x1a\ngarbage"));
    }
}
