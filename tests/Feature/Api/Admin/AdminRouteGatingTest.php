<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Admin;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Api\ApiTestCase;

/**
 * Stage A / A3 — every admin route is gated on a `view-*` permission.
 *
 * DB-01 (Critical) had two halves. The mechanism half was closed earlier:
 * AdminPermissionMiddleware exists and the 17 `view-*` keys the Dashboard uses
 * were seeded. The coverage half is this: of the 215 routes behind
 * `role:Admin`, only 41 actually named a permission. The other 174 were
 * reachable by any principal holding the Admin role, whatever their own role
 * granted — so an admin restricted to, say, the inbox could still read the
 * audit log, edit courses, or delete instructors by calling the API directly.
 *
 * The first test is structural and is the one that matters long-term: it walks
 * the real route table, so a new admin route added without a permission fails
 * here rather than shipping un-gated. The rest prove the gate actually refuses.
 */
class AdminRouteGatingTest extends ApiTestCase
{
    /**
     * Admin routes that are deliberately not permission-gated.
     *
     * Both exemptions are about routes that are not really "admin sections":
     *
     *  - auth/admin/{me,profile,logout}: every authenticated admin needs their
     *    own identity and a way to sign out. Gating logout behind a permission
     *    would let a misconfigured role trap a session open, which makes the
     *    system less safe, not more.
     *
     *  - the submission-file route: its group is role:Admin,User,Instructor. It
     *    is a learner-facing surface that admins and instructors can also
     *    reach, and authorization there is ownership-based (it runs through the
     *    private-disk download authorization added for B-10). An admin
     *    permission key has no meaning on it.
     */
    private const EXEMPT = [
        'api/v1/auth/admin/logout',
        'api/v1/auth/admin/me',
        'api/v1/auth/admin/profile',
        'api/v1/courses/{course}/assignments/{assignment}/submissions/{submission}/file',
    ];

    /** One representative route per permission key introduced by A3. */
    public static function newlyGatedRoutes(): array
    {
        return [
            'attendance'    => ['view-attendance',    self::BASE.'/attendance'],
            'instructors'   => ['view-instructors',   self::BASE.'/instructors'],
            'forms'         => ['view-forms',         self::BASE.'/forms'],
            'notifications' => ['view-notifications', self::BASE.'/notifications'],
            'audit log'     => ['view-audit-log',     self::BASE.'/audit-log'],
            'blogs'         => ['view-resources',     self::BASE.'/admin/blogs'],
            'dashboard'     => ['view-dashboard',     self::BASE.'/dashboard'],
            'reports'       => ['view-reports',       self::BASE.'/progress'],
            // NOT /courses or /categories: both have a public listing route
            // that matches first, so they would prove nothing about the gate.
            'courses'       => ['view-courses',       self::BASE.'/lecture-questions'],
        ];
    }

    /**
     * An admin holding exactly one permission, and nothing else.
     */
    private function adminWith(string ...$permissions): array
    {
        $role = Role::findOrCreate('gating-'.uniqid(), 'admin');

        foreach ($permissions as $name) {
            $role->givePermissionTo(Permission::findOrCreate($name, 'admin'));
        }

        $admin = Admin::factory()->create();
        $admin->assignRole($role);

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken];
    }

    // ------------------------------------------------------------- structural

    /**
     * The regression guard: no admin route may exist without a permission.
     *
     * This walks the actual route table rather than a hand-maintained list, so
     * it keeps working as routes are added.
     */
    public function test_every_admin_route_names_a_permission(): void
    {
        $ungated = [];

        foreach (Route::getRoutes() as $route) {
            // gatherMiddleware() can return Closures as well as strings.
            $middleware = implode(',', array_filter(
                $route->gatherMiddleware(),
                static fn ($m) => is_string($m),
            ));

            if (! str_contains($middleware, 'RoleMiddleware:Admin')) {
                continue;
            }
            if (str_contains($middleware, 'AdminSectionMiddleware:') || str_contains($middleware, 'AdminPermissionMiddleware:')) {
                continue;
            }
            if (in_array($route->uri(), self::EXEMPT, true)) {
                continue;
            }

            $ungated[] = $route->methods()[0].' '.$route->uri();
        }

        $this->assertSame(
            [],
            $ungated,
            "These admin routes are reachable by any admin regardless of their role.\n"
            ."Add 'section:<section>' to the route group, or add the route to\n"
            ."AdminRouteGatingTest::EXEMPT with a written reason.\n\n"
            .implode("\n", $ungated)
        );
    }

    /**
     * The exemptions are exemptions, not oversights: they must still work for
     * an admin who holds no `view-*` permission at all.
     */
    public function test_an_admin_with_no_permissions_can_still_read_their_own_identity_and_log_out(): void
    {
        $headers = $this->adminWith();

        $this->getJson(self::BASE.'/auth/admin/me', $headers)->assertOk();
        $this->postJson(self::BASE.'/auth/admin/logout', [], $headers)->assertOk();
    }

    // ---------------------------------------------------------- the gate bites

    #[\PHPUnit\Framework\Attributes\DataProvider('newlyGatedRoutes')]
    public function test_an_admin_without_the_key_is_refused(string $permission, string $url): void
    {
        // A permission that exists but is not the one this route needs, so the
        // refusal is about the specific key and not about holding none at all.
        $headers = $this->adminWith($permission === 'view-dashboard' ? 'view-inbox' : 'view-dashboard');

        $this->getJson($url, $headers)->assertStatus(403);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('newlyGatedRoutes')]
    public function test_an_admin_holding_the_key_is_allowed_through(string $permission, string $url): void
    {
        $headers = $this->adminWith($permission);

        // Past the gate. What the controller then returns is that endpoint's
        // own business - this asserts only that authorization did not refuse.
        $this->getJson($url, $headers)->assertStatus(200);
    }

    public function test_a_learner_cannot_reach_admin_routes(): void
    {
        ['headers' => $headers] = $this->userToken();

        $this->getJson(self::BASE.'/attendance', $headers)->assertStatus(403);
        $this->getJson(self::BASE.'/instructors', $headers)->assertStatus(403);
        $this->getJson(self::BASE.'/audit-log', $headers)->assertStatus(403);
    }

    public function test_a_guest_is_unauthenticated_on_admin_routes(): void
    {
        $this->getJson(self::BASE.'/attendance')->assertStatus(401);
        $this->getJson(self::BASE.'/instructors')->assertStatus(401);
    }
}
