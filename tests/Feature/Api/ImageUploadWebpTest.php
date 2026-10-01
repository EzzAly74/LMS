<?php

namespace Tests\Feature\Api;

use App\Models\Course;
use App\Models\Instructor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

/**
 * IMG-01, end to end: a display image uploaded through the API is stored as
 * WebP, and a downloadable file is stored exactly as uploaded.
 */
class ImageUploadWebpTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function png(int $width, int $height): UploadedFile
    {
        return UploadedFile::fake()->image('photo.png', $width, $height);
    }

    /**
     * The Dashboard's "Add New User" modal (Figma 529:38878) - the flow that
     * actually creates instructors. The legacy POST /instructors is not used:
     * it has always failed on the NOT NULL `email` column (B-107).
     */
    private function createInstructorWithAvatar(string $email, UploadedFile $image): string
    {
        Role::findOrCreate('instructor', 'admin');
        ['headers' => $headers] = $this->adminToken();

        $this->withHeaders($headers)->post(self::BASE.'/admin/users', [
            'name_en'               => 'Webp Instructor',
            'name_ar'               => 'مدرب',
            'email'                 => $email,
            'role'                  => 'instructor',
            'password'              => 'Secret-pass-123',
            'password_confirmation' => 'Secret-pass-123',
            'image'                 => $image,
        ], ['Accept' => 'application/json'])->assertCreated();

        return (string) Instructor::query()->where('email', $email)->value('image');
    }

    public function test_an_uploaded_avatar_is_stored_as_webp(): void
    {
        // The account's avatar (D-075) is shared with its instructor record.
        $path = $this->createInstructorWithAvatar('webp@example.test', $this->png(640, 480));

        $this->assertStringStartsWith('admins/', $path);
        $this->assertStringEndsWith('.webp', $path);
        Storage::disk('public')->assertExists($path);

        $info = getimagesizefromstring(Storage::disk('public')->get($path));
        $this->assertSame([640, 480, 'image/webp'], [$info[0], $info[1], $info['mime']]);
    }

    public function test_an_oversized_upload_is_scaled_to_the_edge_cap(): void
    {
        $path = $this->createInstructorWithAvatar('big@example.test', $this->png(2400, 1600));

        $info = getimagesizefromstring(Storage::disk('public')->get($path));
        $this->assertSame([2048, 1365], [$info[0], $info[1]]);
    }

    public function test_a_rejected_image_stores_nothing(): void
    {
        ['headers' => $headers] = $this->adminToken();

        // An SVG is refused by validation before any conversion is attempted.
        Role::findOrCreate('instructor', 'admin');
        $this->withHeaders($headers)->post(self::BASE.'/admin/users', [
            'name_en'               => 'Svg Instructor',
            'name_ar'               => 'مدرب',
            'email'                 => 'svg@example.test',
            'role'                  => 'instructor',
            'password'              => 'Secret-pass-123',
            'password_confirmation' => 'Secret-pass-123',
            'image'                 => UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_a_lecture_attachment_is_stored_unconverted(): void
    {
        // Lecture files are downloads: a learner gets back the format that
        // was uploaded, so an image attachment stays a PNG.
        ['headers' => $headers] = $this->adminToken();
        $course = Course::factory()->create();

        $response = $this->withHeaders($headers)->post(self::BASE."/courses/{$course->id}/lectures/upload", [
            'file' => $this->png(100, 100),
        ], ['Accept' => 'application/json'])->assertOk();

        $path = (string) $response->json('result.path');
        $this->assertStringEndsWith('.png', $path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_uploading_requires_authentication(): void
    {
        $this->post(self::BASE.'/admin/users', [
            'name_en' => 'Anonymous',
            'image'   => $this->png(10, 10),
        ], ['Accept' => 'application/json'])->assertUnauthorized();

        $this->assertSame([], Storage::disk('public')->allFiles());
    }
}
