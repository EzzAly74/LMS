<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Admin;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Api\ApiTestCase;

/**
 * GET admin/roles pages on request (the Roles screen's pager) and still
 * answers every role without `page` (the Controllers role picker).
 */
class AdminRoleListPaginationTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (range(1, 5) as $i) {
            DB::table('roles')->insert([
                'name'       => "pager_role_{$i}",
                'guard_name' => 'admin',
                'name_en'    => "Pager Role {$i}",
                'name_ar'    => "دور {$i}",
                'is_system'  => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** @return array<string,mixed> */
    private function list(string $query): array
    {
        ['headers' => $headers] = $this->adminToken();

        return $this->getJson(self::BASE.'/admin/roles'.$query, $headers)->assertOk()->json('result');
    }

    // ---------------------------------------------------------------- allowed

    public function test_a_page_holds_per_page_roles_and_the_paging_meta(): void
    {
        $first = $this->list('?search=Pager%20Role&page=1&per_page=2');

        $this->assertSame(['Pager Role 1', 'Pager Role 2'], array_column($first['roles'], 'name_en'));
        $this->assertSame(5, $first['total']);
        $this->assertSame(1, $first['current_page']);
        $this->assertSame(2, $first['per_page']);
        $this->assertSame(3, $first['last_page']);

        $last = $this->list('?search=Pager%20Role&page=3&per_page=2');
        $this->assertSame(['Pager Role 5'], array_column($last['roles'], 'name_en'));
        $this->assertArrayHasKey('total_views', $last);
    }

    public function test_without_a_page_every_role_comes_back_as_before(): void
    {
        $all = $this->list('?search=Pager%20Role');

        $this->assertCount(5, $all['roles']);
        $this->assertSame(5, $all['total']);
        $this->assertArrayNotHasKey('current_page', $all);
    }

    // ---------------------------------------------------------- invalid input

    public function test_per_page_is_capped_and_page_must_be_positive(): void
    {
        ['headers' => $headers] = $this->adminToken();

        $this->getJson(self::BASE.'/admin/roles?page=1&per_page=100', $headers)->assertOk();
        $this->getJson(self::BASE.'/admin/roles?page=1&per_page=101', $headers)->assertStatus(422);
        $this->getJson(self::BASE.'/admin/roles?page=0', $headers)->assertStatus(422);
        $this->getJson(self::BASE.'/admin/roles?page=abc', $headers)->assertStatus(422);
    }

    // -------------------------------------------------------------- forbidden

    public function test_an_admin_without_the_roles_view_is_refused(): void
    {
        $role = Role::findOrCreate('roles-pager-restricted', 'admin');
        $role->givePermissionTo(Permission::findOrCreate('view-dashboard', 'admin'));
        $admin = Admin::factory()->create();
        $admin->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $headers = ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken];

        $this->getJson(self::BASE.'/admin/roles?page=1', $headers)->assertStatus(403);
    }

    public function test_a_learner_is_refused(): void
    {
        ['headers' => $headers] = $this->userToken();

        $this->getJson(self::BASE.'/admin/roles?page=1', $headers)->assertStatus(403);
    }

    public function test_a_guest_is_unauthenticated(): void
    {
        $this->getJson(self::BASE.'/admin/roles?page=1')->assertStatus(401);
    }
}
