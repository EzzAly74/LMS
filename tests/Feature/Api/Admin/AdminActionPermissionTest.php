<?php

namespace Tests\Feature\Api\Admin;

use App\Http\Middleware\AdminSectionMiddleware;
use App\Models\Admin;
use App\Models\Course;
use App\Support\Permissions\AdminSections;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Api\ApiTestCase;

/**
 * The per-action permission matrix (D-073): view / create / edit / delete are
 * separate permissions per section, enforced on the server, and the Roles
 * screen cannot be used to escalate.
 */
class AdminActionPermissionTest extends ApiTestCase
{
    /** @return array{model:Admin, role:Role, headers:array<string,string>} */
    private function adminWith(string ...$permissions): array
    {
        $role = Role::findOrCreate('matrix-'.uniqid(), 'admin');
        foreach ($permissions as $name) {
            $role->givePermissionTo(Permission::findOrCreate($name, 'admin'));
        }

        $admin = Admin::factory()->create();
        $admin->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return [
            'model'   => $admin,
            'role'    => $role,
            'headers' => ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken],
        ];
    }

    private function superAdmin(): array
    {
        $admin = Admin::factory()->create();
        $admin->assignRole(Role::findOrCreate('superAdmin', 'admin'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return ['model' => $admin, 'headers' => ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken]];
    }

    // ------------------------------------------------------------ structure

    public function test_every_section_route_names_a_known_section_and_a_supported_action(): void
    {
        $problems = [];

        foreach (Route::getRoutes() as $route) {
            $middleware = array_filter($route->gatherMiddleware(), 'is_string');
            foreach ($middleware as $m) {
                if (! str_starts_with($m, AdminSectionMiddleware::class.':')) {
                    continue;
                }
                $section = substr($m, strlen(AdminSectionMiddleware::class) + 1);
                foreach ($route->methods() as $method) {
                    if ($method === 'HEAD') {
                        continue;
                    }
                    $label = $method.' '.$route->uri();
                    if (! AdminSections::has($section)) {
                        $problems[] = "$label: unknown section '$section'";
                        continue;
                    }
                    // A POST is as often an edit as a create: it must say which.
                    if ($method === 'POST' && ! is_string($route->getAction('ability'))) {
                        $problems[] = "$label: a POST must declare ->ability()";
                    }
                    $action = AdminSectionMiddleware::actionFor($route, $method);
                    if (! AdminSections::supports($section, (string) $action)) {
                        $problems[] = "$label: section '$section' has no '$action' action";
                    }
                }
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems));
    }

    public function test_every_matrix_permission_exists_and_the_super_admin_role_holds_it(): void
    {
        $names = AdminSections::permissionNames();
        $this->assertSame(
            count($names),
            Permission::query()->where('guard_name', 'admin')->whereIn('name', $names)->count(),
        );

        $super = Role::query()->where('name', 'superAdmin')->where('guard_name', 'admin')->first();
        $this->assertNotNull($super);
        $this->assertSame([], array_diff($names, $super->permissions()->pluck('name')->all()));
    }

    // ------------------------------------------------- each action is separate

    public function test_view_alone_reads_but_cannot_create_edit_or_delete(): void
    {
        ['headers' => $h] = $this->adminWith('view-categories');

        $this->getJson(self::BASE.'/categories', $h)->assertOk();
        $this->postJson(self::BASE.'/categories', [], $h)->assertStatus(403);
        $this->putJson(self::BASE.'/categories/1', [], $h)->assertStatus(403);
        $this->deleteJson(self::BASE.'/categories/1', [], $h)->assertStatus(403);
    }

    public function test_each_write_needs_its_own_permission(): void
    {
        ['headers' => $create] = $this->adminWith('view-categories', 'create-categories');
        ['headers' => $edit] = $this->adminWith('view-categories', 'edit-categories');

        // Past the gate: validation (422), not authorization (403).
        $this->postJson(self::BASE.'/categories', [], $create)->assertStatus(422);
        $this->putJson(self::BASE.'/categories/999999', [], $create)->assertStatus(403);

        $this->postJson(self::BASE.'/categories', [], $edit)->assertStatus(403);
        $this->deleteJson(self::BASE.'/categories/999999', [], $edit)->assertStatus(403);
    }

    public function test_a_post_that_edits_needs_edit_not_create(): void
    {
        $course = Course::factory()->create();
        ['headers' => $create] = $this->adminWith('view-courses', 'create-courses');
        ['headers' => $edit] = $this->adminWith('view-courses', 'edit-courses');

        // Adding a cohort edits the course.
        $this->postJson(self::BASE."/courses/{$course->id}/sections", [], $create)->assertStatus(403);
        $this->postJson(self::BASE."/courses/{$course->id}/sections", [], $edit)->assertStatus(422);
    }

    public function test_the_dashboard_home_needs_view_dashboard_not_view_controllers(): void
    {
        ['headers' => $home] = $this->adminWith('view-dashboard');
        ['headers' => $controllers] = $this->adminWith('view-controllers');

        $this->getJson(self::BASE.'/dashboard', $home)->assertOk();
        $this->getJson(self::BASE.'/dashboard', $controllers)->assertStatus(403);
    }

    public function test_me_reports_the_full_matrix(): void
    {
        ['headers' => $h] = $this->adminWith('view-courses', 'edit-courses');

        $me = $this->getJson(self::BASE.'/auth/admin/me', $h)->assertOk()->json('result');

        $this->assertEqualsCanonicalizing(['view-courses', 'edit-courses'], $me['permissions']);
        $this->assertSame(['view-courses'], $me['view_keys']);
        $this->assertSame('all', $me['course_scope']);
    }

    // ------------------------------------------------------- the Roles screen

    public function test_the_catalogue_lists_sections_with_their_actions(): void
    {
        ['headers' => $h] = $this->adminWith('view-roles');

        $catalog = $this->getJson(self::BASE.'/admin/roles/sections', $h)->assertOk()->json('result');

        $this->assertSame(['view', 'create', 'edit', 'delete'], array_column($catalog['actions'], 'key'));
        $items = collect($catalog['groups'])->flatMap(fn ($g) => $g['items'])->keyBy('key');
        $this->assertSame(['view', 'create', 'edit', 'delete'], $items['courses']['actions']);
        $this->assertSame(['view'], $items['audit-log']['actions']);
        $this->assertFalse($items->has('forms'), 'legacy sections stay off the form');
    }

    public function test_creating_a_role_stores_the_matrix_and_implies_view(): void
    {
        ['headers' => $h] = $this->superAdmin();

        $role = $this->postJson(self::BASE.'/admin/roles', [
            'name_en'     => 'Course editor',
            'name_ar'     => 'محرر الدورات',
            'permissions' => ['edit-courses', 'delete-courses'],
        ], $h)->assertCreated()->json('result');

        $this->assertEqualsCanonicalizing(['view-courses', 'edit-courses', 'delete-courses'], $role['permissions']);
        $this->assertSame('all', $role['course_scope']);
    }

    public function test_an_unknown_permission_is_a_validation_error(): void
    {
        ['headers' => $h] = $this->superAdmin();

        $this->postJson(self::BASE.'/admin/roles', [
            'name_en' => 'X', 'name_ar' => 'X', 'permissions' => ['launch-rockets'],
        ], $h)->assertStatus(422);
    }

    public function test_nobody_grants_a_permission_they_do_not_hold(): void
    {
        ['headers' => $h] = $this->adminWith('view-roles', 'create-roles', 'view-courses');

        $this->postJson(self::BASE.'/admin/roles', [
            'name_en' => 'Escalated', 'name_ar' => 'Escalated', 'permissions' => ['view-courses', 'delete-courses'],
        ], $h)->assertStatus(403);

        $this->postJson(self::BASE.'/admin/roles', [
            'name_en' => 'Fine', 'name_ar' => 'Fine', 'permissions' => ['view-courses'],
        ], $h)->assertCreated();
    }

    public function test_permissions_outside_the_editors_reach_are_kept_not_stripped(): void
    {
        $target = Role::findOrCreate('target-'.uniqid(), 'admin');
        $target->givePermissionTo(['view-users', 'edit-users', 'view-courses']);
        ['headers' => $h] = $this->adminWith('view-roles', 'edit-roles', 'view-courses', 'view-quizzes');

        $role = $this->putJson(self::BASE."/admin/roles/{$target->id}", [
            'permissions' => ['view-quizzes'],
        ], $h)->assertOk()->json('result');

        // view-courses was within reach and dropped; the users pair was not and stays.
        $this->assertEqualsCanonicalizing(['view-users', 'edit-users', 'view-quizzes'], $role['permissions']);
    }

    public function test_nobody_but_a_super_admin_edits_their_own_role_or_a_super_admin_role(): void
    {
        ['headers' => $h, 'role' => $own] = $this->adminWith('view-roles', 'edit-roles', 'delete-roles');
        $super = Role::query()->where('name', 'superAdmin')->first();

        $this->putJson(self::BASE."/admin/roles/{$own->id}", ['name_en' => 'Mine'], $h)->assertStatus(403);
        $this->putJson(self::BASE."/admin/roles/{$super->id}", ['permissions' => []], $h)->assertStatus(403);
        $this->deleteJson(self::BASE."/admin/roles/{$super->id}", [], $h)->assertStatus(403);
        $this->assertTrue($super->fresh()->permissions()->exists());
    }

    public function test_the_list_tells_the_editor_which_roles_it_may_change(): void
    {
        ['headers' => $h, 'role' => $own] = $this->adminWith('view-roles', 'edit-roles');
        $other = Role::findOrCreate('other-'.uniqid(), 'admin');

        $roles = collect($this->getJson(self::BASE.'/admin/roles', $h)->assertOk()->json('result.roles'))->keyBy('id');

        $this->assertFalse($roles[$own->id]['can_manage']);
        $this->assertTrue($roles[$other->id]['can_manage']);
        $this->assertFalse($roles[Role::query()->where('name', 'superAdmin')->value('id')]['can_manage']);
    }

    public function test_a_permission_change_applies_on_the_very_next_request(): void
    {
        ['headers' => $editor] = $this->superAdmin();
        ['headers' => $subject, 'role' => $role] = $this->adminWith('view-categories');

        $this->postJson(self::BASE.'/categories', [], $subject)->assertStatus(403);

        $this->putJson(self::BASE."/admin/roles/{$role->id}", [
            'permissions' => ['view-categories', 'create-categories'],
        ], $editor)->assertOk();

        $this->postJson(self::BASE.'/categories', [], $subject)->assertStatus(422);
    }

    public function test_a_role_change_is_audited(): void
    {
        ['headers' => $h] = $this->superAdmin();
        $role = Role::findOrCreate('audited-'.uniqid(), 'admin');

        $this->putJson(self::BASE."/admin/roles/{$role->id}", ['permissions' => ['view-reports']], $h)->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action'     => 'permissions_changed',
            'model_type' => Role::class,
            'model_id'   => $role->id,
        ]);
    }

    public function test_legacy_role_endpoints_follow_the_same_rules(): void
    {
        ['headers' => $h] = $this->adminWith('view-roles', 'create-roles', 'delete-roles');
        $super = Role::query()->where('name', 'superAdmin')->first();
        Permission::findOrCreate('users-index', 'admin'); // a legacy Blade-era name

        $this->postJson(self::BASE.'/roles', ['name' => 'legacy-esc', 'permissions' => ['delete-users']], $h)->assertStatus(403);
        $this->postJson(self::BASE.'/roles', ['name' => 'legacy-blade', 'permissions' => ['users-index']], $h)->assertStatus(403);
        $this->deleteJson(self::BASE."/roles/{$super->id}", [], $h)->assertStatus(403);
        $this->assertNotNull($super->fresh());
    }

    // ------------------------------------------------------------- accounts

    public function test_assigning_a_role_needs_every_permission_it_carries(): void
    {
        $strong = Role::findOrCreate('strong-'.uniqid(), 'admin');
        $strong->givePermissionTo(['view-controllers', 'delete-controllers', 'view-roles']);
        ['headers' => $h] = $this->adminWith('view-controllers', 'create-controllers');

        $payload = fn (string $role) => [
            'name' => 'New', 'email' => uniqid().'@x.test', 'password' => 'secret-pass-1',
            'password_confirmation' => 'secret-pass-1', 'role' => $role,
        ];

        $this->postJson(self::BASE.'/admin/controllers', $payload($strong->name), $h)->assertStatus(403);
        $this->postJson(self::BASE.'/admin/controllers', $payload('superAdmin'), $h)->assertStatus(403);
    }

    public function test_a_deactivated_admin_cannot_sign_in_and_loses_live_tokens(): void
    {
        ['model' => $admin, 'headers' => $h] = $this->adminWith('view-dashboard');
        $admin->forceFill(['password' => bcrypt('pass-1234!'), 'status' => 'deactivated'])->save();

        $this->getJson(self::BASE.'/auth/admin/me', $h)->assertStatus(401);
        $this->assertSame(0, $admin->tokens()->count());

        $this->postJson(self::BASE.'/auth/admin/login', ['email' => $admin->email, 'password' => 'pass-1234!'])
            ->assertStatus(403);
    }
}
