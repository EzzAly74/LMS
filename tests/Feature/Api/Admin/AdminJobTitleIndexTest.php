<?php

namespace Tests\Feature\Api\Admin;

use App\Models\JobTitle;
use App\Models\QualificationSkill;
use App\Models\User;
use Tests\Feature\Api\ApiTestCase;

/**
 * D1b - GET admin/job-titles (Figma 2078:102691) and
 * GET admin/job-titles/learner-options (Filter modal 2463:138054).
 */
class AdminJobTitleIndexTest extends ApiTestCase
{
    private JobTitle $hr;

    private JobTitle $sales;

    private QualificationSkill $safety;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hr     = JobTitle::query()->create(['name' => 'HR Specialist', 'name_en' => 'HR Specialist', 'name_ar' => 'أخصائي موارد بشرية']);
        $this->sales  = JobTitle::query()->create(['name' => 'Sales Representative', 'name_en' => 'Sales Representative', 'name_ar' => 'مندوب مبيعات']);
        $this->safety = QualificationSkill::query()->create(['name' => 'Safety']);
        $this->hr->qualificationSkills()->attach($this->safety->id);
    }

    private function names(string $query = ''): array
    {
        ['headers' => $headers] = $this->adminToken();

        return collect($this->getJson(self::BASE.'/admin/job-titles'.$query, $headers)->assertOk()->json('result'))
            ->pluck('name')->all();
    }

    // ---------------------------------------------------------------- allowed

    public function test_the_index_returns_the_card_metrics(): void
    {
        User::factory()->count(2)->create(['job_title_id' => $this->hr->id]);
        ['headers' => $headers] = $this->adminToken();

        $row = collect($this->getJson(self::BASE.'/admin/job-titles', $headers)->assertOk()->json('result'))
            ->firstWhere('id', $this->hr->id);

        $this->assertSame(2, $row['employees_count']);
        $this->assertSame(1, $row['qualifications_count']);
        $this->assertArrayHasKey('compliance_percent', $row);
    }

    public function test_search_matches_the_job_title_name(): void
    {
        $this->assertSame(['Sales Representative'], $this->names('?search=sales'));
    }

    public function test_search_matches_an_employee_name_or_id(): void
    {
        User::factory()->create(['name' => 'Ava Chen', 'machine_code' => 'AC-2048', 'job_title_id' => $this->sales->id]);

        $this->assertSame(['Sales Representative'], $this->names('?search=ava'));
        $this->assertSame(['Sales Representative'], $this->names('?search=AC-20'));
    }

    public function test_filter_by_qualification(): void
    {
        $this->assertSame(['HR Specialist'], $this->names('?qualification_ids[]='.$this->safety->id));
    }

    public function test_filter_by_learner_returns_their_job_title(): void
    {
        $ava = User::factory()->create(['job_title_id' => $this->sales->id]);

        $this->assertSame(['Sales Representative'], $this->names('?learner_id='.$ava->id));
    }

    public function test_filters_combine(): void
    {
        $ava = User::factory()->create(['job_title_id' => $this->sales->id]);

        // Sales does not require Safety, so nothing matches both.
        $this->assertSame([], $this->names('?learner_id='.$ava->id.'&qualification_ids[]='.$this->safety->id));
    }

    public function test_like_wildcards_are_literal(): void
    {
        $this->assertSame([], $this->names('?search=%25'));
    }

    public function test_learner_options_search_learners_with_a_job_title(): void
    {
        User::factory()->create(['name' => 'Ava Chen', 'machine_code' => 'AC-2048', 'job_title_id' => $this->hr->id]);
        User::factory()->create(['name' => 'Ava Nobody', 'job_title_id' => null]);
        ['headers' => $headers] = $this->adminToken();

        $rows = $this->getJson(self::BASE.'/admin/job-titles/learner-options?search=ava', $headers)->assertOk()->json('result');

        $this->assertSame(['Ava Chen'], array_column($rows, 'name'));
        $this->assertSame('AC-2048', $rows[0]['employee_id']);
        $this->assertSame(['id', 'name', 'employee_id'], array_keys($rows[0]));
    }

    // ---------------------------------------------------------------- invalid

    public function test_invalid_filters_are_rejected(): void
    {
        ['headers' => $headers] = $this->adminToken();

        $this->getJson(self::BASE.'/admin/job-titles?qualification_ids[]=999999', $headers)->assertStatus(422);
        $this->getJson(self::BASE.'/admin/job-titles?learner_id=abc', $headers)->assertStatus(422);
        $this->getJson(self::BASE.'/admin/job-titles?per_page=500', $headers)->assertStatus(422);
    }

    // -------------------------------------------------------------- forbidden

    public function test_an_admin_without_the_permission_is_refused(): void
    {
        $role = \Spatie\Permission\Models\Role::findOrCreate('jt-index-restricted', 'admin');
        $role->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('view-dashboard', 'admin'));
        $admin = \App\Models\Admin::factory()->create();
        $admin->assignRole($role);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $headers = ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken];

        $this->getJson(self::BASE.'/admin/job-titles', $headers)->assertStatus(403);
        $this->getJson(self::BASE.'/admin/job-titles/learner-options', $headers)->assertStatus(403);
    }

    public function test_a_learner_cannot_search_job_titles_by_person(): void
    {
        ['headers' => $headers] = $this->userToken();

        $this->getJson(self::BASE.'/admin/job-titles?search=ava', $headers)->assertStatus(403);
        $this->getJson(self::BASE.'/admin/job-titles/learner-options', $headers)->assertStatus(403);
    }

    public function test_a_guest_is_unauthenticated(): void
    {
        $this->getJson(self::BASE.'/admin/job-titles')->assertStatus(401);
    }
}
