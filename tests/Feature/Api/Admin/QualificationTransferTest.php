<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Admin;
use App\Models\JobTitle;
use App\Models\QualificationSkill;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Api\ApiTestCase;

/**
 * Stage B / B4 — qualification import, export and one-step assignment.
 *
 * Figma 2066:99852 (export menu), 1983:44634 (import menu), 2066:100876 (New
 * Qualification modal). None of it had a backend.
 *
 * The learner half of the modal's assignment is NOT covered here because it is
 * not implemented — there is no learner-qualification table and adding one
 * changes the compliance figures elsewhere. See D-045.
 */
class QualificationTransferTest extends ApiTestCase
{
    private function csv(string $body, string $name = 'quals.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $body);
    }

    private function importUrl(): string
    {
        return self::BASE.'/admin/qualification-skills/import';
    }

    // ------------------------------------------------------------------ export

    public function test_export_returns_a_spreadsheet_with_the_template_columns(): void
    {
        $skill = QualificationSkill::query()->create(['name' => ['en' => 'Welding', 'ar' => 'لحام']]);
        $jt    = JobTitle::query()->create(['name' => 'Technician '.uniqid()]);
        $skill->jobTitles()->attach($jt->id);

        ['headers' => $headers] = $this->adminToken();

        $response = $this->get(self::BASE.'/admin/qualification-skills/export?format=csv', $headers);

        $response->assertOk();
        // Excel::download returns a BinaryFileResponse - the bytes are in the
        // temp file it points at, not in a streamed callback.
        $body = file_get_contents($response->getFile()->getPathname());

        $this->assertStringContainsString('name_en', $body);
        $this->assertStringContainsString('name_ar', $body);
        $this->assertStringContainsString('job_titles', $body);
        $this->assertStringContainsString('Welding', $body);
    }

    public function test_the_import_template_has_headers_and_no_rows(): void
    {
        QualificationSkill::query()->create(['name' => ['en' => 'Should Not Appear', 'ar' => 'س']]);
        ['headers' => $headers] = $this->adminToken();

        $response = $this->get(self::BASE.'/admin/qualification-skills/import-template?format=csv', $headers)
            ->assertOk();
        $body = file_get_contents($response->getFile()->getPathname());

        $this->assertStringContainsString('name_en', $body);
        // A template is a blank form; existing data must not leak into it.
        $this->assertStringNotContainsString('Should Not Appear', $body);
    }

    public function test_an_unknown_export_format_falls_back_rather_than_erroring(): void
    {
        ['headers' => $headers] = $this->adminToken();

        $this->get(self::BASE.'/admin/qualification-skills/export?format=exe', $headers)->assertOk();
    }

    // ------------------------------------------------------------------ import

    public function test_import_creates_new_qualifications(): void
    {
        ['headers' => $headers] = $this->adminToken();

        $report = $this->post($this->importUrl(), [
            'file' => $this->csv("name_en,name_ar,job_titles\nWelding,لحام,\nFirst Aid,إسعاف,\n"),
        ], $headers + ['Accept' => 'application/json'])->assertOk()->json('result');

        $this->assertSame(2, $report['created']);
        $this->assertSame(0, $report['updated']);
        $this->assertSame([], $report['errors']);
        $this->assertSame(2, QualificationSkill::query()->count());
    }

    public function test_import_updates_an_existing_qualification_rather_than_duplicating(): void
    {
        QualificationSkill::query()->create(['name' => ['en' => 'Welding', 'ar' => 'قديم']]);
        ['headers' => $headers] = $this->adminToken();

        $report = $this->post($this->importUrl(), [
            'file' => $this->csv("name_en,name_ar\nWelding,لحام\n"),
        ], $headers + ['Accept' => 'application/json'])->assertOk()->json('result');

        $this->assertSame(0, $report['created']);
        $this->assertSame(1, $report['updated']);
        $this->assertSame(1, QualificationSkill::query()->count());
        $this->assertSame('لحام', QualificationSkill::query()->first()->getTranslation('name', 'ar'));
    }

    public function test_import_links_job_titles_by_name(): void
    {
        $jt = JobTitle::query()->create(['name' => 'Technician']);
        ['headers' => $headers] = $this->adminToken();

        $this->post($this->importUrl(), [
            'file' => $this->csv("name_en,name_ar,job_titles\nWelding,لحام,Technician\n"),
        ], $headers + ['Accept' => 'application/json'])->assertOk();

        $this->assertSame(1, QualificationSkill::query()->first()->jobTitles()->count());
        $this->assertTrue($jt->qualificationSkills()->exists());
    }

    public function test_an_unknown_job_title_is_reported_and_never_invented(): void
    {
        ['headers' => $headers] = $this->adminToken();

        $report = $this->post($this->importUrl(), [
            'file' => $this->csv("name_en,name_ar,job_titles\nWelding,لحام,Ghost Role\n"),
        ], $headers + ['Accept' => 'application/json'])->assertOk()->json('result');

        // The qualification is still created; only the bad link is reported.
        $this->assertSame(1, $report['created']);
        $this->assertNotEmpty($report['errors']);
        $this->assertSame(2, $report['errors'][0]['row']);
        // A typo must not silently create a job title.
        $this->assertSame(0, JobTitle::query()->where('name', 'Ghost Role')->count());
    }

