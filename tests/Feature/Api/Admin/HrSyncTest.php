<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Admin;
use App\Models\JobTitle;
use App\Models\User;
use App\Services\HRSystemService;
use App\Services\HrEmployeeSyncService;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Api\ApiTestCase;

/**
 * "Update Job titles" / "Update Learners" (D-088, NEW2B-6184) and the HR
 * fields they bring (NEW2B-6227). HR is faked: no test talks to it.
 */
class HrSyncTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->forgetHrSync();
        // HR is set up (the fake below answers); one test blanks it.
        config(['hr.base_url' => 'https://hr.example.test', 'hr.admin_email' => 'sync@example.test', 'hr.admin_password' => 'test-only']);

        $this->app->instance(HRSystemService::class, new class extends HRSystemService {
            public function __construct() {}

            public function getAllEmployees(string $culture = 'ar')
            {
                return collect([(object) [
                    'id' => 7701, 'machineCode' => 'EMP-77', 'email' => 'sara@example.test', 'phone' => null,
                    'nationalId' => null, 'jobName' => 'محلل', 'name' => $culture === 'en' ? 'Sara Ali' : 'سارة علي',
                    'departmentName' => $culture === 'en' ? 'Human Resources' : 'الموارد البشرية',
                    'imageUrl' => 'Employee/abc123.jpg',
                ]]);
            }

            public function getAllJobs(string $culture = 'ar')
            {
                return collect([(object) ['id' => 9, 'name' => $culture === 'en' ? 'Analyst' : 'محلل']]);
            }
        });
    }

    /** Only the HR sync keys: a full flush drops state other tests rely on. */
    private function forgetHrSync(): void
    {
        Cache::forget(HrEmployeeSyncService::STATUS_KEY);
        Cache::lock(HrEmployeeSyncService::LOCK_KEY)->forceRelease();
    }

    public function test_the_sync_stores_names_and_department_in_both_languages(): void
    {
        $report = app(HrEmployeeSyncService::class)->sync();

        $this->assertSame(1, $report['employees']);
        $this->assertSame(1, $report['created']);
        $user = User::where('system_id', 7701)->firstOrFail();
        $this->assertSame('Sara Ali', $user->name_en);
        $this->assertSame('الموارد البشرية', $user->department_name);
        $this->assertSame('Human Resources', $user->department_name_en);
        $this->assertNotNull($user->job_title_id);
        // HR's photo (NEW2B-6219): kept as a path, served from HR's media folder.
        $this->assertSame('Employee/abc123.jpg', $user->hr_image_path);
        $this->assertSame('https://shantafactory.com/HR/AppMedia/Employee/abc123.jpg', $user->photoUrl());

        // A second run updates the same row.
        $this->assertSame(1, app(HrEmployeeSyncService::class)->sync()['updated']);
        $this->assertSame(1, User::where('system_id', 7701)->count());
    }

    public function test_an_admin_starts_the_update_and_reads_its_result(): void
    {
        ['headers' => $headers] = $this->adminToken();

        foreach (['/admin/learners/hr-sync', '/admin/job-titles/hr-sync'] as $url) {
            $this->forgetHrSync();
            $this->postJson(self::BASE.$url, [], $headers)->assertStatus(202)->assertJsonPath('result.state', 'running');

            // dispatchAfterResponse ran the job once the response was sent.
            $status = $this->getJson(self::BASE.$url, $headers)->assertOk()->json('result');
            $this->assertSame('done', $status['state']);
            $this->assertSame(1, $status['report']['employees']);
        }
        $this->assertTrue(User::where('machine_code', 'EMP-77')->exists());
    }

    public function test_without_the_hr_address_and_account_it_says_not_configured(): void
    {
        ['headers' => $headers] = $this->adminToken();
        config(['hr.admin_password' => '']);

        $this->postJson(self::BASE.'/admin/learners/hr-sync', [], $headers)->assertStatus(202);

        $status = $this->getJson(self::BASE.'/admin/learners/hr-sync', $headers)->assertOk()->json('result');
        $this->assertSame('failed', $status['state']);
        $this->assertSame('not_configured', $status['reason']);
        $this->assertFalse(User::where('machine_code', 'EMP-77')->exists());
    }

    public function test_a_second_press_while_running_does_not_start_another_run(): void
    {
        ['headers' => $headers] = $this->adminToken();
        Cache::put(HrEmployeeSyncService::STATUS_KEY, ['state' => 'running', 'started_at' => now()->toIso8601String()], 60);

        $this->postJson(self::BASE.'/admin/learners/hr-sync', [], $headers)->assertStatus(202);

        $this->assertFalse(User::where('machine_code', 'EMP-77')->exists());
    }

    public function test_who_may_start_it(): void
    {
        // Job Titles needs "edit"; Learners has only "view" (D-088).
        $role = Role::findOrCreate('job-titles-viewer', 'admin');
        $role->givePermissionTo(Permission::findOrCreate('view-job-titles', 'admin'));
        $admin = Admin::factory()->create();
        $admin->assignRole($role);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $viewer = ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken];

        $this->postJson(self::BASE.'/admin/job-titles/hr-sync', [], $viewer)->assertStatus(403);
        $this->postJson(self::BASE.'/admin/learners/hr-sync', [], $viewer)->assertStatus(403);

        ['headers' => $learner] = $this->userToken();
        $this->postJson(self::BASE.'/admin/learners/hr-sync', [], $learner)->assertStatus(403);
        $this->assertFalse(User::where('machine_code', 'EMP-77')->exists());
    }

    public function test_the_learners_list_searches_the_employee_id_filters_by_job_title_and_localises_the_department(): void
    {
        $analyst = JobTitle::query()->create(['name' => 'Analyst '.uniqid()]);
        $other   = JobTitle::query()->create(['name' => 'Driver '.uniqid()]);
        $sara = User::factory()->create(['machine_code' => 'EMP-4120', 'job_title_id' => $analyst->id,
            'department_name' => 'الموارد البشرية', 'department_name_en' => 'Human Resources']);
        User::factory()->create(['machine_code' => 'EMP-9999', 'job_title_id' => $other->id]);
        ['headers' => $headers] = $this->adminToken();

        $byId = $this->getJson(self::BASE.'/admin/learners?search=4120', $headers)->assertOk()->json('result');
        $this->assertSame([$sara->id], array_column($byId, 'id'));

        $byJob = $this->getJson(self::BASE.'/admin/learners?job_title_ids[]='.$analyst->id, $headers)->assertOk()->json('result');
        $this->assertSame([$sara->id], array_column($byJob, 'id'));

        $en = $this->getJson(self::BASE.'/admin/learners?search=4120', $headers + ['Accept-Language' => 'en'])->json('result.0.department_name');
        $ar = $this->getJson(self::BASE.'/admin/learners?search=4120', $headers + ['Accept-Language' => 'ar'])->json('result.0.department_name');
        $this->assertSame('Human Resources', $en);
        $this->assertSame('الموارد البشرية', $ar);

        $detail = $this->getJson(self::BASE.'/admin/learners/'.$sara->id, $headers + ['Accept-Language' => 'en'])->json('result.profile.department');
        $this->assertSame('Human Resources', $detail);
    }

    public function test_hr_photo_shows_when_nothing_was_uploaded_and_unsafe_paths_are_dropped(): void
    {
        $sara = User::factory()->create(['machine_code' => 'EMP-5120', 'image' => null, 'hr_image_path' => 'Employee/p 1.jpg']);
        ['headers' => $headers] = $this->adminToken();

        $row = $this->getJson(self::BASE.'/admin/learners?search=5120', $headers)->assertOk()->json('result.0.image');
        $this->assertNull($row); // a space is not a plain path
        $sara->update(['hr_image_path' => 'Employee/p1.jpg']);
        $this->assertSame('https://shantafactory.com/HR/AppMedia/Employee/p1.jpg',
            $this->getJson(self::BASE.'/admin/learners?search=5120', $headers)->json('result.0.image'));
        $this->assertSame('https://shantafactory.com/HR/AppMedia/Employee/p1.jpg',
            $this->getJson(self::BASE.'/admin/learners/'.$sara->id, $headers)->json('result.profile.image_url'));

        foreach (['../secret.jpg', 'https://evil.test/a.jpg', '//evil.test/a.jpg', 'Employee/a.php', ''] as $bad) {
            $this->assertNull(\App\Support\HrPhoto::path($bad), $bad);
        }
    }
}
