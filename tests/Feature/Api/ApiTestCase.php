<?php

namespace Tests\Feature\Api;

use App\Models\Admin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

abstract class ApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected const BASE = '/api/v1';

    // -------------------------------------------------------------------------
    // Auth helpers — create model + real Sanctum token (our middleware reads it)
    // -------------------------------------------------------------------------

    /**
     * A fully-privileged admin — the default subject for admin API tests.
     *
     * Since DB-01 was closed, admin routes are gated on `view-*` permissions
     * (AdminPermissionMiddleware). A bare `Admin::factory()` holds no role and
     * would now be refused, so this grants the full `view-*` set — which is
     * what these tests always meant by "an admin", and matches the access the
     * seeded `admin` role has in the real system.
     *
     * Tests that exercise *restricted* admins build their own principal with
     * only the permissions under test (see AdminPermissionEnforcementTest).
     */
    protected function adminToken(?Admin $admin = null): array
    {
        $admin ??= Admin::factory()->create();

        if ($admin->roles()->count() === 0) {
            $admin->assignRole($this->fullAccessAdminRole());
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        }

        $token = $admin->createToken('test')->plainTextToken;

        return ['model' => $admin, 'headers' => ['Authorization' => 'Bearer ' . $token]];
    }

    /** The seeded `admin` role, holding every matrix permission (D-073). */
    private function fullAccessAdminRole(): \Spatie\Permission\Models\Role
    {
        $role = \Spatie\Permission\Models\Role::findOrCreate('admin', 'admin');

        $viewPermissions = \Spatie\Permission\Models\Permission::query()
            ->where('guard_name', 'admin')
            ->whereIn('name', \App\Support\Permissions\AdminSections::permissionNames())
            ->pluck('name');

        foreach ($viewPermissions as $name) {
            if (! $role->hasPermissionTo($name)) {
                $role->givePermissionTo($name);
            }
        }

        return $role;
    }

    protected function userToken(?User $user = null): array
    {
        $user  ??= User::factory()->create();
        $token   = $user->createToken('test')->plainTextToken;

        return ['model' => $user, 'headers' => ['Authorization' => 'Bearer ' . $token]];
    }

    // -------------------------------------------------------------------------
    // Assertion helpers
    // -------------------------------------------------------------------------

    protected function assertSuccess(TestResponse $response, int $status = 200): void
    {
        $response->assertStatus($status)
                 ->assertJsonStructure(['status', 'message'])
                 ->assertJson(['status' => 'success']);
    }

    protected function assertCreated(TestResponse $response): void
    {
        $this->assertSuccess($response, 201);
    }

    protected function assertPaginated(TestResponse $response): void
    {
        $response->assertStatus(200)
                 ->assertJsonStructure(['status', 'message', 'result', 'meta' => [
                     'current_page', 'last_page', 'per_page', 'total',
                 ]])
                 ->assertJson(['status' => 'success']);
    }

    protected function assertUnauthorized(TestResponse $response): void
    {
        $response->assertStatus(401)->assertJson(['status' => 'error']);
    }

    protected function assertForbidden(TestResponse $response): void
    {
        $response->assertStatus(403)->assertJson(['status' => 'error']);
    }

    protected function assertNotFound(TestResponse $response): void
    {
        $response->assertStatus(404);
    }

    protected function assertValidationError(TestResponse $response): void
    {
        $response->assertStatus(422);
    }
}