    public function test_a_partially_valid_file_applies_good_rows_and_reports_bad_ones(): void
    {
        ['headers' => $headers] = $this->adminToken();

        // Row 2 valid, row 3 missing the Arabic name, row 4 valid.
        $report = $this->post($this->importUrl(), [
            'file' => $this->csv("name_en,name_ar\nGood One,جيد\nBroken,\nGood Two,جيد٢\n"),
        ], $headers + ['Accept' => 'application/json'])->assertOk()->json('result');

        $this->assertSame(2, $report['created']);
        $this->assertSame(1, $report['skipped']);
        $this->assertCount(1, $report['errors']);
        // Row numbers match what the admin sees in Excel (header is row 1).
        $this->assertSame(3, $report['errors'][0]['row']);
        $this->assertSame(2, QualificationSkill::query()->count());
    }

    public function test_a_file_missing_required_columns_is_rejected_whole(): void
    {
        ['headers' => $headers] = $this->adminToken();

        $report = $this->post($this->importUrl(), [
            'file' => $this->csv("wrong_column\nvalue\n"),
        ], $headers + ['Accept' => 'application/json'])->assertOk()->json('result');

        $this->assertSame(0, $report['created']);
        $this->assertNotEmpty($report['errors']);
        $this->assertSame(0, QualificationSkill::query()->count());
    }

    /**
     * A script renamed to .xlsx never reaches the database.
     *
     * Harness limitation, same as the B-02 upload tests: UploadedFile::fake()
     * reports the MIME type derived from the filename, not the bytes, so
     * `mimetypes:` cannot fire here the way it does in production (finfo sees
     * text/x-php and rejects with 422).
     *
     * What this asserts is the defence that holds either way: the request does
     * not 500, and nothing is written. Before the parse-failure handling was
     * added this returned a 500 with a stack trace from PhpSpreadsheet - which
     * is a real defect for any malformed upload, not only a hostile one.
     */
    public function test_a_disguised_script_never_reaches_the_database(): void
    {
        ['headers' => $headers] = $this->adminToken();

        $response = $this->post($this->importUrl(), [
            'file' => UploadedFile::fake()->createWithContent('payload.xlsx', '<?php echo shell_exec($_GET["c"]); ?>'),
        ], $headers + ['Accept' => 'application/json']);

        $this->assertContains($response->getStatusCode(), [200, 422]);
        $this->assertNotSame(500, $response->getStatusCode(), 'A bad upload must not surface as a server error.');
        $this->assertSame(0, QualificationSkill::query()->count());
    }

    public function test_an_unreadable_file_is_reported_not_a_500(): void
    {
        ['headers' => $headers] = $this->adminToken();

        $response = $this->post($this->importUrl(), [
            'file' => UploadedFile::fake()->createWithContent('broken.xlsx', "PK truncated rubbish"),
        ], $headers + ['Accept' => 'application/json']);

        $this->assertNotSame(500, $response->getStatusCode());
        $this->assertSame(0, QualificationSkill::query()->count());
    }

    public function test_import_requires_a_file(): void
    {
        ['headers' => $headers] = $this->adminToken();

        $this->post($this->importUrl(), [], $headers + ['Accept' => 'application/json'])->assertStatus(422);
    }

    // ------------------------------------------- one-step assignment (2066:100876)

    public function test_a_qualification_can_be_created_and_assigned_in_one_request(): void
    {
        $a = JobTitle::query()->create(['name' => 'Technician '.uniqid()]);
        $b = JobTitle::query()->create(['name' => 'Driver '.uniqid()]);
        ['headers' => $headers] = $this->adminToken();

        $this->postJson(self::BASE.'/qualification-skills', [
            'name'          => ['en' => 'Forklift', 'ar' => 'رافعة'],
            'job_title_ids' => [$a->id, $b->id],
        ], $headers)->assertCreated();

        $skill = QualificationSkill::query()->first();
        $this->assertSame(2, $skill->jobTitles()->count());
    }

    public function test_creating_without_assignment_still_works(): void
    {
        ['headers' => $headers] = $this->adminToken();

        $this->postJson(self::BASE.'/qualification-skills', [
            'name' => ['en' => 'Standalone', 'ar' => 'مستقل'],
        ], $headers)->assertCreated();

        $this->assertSame(0, QualificationSkill::query()->first()->jobTitles()->count());
    }

    public function test_an_unknown_job_title_id_is_a_422_not_a_silent_drop(): void
    {
        ['headers' => $headers] = $this->adminToken();

        $this->postJson(self::BASE.'/qualification-skills', [
            'name'          => ['en' => 'Bad', 'ar' => 'سيئ'],
            'job_title_ids' => [999999],
        ], $headers)->assertStatus(422);

        $this->assertSame(0, QualificationSkill::query()->count());
    }

    // ------------------------------------------------------------------- authz

    public function test_an_admin_without_view_qualifications_is_refused(): void
    {
        $role = Role::findOrCreate('qual-restricted', 'admin');
        $role->givePermissionTo(Permission::findOrCreate('view-dashboard', 'admin'));
        $admin = Admin::factory()->create();
        $admin->assignRole($role);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $headers = ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken];

        $this->getJson(self::BASE.'/admin/qualification-skills/export', $headers)->assertStatus(403);
        $this->getJson(self::BASE.'/admin/qualification-skills/import-template', $headers)->assertStatus(403);
        $this->postJson($this->importUrl(), [], $headers)->assertStatus(403);
    }

    public function test_a_learner_cannot_import_or_export(): void
    {
        ['headers' => $headers] = $this->userToken();

        $this->getJson(self::BASE.'/admin/qualification-skills/export', $headers)->assertStatus(403);
        $this->postJson($this->importUrl(), [], $headers)->assertStatus(403);
    }

    public function test_a_guest_is_unauthenticated(): void
    {
        $this->getJson(self::BASE.'/admin/qualification-skills/export')->assertStatus(401);
        $this->postJson($this->importUrl(), [])->assertStatus(401);
    }
}
