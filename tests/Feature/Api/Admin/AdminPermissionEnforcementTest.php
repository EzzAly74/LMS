<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Admin;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Api\ApiTestCase;

/**
 * Regression tests for DB-01 (Critical) — server-side admin permissions.
 *
 * The `view-*` matrix was enforced only in the Angular guard and sidebar.
 * RoleMiddleware checks nothing beyond `$user instanceof Admin`, so any admin
 * could call every admin endpoint directly: create roles and admins, change
 * platform settings, read the audit log.
 *
 * Phase 2 counted 154 routes carrying Spatie's PermissionMiddleware, but all of
 * them were Blade routes — removing the Blade surface in Stage A dropped that
 * count to zero, confirming the API never had a permission check at all.
 *
 * These tests cover the escalation paths DB-01 names. The remaining ~255
 * role:Admin routes are still ungated pending the role matrix decision; that is
 * recorded in the plan rather than silently assumed.
 */
class AdminPermissionEnforcementTest extends ApiTestCase
{
    private function adminWithPermissions(array $viewKeys): array
    {
        $role = Role::findOrCreate('restricted-'.uniqid(), 'admin');

        foreach ($viewKeys as $key) {
            $role->givePermissionTo(Permission::findOrCreate($key, 'admin'));
        }

        $admin = Admin::factory()->create();
        $admin->assignRole($role);

        // Spatie caches the whole permission set on first access; permissions
        // created inside a test would otherwise be invisible to the middleware.
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return [
            'model'   => $admin,
            'headers' => ['Authorization' => 'Bearer '.$admin->createToken('test')->plainTextToken],
        ];
    }

    /** Route => the view-* permission that now gates it. */
    public static function gatedRoutes(): array
    {
        return [
            'roles list'      => ['GET', '/roles', 'view-roles'],
            'admins list'     => ['GET', '/admins', 'view-controllers'],
            'admin settings'  => ['GET', '/admin/settings', 'view-platform-config'],
            'audit log'       => ['GET', '/admin/audit-log', 'view-audit-log'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('gatedRoutes')]
    public function test_an_admin_without_the_permission_is_refused(string $method, string $path, string $permission): void
    {
        // Holds a permission, just not the one this route needs.
        ['headers' => $headers] = $this->adminWithPermissions(['view-dashboard']);

        $this->json($method, self::BASE.$path, [], $headers)
            ->assertStatus(403, "Expected 403 on $method $path without $permission");
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('gatedRoutes')]
    public function test_an_admin_with_the_permission_is_allowed(string $method, string $path, string $permission): void
    {
        ['headers' => $headers] = $this->adminWithPermissions([$permission]);

        $status = $this->json($method, self::BASE.$path, [], $headers)->getStatusCode();

        $this->assertNotSame(403, $status, "Admin holding $permission was refused on $method $path");
    }

    public function test_a_learner_still_cannot_reach_admin_routes(): void
    {
        ['headers' => $headers] = $this->userToken();

        foreach (self::gatedRoutes() as [$method, $path, $_perm]) {
            $this->json($method, self::BASE.$path, [], $headers)->assertStatus(403);
        }
    }

    public function test_guests_are_unauthenticated_not_forbidden(): void
    {
        foreach (self::gatedRoutes() as [$method, $path, $_perm]) {
            $this->json($method, self::BASE.$path)->assertStatus(401);
        }
    }

    public function test_role_creation_requires_view_roles(): void
    {
        // The specific escalation DB-01 calls out: an admin without view-roles
        // must not be able to mint a new role for themselves.
        ['headers' => $headers] = $this->adminWithPermissions(['view-dashboard']);

        $this->postJson(self::BASE.'/roles', [
            'name'        => 'escalated',
            'permissions' => ['view-roles'],
        ], $headers)->assertStatus(403);

        $this->assertDatabaseMissing('roles', ['name' => 'escalated']);
    }

    public function test_seeded_roles_keep_working_access(): void
    {
        // The migration grants every view-* to superAdmin and admin so that
        // enforcing the middleware cannot lock anyone out of a working system.
        foreach (['superAdmin', 'admin'] as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', 'admin')->first();
            $this->assertNotNull($role, "Expected the $roleName role to exist");

            $admin = Admin::factory()->create();
            $admin->assignRole($role);

            $this->assertTrue(
                $admin->can('view-roles'),
                "$roleName should retain view-roles so existing access is preserved",
            );
        }
    }
}
