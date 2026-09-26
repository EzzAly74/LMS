<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Admin;
use App\Models\JobTitle;
use App\Models\QualificationSkill;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Api\ApiTestCase;

/**
 * Qualifications import / export (D-034; Figma 2066:99852 export menu,
 * 1983:44634 import menu).
 *
 * B4 built the import as "apply good rows, report bad ones" and overwrote a
 * qualification whose English name matched. D5 brought it in line with D-034:
 * all or nothing, a name in use is an error, problems by (row, column).
 */
class QualificationTransferTest extends ApiTestCase
{
    private const URL = self::BASE.'/admin/qualification-skills';

    private function csv(string $body): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('quals.csv', $body);
    }

    private function import(string $body, array $headers): array
    {
        return $this->post(self::URL.'/import', ['file' => $this->csv($body)], $headers + ['Accept' => 'application/json'])
            ->assertOk()->json();
    }

    // ------------------------------------------------------------------ export

    public function test_export_follows_the_search_and_carries_assignments_and_figures(): void
    {
        Excel::fake();
        $welding = QualificationSkill::query()->create(['name' => ['en' => 'Welding', 'ar' => 'لحام']]);
        QualificationSkill::query()->create(['name' => ['en' => 'First Aid', 'ar' => 'إسعاف']]);
        $jt = JobTitle::query()->create(['name' => 'فني', 'name_en' => 'Technician', 'name_ar' => 'فني']);
        $welding->jobTitles()->attach($jt->id);
        $u = User::factory()->create(['machine_code' => 'EMP42']);
        DB::table('user_qualification_skill')->insert(['user_id' => $u->id, 'qualification_skill_id' => $welding->id, 'created_at' => now(), 'updated_at' => now()]);
        ['headers' => $headers] = $this->adminToken();

        $this->get(self::URL.'/export?format=csv&search=weld', $headers + ['Accept-Language' => 'en'])->assertOk();

        Excel::assertDownloaded('qualifications-'.now()->format('Y-m-d').'.csv', function ($export) {
            $this->assertSame([
                'name_en', 'name_ar', 'job_titles', 'learner_employee_ids',
                'linked_courses', 'enrolled', 'job_titles_count', 'learners', 'certified', 'completion_percent',
            ], $export->headings());
            $rows = $export->collection();
            $this->assertCount(1, $rows);
            $this->assertSame(['Welding', 'لحام', 'Technician', 'EMP42', 0, 0, 1, 1, 1, 100], $export->map($rows->first()));

            return true;
        });
    }

    public function test_the_template_is_the_importable_columns_and_no_rows(): void
    {
        Excel::fake();
        QualificationSkill::query()->create(['name' => ['en' => 'Should Not Appear', 'ar' => 'س']]);
        ['headers' => $headers] = $this->adminToken();

        $this->get(self::URL.'/import-template', $headers)->assertOk();

        Excel::assertDownloaded('qualifications-template.xlsx', function ($export) {
            $this->assertSame(['name_en', 'name_ar', 'job_titles', 'learner_employee_ids'], $export->headings());
            $this->assertCount(0, $export->collection());

            return true;
        });
    }

    public function test_an_unknown_format_is_refused(): void
    {
        ['headers' => $headers] = $this->adminToken();

        $this->getJson(self::URL.'/export?format=exe', $headers)->assertStatus(422);
        $this->getJson(self::URL.'/import-template?format=exe', $headers)->assertStatus(422);
    }

    // ------------------------------------------------------------------ import

    public function test_import_creates_qualifications_with_job_titles_and_learners(): void
    {
        $jt = JobTitle::query()->create(['name' => 'فني', 'name_en' => 'Technician', 'name_ar' => 'فني']);
        $driver = JobTitle::query()->create(['name' => 'Driver']);
        $u = User::factory()->create(['machine_code' => 'EMP42']);
        ['model' => $admin, 'headers' => $headers] = $this->adminToken();

        $body = $this->import(
            "name_en,name_ar,job_titles,learner_employee_ids,enrolled\n"
            ."Welding,لحام,technician | DRIVER,emp42,99\n"
            ."First Aid,إسعاف,فني,,\n",
            $headers,
        );

        $this->assertSame(['created' => 2, 'errors' => []], $body['result']);
        $welding = QualificationSkill::query()->get()->first(fn ($q) => $q->getTranslation('name', 'en') === 'Welding');
        $this->assertEqualsCanonicalizing([$jt->id, $driver->id], $welding->jobTitles()->pluck('job_titles.id')->all());
        $this->assertDatabaseHas('user_qualification_skill', ['user_id' => $u->id, 'qualification_skill_id' => $welding->id, 'assigned_by' => $admin->id]);
    }

    public function test_one_bad_row_means_nothing_is_written(): void
    {
        ['headers' => $headers] = $this->adminToken();

        $body = $this->import("name_en,name_ar\nGood One,جيد\nBroken,\nGood Two,جيد٢\n", $headers);

        $this->assertSame(0, $body['result']['created']);
        $this->assertSame([['row' => 3, 'column' => 'name_ar', 'message' => __('messages.import_cell_required')]], $body['result']['errors']);
        $this->assertSame(__('messages.import_rejected'), $body['message']);
        $this->assertSame(0, QualificationSkill::query()->count());
    }

    public function test_a_name_in_use_is_an_error_never_an_overwrite(): void
    {
        QualificationSkill::query()->create(['name' => ['en' => 'Welding', 'ar' => 'قديم']]);
        ['headers' => $headers] = $this->adminToken();

        $errors = $this->import("name_en,name_ar\nWELDING,لحام\nNew,قديم\nTwice,مرتين\ntwice,مرة\n", $headers)['result']['errors'];

        $this->assertSame([
            ['row' => 2, 'column' => 'name_en'],
            ['row' => 3, 'column' => 'name_ar'],
            ['row' => 5, 'column' => 'name_en'],
        ], array_map(fn ($e) => ['row' => $e['row'], 'column' => $e['column']], $errors));
        $this->assertSame(1, QualificationSkill::query()->count());
        $this->assertSame('قديم', QualificationSkill::query()->first()->getTranslation('name', 'ar'));
    }

    public function test_unknown_or_ambiguous_references_are_reported_never_invented(): void
    {
        JobTitle::query()->create(['name' => 'Clerk']);
        JobTitle::query()->create(['name' => 'Other', 'name_en' => 'Clerk']);
        User::factory()->create(['machine_code' => 'DUP1']);
        User::factory()->create(['machine_code' => 'DUP1']);
        ['headers' => $headers] = $this->adminToken();

        $errors = $this->import("name_en,name_ar,job_titles,learner_employee_ids\nA,أ,Ghost Role|Clerk,NOPE|DUP1\n", $headers)['result']['errors'];

        $this->assertSame([
            ['row' => 2, 'column' => 'job_titles', 'message' => __('messages.import_unknown_job_title', ['name' => 'Ghost Role'])],
            ['row' => 2, 'column' => 'job_titles', 'message' => __('messages.import_ambiguous_job_title', ['name' => 'Clerk'])],
            ['row' => 2, 'column' => 'learner_employee_ids', 'message' => __('messages.import_unknown_employee', ['id' => 'NOPE'])],
            ['row' => 2, 'column' => 'learner_employee_ids', 'message' => __('messages.import_ambiguous_employee', ['id' => 'DUP1'])],
        ], $errors);
        $this->assertSame(0, QualificationSkill::query()->count());
        $this->assertSame(0, JobTitle::query()->where('name', 'Ghost Role')->count());
    }

    public function test_a_file_missing_required_columns_is_rejected_whole(): void
    {
        ['headers' => $headers] = $this->adminToken();

        $body = $this->import("wrong_column\nvalue\n", $headers);

        $this->assertSame(1, $body['result']['errors'][0]['row']);
        $this->assertSame(0, QualificationSkill::query()->count());
    }

    public function test_a_file_that_is_not_a_spreadsheet_is_refused_by_its_content(): void
    {
        ['headers' => $headers] = $this->adminToken();
        // A real file, not UploadedFile::fake(): a fake reports the MIME type of
        // its extension, so it cannot show that the content is what is checked.
        $path = tempnam(sys_get_temp_dir(), 'qual');
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        $file = new UploadedFile($path, 'quals.csv', null, null, true);

        $this->post(self::URL.'/import', ['file' => $file], $headers + ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('file', 'errors');
        $this->assertSame(0, QualificationSkill::query()->count());
    }

    public function test_an_unreadable_file_is_reported_not_a_500(): void
    {
        ['headers' => $headers] = $this->adminToken();

        $response = $this->post(self::URL.'/import', [
            'file' => UploadedFile::fake()->createWithContent('broken.xlsx', 'PK truncated rubbish'),
        ], $headers + ['Accept' => 'application/json']);

        $this->assertNotSame(500, $response->getStatusCode());
        $this->assertSame(0, QualificationSkill::query()->count());
    }

    public function test_import_requires_a_file(): void
    {
        ['headers' => $headers] = $this->adminToken();

        $this->post(self::URL.'/import', [], $headers + ['Accept' => 'application/json'])->assertStatus(422);
    }

    // ------------------------------------------------------------------- authz

    public function test_an_admin_without_view_qualifications_is_refused(): void
    {
        $admin = Admin::factory()->create();
        $admin->assignRole(tap(Role::findOrCreate('qual-restricted', 'admin'))->givePermissionTo(Permission::findOrCreate('view-dashboard', 'admin')));
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        ['headers' => $headers] = $this->adminToken($admin);

        $this->getJson(self::URL.'/export', $headers)->assertForbidden();
        $this->getJson(self::URL.'/import-template', $headers)->assertForbidden();
        $this->postJson(self::URL.'/import', [], $headers)->assertForbidden();
    }

    public function test_a_learner_and_a_guest_cannot_import_or_export(): void
    {
        ['headers' => $headers] = $this->userToken();

        $this->getJson(self::URL.'/export', $headers)->assertForbidden();
        $this->postJson(self::URL.'/import', [], $headers)->assertForbidden();
        $this->getJson(self::URL.'/export')->assertUnauthorized();
        $this->postJson(self::URL.'/import', [])->assertUnauthorized();
    }
}
