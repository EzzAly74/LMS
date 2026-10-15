<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Admin;
use App\Models\JobTitle;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Api\ApiTestCase;

/**
 * GET admin/reports/compliance-preview pages through every job-title row on
 * request; without `page` it keeps its original first-25 answer.
 */
class ReportCompliancePreviewPaginationTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Alpha Analyst' => 3, 'Beta Builder' => 2, 'Gamma Guard' => 1] as $name => $count) {
            $job = JobTitle::query()->create(['name' => $name, 'name_en' => $name, 'name_ar' => $name]);
            User::factory()->count($count)->create(['job_title_id' => $job->id]);
        }
    }

    /** @return array<string,mixed> */
    private function preview(string $query = ''): array
    {
        ['headers' => $headers] = $this->adminToken();

        return $this->getJson(self::BASE.'/admin/reports/compliance-preview'.$query, $headers)->assertOk()->json('result');
    }

    // ---------------------------------------------------------------- allowed

    public function test_pages_walk_every_row_largest_group_first(): void
    {
        $first = $this->preview('?page=1&per_page=2');
        $this->assertSame(['Alpha Analyst', 'Beta Builder'], array_column($first['rows'], 'role'));
        $this->assertSame(3, $first['total']);
        $this->assertSame(1, $first['current_page']);
        $this->assertSame(2, $first['per_page']);
        $this->assertSame(2, $first['last_page']);

        $second = $this->preview('?page=2&per_page=2');
        $this->assertSame(['Gamma Guard'], array_column($second['rows'], 'role'));
    }

    public function test_without_a_page_the_preview_keeps_its_original_shape(): void
    {
        $result = $this->preview();

        $this->assertCount(3, $result['rows']);
        $this->assertArrayNotHasKey('total', $result);
    }

    // ---------------------------------------------------------- invalid input

    public function test_per_page_is_capped_and_page_must_be_positive(): void
    {
        ['headers' => $headers] = $this->adminToken();
        $url = self::BASE.'/admin/reports/compliance-preview';

        $this->getJson($url.'?page=1&per_page=100', $headers)->assertOk();
        $this->getJson($url.'?page=1&per_page=101', $headers)->assertStatus(422);
        $this->getJson($url.'?page=0', $headers)->assertStatus(422);
    }

    // -------------------------------------------------------------- forbidden

    public function test_an_admin_without_the_reports_view_is_refused(): void
    {
        $role = Role::findOrCreate('reports-pager-restricted', 'admin');
        $role->givePermissionTo(Permission::findOrCreate('view-dashboard', 'admin'));
        $admin = Admin::factory()->create();
        $admin->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $headers = ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken];

        $this->getJson(self::BASE.'/admin/reports/compliance-preview?page=1', $headers)->assertStatus(403);
    }

    public function test_a_learner_is_refused_and_a_guest_is_unauthenticated(): void
    {
        ['headers' => $headers] = $this->userToken();

        $this->getJson(self::BASE.'/admin/reports/compliance-preview?page=1', $headers)->assertStatus(403);
        $this->getJson(self::BASE.'/admin/reports/compliance-preview?page=1')->assertStatus(401);
    }
}
