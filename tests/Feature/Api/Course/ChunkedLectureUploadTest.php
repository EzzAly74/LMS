<?php

namespace Tests\Feature\Api\Course;

use App\Models\Admin;
use App\Models\Course;
use App\Services\ChunkedUploadService;
use App\Support\Mp4FastStart;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Api\ApiTestCase;

/**
 * D-111: a lesson video bigger than one request may be (a 3-minute video was
 * refused: 413 at PHP's 8 MB) is sent in parts, resumable, checked by its
 * content, and an MP4 is made fast-start so it plays before it downloads.
 */
class ChunkedLectureUploadTest extends ApiTestCase
{
    private Course $course;
    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['filesystems.default' => 'local']);
        $this->course = Course::factory()->create();
        ['headers' => $this->headers] = $this->adminToken();
    }

    private function url(string $tail = ''): string
    {
        return self::BASE."/courses/{$this->course->id}/lectures/uploads{$tail}";
    }

    private function sendPart(string $id, int $offset, string $bytes, ?array $headers = null): TestResponse
    {
        $server = [];
        foreach (($headers ?? $this->headers) + ['Accept' => 'application/json', 'Content-Type' => 'application/octet-stream'] as $k => $v) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
        }
        $server['CONTENT_TYPE'] = 'application/octet-stream';

        return $this->call('PUT', $this->url("/{$id}?offset={$offset}"), [], [], [], $server, $bytes);
    }

    private function box(string $type, string $payload): string
    {
        return pack('N', 8 + strlen($payload)).$type.$payload;
    }

    /** A minimal MP4 whose index comes after its media, as phones record it. */
    private function slowMp4(string $media): array
    {
        $ftyp = $this->box('ftyp', 'isom'.pack('N', 512).'isomiso2');
        $mdat = $this->box('mdat', $media);
        $mediaAt = strlen($ftyp) + 8;
        $stco = $this->box('stco', pack('N', 0).pack('N', 1).pack('N', $mediaAt));
        $moov = $this->box('moov', $this->box('mvhd', str_repeat("\0", 100))
            .$this->box('trak', $this->box('mdia', $this->box('minf', $this->box('stbl', $stco)))));

        return [$ftyp.$mdat.$moov, strlen($moov)];
    }

    private function upload(string $name, string $bytes, int $part = 1000): TestResponse
    {
        $id = $this->postJson($this->url(), ['name' => $name, 'size' => strlen($bytes)], $this->headers)->assertCreated()->json('result.id');
        for ($at = 0; $at < strlen($bytes); $at += $part) {
            $this->sendPart($id, $at, substr($bytes, $at, $part))->assertOk()->assertJsonPath('result.received', min(strlen($bytes), $at + $part));
        }

        return $this->postJson($this->url("/{$id}/complete"), [], $this->headers);
    }

    public function test_a_video_sent_in_parts_is_stored_fast_start_and_plays_from_the_front(): void
    {
        $media = str_repeat('MEDIA-0123456789', 300);
        [$file, $moovLen] = $this->slowMp4($media);

        $res = $this->upload('lesson 1.mp4', $file, 1024)->assertOk()
            ->assertJsonPath('result.name', 'lesson 1.mp4')->assertJsonPath('result.size', strlen($file));
        $path = $res->json('result.path');
        $this->assertStringStartsWith('course-lectures/', $path);
        $this->assertStringEndsWith('.mp4', $path);
        Storage::disk('public')->assertExists($path);

        $stored = Storage::disk('public')->get($path);
        $this->assertSame(strlen($file), strlen($stored));
        // ftyp, then the index, then the media.
        $this->assertSame('moov', substr($stored, 24 + 4, 4));
        $offset = unpack('N', substr($stored, strpos($stored, 'stco') + 12, 4))[1];
        $this->assertSame(24 + $moovLen + 8, $offset);
        $this->assertSame($media, substr($stored, $offset, strlen($media)), 'the index points at the same media');
    }

    public function test_a_lost_part_is_resumed_from_what_arrived(): void
    {
        $bytes = '%PDF-1.4 '.str_repeat('x', 2000);
        $id = $this->postJson($this->url(), ['name' => 'notes.pdf', 'size' => strlen($bytes)], $this->headers)->assertCreated()->json('result.id');

        $this->sendPart($id, 0, substr($bytes, 0, 1000))->assertOk();
        // The client thinks part 2 failed and sends it at the wrong offset: told where to resume.
        $this->sendPart($id, 1500, substr($bytes, 1500))->assertStatus(409)->assertJsonPath('result.received', 1000);
        $this->getJson($this->url("/{$id}"), $this->headers)->assertOk()->assertJsonPath('result.received', 1000);
        // Completing early is refused.
        $this->postJson($this->url("/{$id}/complete"), [], $this->headers)->assertStatus(409);

        $this->sendPart($id, 1000, substr($bytes, 1000))->assertOk()->assertJsonPath('result.received', strlen($bytes));
        $path = $this->postJson($this->url("/{$id}/complete"), [], $this->headers)->assertOk()->json('result.path');
        $this->assertSame($bytes, Storage::disk('public')->get($path));
    }

    public function test_a_part_past_the_announced_size_is_refused(): void
    {
        $id = $this->postJson($this->url(), ['name' => 'a.txt', 'size' => 10], $this->headers)->json('result.id');

        $this->sendPart($id, 0, str_repeat('a', 11))->assertStatus(409)->assertJsonPath('result.received', 0);
        $this->sendPart($id, 0, str_repeat('a', 10))->assertOk();
    }

    public function test_type_size_and_content_are_checked(): void
    {
        $this->postJson($this->url(), ['name' => 'run.php', 'size' => 10], $this->headers)->assertStatus(422);
        $this->postJson($this->url(), ['name' => 'big.mp4', 'size' => ChunkedUploadService::MAX_SIZE + 1], $this->headers)
            ->assertStatus(422)->assertJsonValidationErrors('size');

        // A PDF renamed .mp4 is refused at the end, and nothing is kept.
        $this->upload('fake.mp4', '%PDF-1.4 not a video')->assertStatus(422)
            ->assertJsonPath('errors.file.0', __('messages.upload_content'));
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_only_the_account_and_course_that_started_it_continue_it(): void
    {
        $id = $this->postJson($this->url(), ['name' => 'a.txt', 'size' => 5], $this->headers)->json('result.id');

        ['headers' => $other] = $this->adminToken(Admin::factory()->create());
        $this->sendPart($id, 0, 'hello', $other)->assertNotFound();
        $this->getJson($this->url("/{$id}"), $other)->assertNotFound();

        $elsewhere = Course::factory()->create();
        $this->getJson(self::BASE."/courses/{$elsewhere->id}/lectures/uploads/{$id}", $this->headers)->assertNotFound();

        ['headers' => $learner] = $this->userToken();
        $this->postJson($this->url(), ['name' => 'a.txt', 'size' => 5], $learner)->assertStatus(403);
    }

    public function test_unfinished_uploads_are_pruned_after_a_day(): void
    {
        $id = $this->postJson($this->url(), ['name' => 'a.txt', 'size' => 5], $this->headers)->json('result.id');
        $dir = storage_path('app/chunked-uploads/'.$id);
        $this->assertDirectoryExists($dir);

        $this->artisan('uploads:prune-chunks')->assertSuccessful();
        $this->assertDirectoryExists($dir, 'a fresh upload stays');

        touch($dir.'/meta.json', now()->subHours(25)->getTimestamp());
        touch($dir.'/data', now()->subHours(25)->getTimestamp());
        $this->artisan('uploads:prune-chunks')->assertSuccessful();
        $this->assertDirectoryDoesNotExist($dir);
    }

    public function test_an_mp4_already_fast_start_or_malformed_is_left_as_it_is(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mp4');
        $ftyp = $this->box('ftyp', 'isom'.pack('N', 512));
        file_put_contents($tmp, $ftyp.$this->box('moov', '').$this->box('mdat', 'abc'));
        $this->assertFalse(Mp4FastStart::apply($tmp));

        file_put_contents($tmp, $ftyp.pack('N', 999999).'mdat');
        $before = file_get_contents($tmp);
        $this->assertFalse(Mp4FastStart::apply($tmp));
        $this->assertSame($before, file_get_contents($tmp));
        @unlink($tmp);
    }
}
