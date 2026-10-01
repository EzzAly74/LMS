<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Admin;
use App\Models\Instructor;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Api\ApiTestCase;

/**
 * The Users screen manages Dashboard accounts only (D-075), through the same
 * role rules as the Roles screen (D-073).
 */
class DashboardAccountTest extends ApiTestCase
{
    private function adminWith(string ...$permissions): array
    {
        $role = Role::findOrCreate('acct-'.uniqid(), 'admin');
        $role->givePermissionTo(array_map(fn ($n) => Permission::findOrCreate($n, 'admin'), $permissions));
        $admin = Admin::factory()->create();
        $admin->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return ['model' => $admin, 'role' => $role, 'headers' => ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken]];
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name_en' => 'Sara Ali', 'name_ar' => 'سارة علي', 'email' => uniqid().'@academy.test',
            'password' => 'Strong-Pass-2026', 'password_confirmation' => 'Strong-Pass-2026', 'role' => 'admin',
        ], $overrides);
    }

    public function test_the_list_holds_dashboard_accounts_not_website_learners(): void
    {
        ['headers' => $h, 'model' => $me] = $this->adminToken();
        $learner = User::factory()->create(['name' => 'Website Learner']);

        $rows = $this->getJson(self::BASE.'/admin/users?per_page=100', $h)->assertOk()->json('result');

        $this->assertContains($me->id, array_column($rows, 'id'));
        $this->assertSame(['admin'], array_values(array_unique(array_column($rows, 'source'))));
        $this->assertNotContains($learner->email, array_column($rows, 'email'));
    }

    public function test_the_role_options_leave_out_the_learner_role(): void
    {
        ['headers' => $h] = $this->adminToken();
        Role::findOrCreate('learner', 'admin');

        $keys = array_column($this->getJson(self::BASE.'/admin/users/filter-options', $h)->assertOk()->json('result.roles'), 'key');

        $this->assertNotContains('learner', $keys);
        $this->assertContains('instructor', $keys);
    }

    public function test_an_instructor_account_is_linked_to_its_instructor_record(): void
    {
        ['headers' => $h] = $this->adminToken();
        $existing = Instructor::query()->create(['name' => ['en' => 'Mona', 'ar' => 'منى'], 'email' => 'mona@academy.test']);

        $row = $this->postJson(self::BASE.'/admin/users', $this->payload([
            'email' => 'MONA@academy.test', 'role' => 'instructor', 'brief_en' => 'Excel trainer',
        ]), $h)->assertCreated()->json('result');

        $this->assertSame($existing->id, $row['instructor_id']);
        $this->assertSame('assigned', $row['course_scope']);
        $this->assertSame('Excel trainer', $existing->fresh()->getTranslation('bio', 'en'));
    }

    public function test_a_new_instructor_gets_an_instructor_record(): void
    {
        ['headers' => $h] = $this->adminToken();

        $row = $this->postJson(self::BASE.'/admin/users', $this->payload(['role' => 'instructor']), $h)
            ->assertCreated()->json('result');

        $this->assertNotNull($row['instructor_id']);
        $this->assertSame('سارة علي', Instructor::query()->find($row['instructor_id'])->getTranslation('name', 'ar'));
    }

    public function test_weak_passwords_and_the_learner_role_are_rejected(): void
    {
        ['headers' => $h] = $this->adminToken();

        $this->postJson(self::BASE.'/admin/users', $this->payload(['password' => 'short1', 'password_confirmation' => 'short1']), $h)
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->postJson(self::BASE.'/admin/users', $this->payload(['password' => 'alllowercase1', 'password_confirmation' => 'alllowercase1']), $h)
            ->assertStatus(422)->assertJsonValidationErrors('password');
        Role::findOrCreate('learner', 'admin');
        $this->postJson(self::BASE.'/admin/users', $this->payload(['role' => 'learner']), $h)
            ->assertStatus(422)->assertJsonValidationErrors('role');
    }

    public function test_nobody_assigns_a_role_with_more_access_than_they_have(): void
    {
        ['headers' => $h] = $this->adminWith('view-users', 'create-users', 'edit-users');

        $this->postJson(self::BASE.'/admin/users', $this->payload(['role' => 'admin']), $h)->assertForbidden();
        $this->postJson(self::BASE.'/admin/users', $this->payload(['role' => 'superAdmin']), $h)->assertForbidden();
        $this->assertDatabaseMissing('admins', ['name' => 'Sara Ali']);
    }

    public function test_nobody_changes_their_own_role_or_deactivates_themselves(): void
    {
        ['headers' => $h, 'model' => $me] = $this->adminToken();

        $this->putJson(self::BASE."/admin/users/admin/{$me->id}", ['role' => 'instructor'], $h)->assertForbidden();
        $this->deleteJson(self::BASE."/admin/users/admin/{$me->id}", [], $h)->assertForbidden();
        $this->putJson(self::BASE."/admin/users/admin/{$me->id}", ['name_en' => 'Renamed'], $h)->assertOk();
    }

    public function test_only_a_super_admin_touches_a_super_admin_account(): void
    {
        ['headers' => $h] = $this->adminToken();
        $super = Admin::factory()->create();
        $super->assignRole(Role::findOrCreate('superAdmin', 'admin'));

        $this->putJson(self::BASE."/admin/users/admin/{$super->id}", ['name_en' => 'X'], $h)->assertForbidden();
        $this->deleteJson(self::BASE."/admin/users/admin/{$super->id}", [], $h)->assertForbidden();
    }

    public function test_deactivating_an_account_signs_it_out_at_once(): void
    {
        ['headers' => $h] = $this->adminToken();
        ['model' => $target, 'headers' => $targetHeaders] = $this->adminWith('view-dashboard');

        $this->getJson(self::BASE.'/auth/admin/me', $targetHeaders)->assertOk();
        $this->deleteJson(self::BASE."/admin/users/admin/{$target->id}", [], $h)->assertOk()->assertJsonPath('result.status', 'deactivated');

        $this->assertSame(0, $target->tokens()->count());
        $this->getJson(self::BASE.'/auth/admin/me', $targetHeaders)->assertUnauthorized();
    }

    public function test_learner_item_routes_are_gone_from_users(): void
    {
        ['headers' => $h] = $this->adminToken();
        $learner = User::factory()->create();

        $this->getJson(self::BASE."/admin/users/user/{$learner->id}", $h)->assertNotFound();
        $this->deleteJson(self::BASE."/admin/users/user/{$learner->id}", [], $h)->assertNotFound();
    }

    public function test_learners_have_their_own_permission(): void
    {
        ['headers' => $users] = $this->adminWith('view-users');
        ['headers' => $learners] = $this->adminWith('view-learners');

        $this->getJson(self::BASE.'/admin/learners', $users)->assertForbidden();
        $this->getJson(self::BASE.'/admin/learners', $learners)->assertOk();
        $this->getJson(self::BASE.'/admin/users', $learners)->assertForbidden();
    }
}
