<?php

namespace Tests\Feature\Api\Auth;

use App\Models\Admin;
use App\Models\User;
use App\Services\HRSystemService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\ApiTestCase;

class AuthApiTest extends ApiTestCase
{
    // =========================================================================
    // User Auth
    // =========================================================================

    public function test_user_login_with_valid_credentials_returns_token(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret123')]);

        $response = $this->postJson(self::BASE . '/auth/user/login', [
            'email'    => $user->email,
            'password' => 'secret123',
        ]);

        $this->assertSuccess($response);
        $response->assertJsonStructure(['result' => ['token', 'user']]);
    }

    /**
     * Stand-in for the HR system (B-145): tests never call the real one.
     * `$error` is what HRSystemService reports: 'invalid' (HR rejected the
     * credential) or 'unreachable'.
     */
    private function fakeHr(string $error): object
    {
        $fake = new class($error) extends HRSystemService {
            /** @var list<string> */
            public array $calls = [];

            public function __construct(private readonly string $error)
            {
                parent::__construct();
            }

            public function getAccessToken($email, $password, $getUserDetails = false, string $endpoint = 'Auth/login')
            {
                $this->calls[]     = (string) $email;
                $this->lastError   = $this->error;
                $this->lastMessage = $this->error === 'invalid' ? 'Invalid password' : null;

                return null;
            }
        };
        $this->app->instance(HRSystemService::class, $fake);

        return $fake;
    }

    public function test_user_login_with_wrong_password_returns_401(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct')]);
        $hr   = $this->fakeHr('invalid');

        $response = $this->postJson(self::BASE . '/auth/user/login', [
            'email'    => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401)->assertJson(['status' => 'error'])->assertJsonMissingPath('result.token');
        $this->assertSame([$user->email], $hr->calls, 'Neither local check matched, so HR was asked once.');
        $this->assertSame(0, DB::table('personal_access_tokens')->count(), 'No token for a wrong password.');
    }

    public function test_user_login_answers_503_when_hr_is_unreachable_and_issues_no_token(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct')]);
        $this->fakeHr('unreachable');

        $this->postJson(self::BASE . '/auth/user/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertStatus(503)->assertJson(['status' => 'error']);
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    public function test_user_login_with_the_dashboard_password_does_not_ask_hr(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret123')]);
        $hr   = $this->fakeHr('unreachable');

        $this->postJson(self::BASE . '/auth/user/login', ['email' => $user->email, 'password' => 'secret123'])
            ->assertOk()->assertJsonStructure(['result' => ['token', 'user']]);
        $this->assertSame([], $hr->calls);
    }

    public function test_user_login_validation_fails_without_email(): void
    {
        $response = $this->postJson(self::BASE . '/auth/user/login', [
            'password' => 'secret',
        ]);

        $this->assertValidationError($response);
    }

    public function test_user_me_returns_profile(): void
    {
        ['headers' => $headers, 'model' => $user] = $this->userToken();

        $response = $this->withHeaders($headers)->getJson(self::BASE . '/auth/user/me');

        $this->assertSuccess($response);
        $response->assertJsonPath('result.email', $user->email);
    }

    public function test_user_me_without_token_returns_401(): void
    {
        $response = $this->getJson(self::BASE . '/auth/user/me');
        $this->assertUnauthorized($response);
    }

    public function test_user_cannot_access_admin_me(): void
    {
        ['headers' => $headers] = $this->userToken();

        $response = $this->withHeaders($headers)->getJson(self::BASE . '/auth/admin/me');

        $this->assertForbidden($response);
    }

    public function test_user_logout_invalidates_token(): void
    {
        ['headers' => $headers] = $this->userToken();

        $logout = $this->withHeaders($headers)->postJson(self::BASE . '/auth/user/logout');
        $this->assertSuccess($logout);

        // Same token should now be rejected
        $me = $this->withHeaders($headers)->getJson(self::BASE . '/auth/user/me');
        $this->assertUnauthorized($me);
    }

    public function test_user_logout_all_invalidates_all_tokens(): void
    {
        $user = User::factory()->create();
        $token1 = $user->createToken('device-1')->plainTextToken;
        $token2 = $user->createToken('device-2')->plainTextToken;

        $this->withHeaders(['Authorization' => 'Bearer ' . $token1])
             ->postJson(self::BASE . '/auth/user/logout-all');

        // Both tokens should now be invalid
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token2])
                         ->getJson(self::BASE . '/auth/user/me');
        $this->assertUnauthorized($response);
    }

    // =========================================================================
    // Admin Auth
    // =========================================================================

    public function test_admin_login_with_valid_credentials_returns_token(): void
    {
        $admin = Admin::factory()->create(['password' => bcrypt('admin123')]);

        $response = $this->postJson(self::BASE . '/auth/admin/login', [
            'email'    => $admin->email,
            'password' => 'admin123',
        ]);

        $this->assertSuccess($response);
        $response->assertJsonStructure(['result' => ['token', 'admin']]);
    }

    public function test_admin_login_with_wrong_password_returns_401(): void
    {
        $admin = Admin::factory()->create(['password' => bcrypt('correct')]);

        // Must clear AdminLoginRequest's `min:6` rule, otherwise this asserts
        // validation (422) rather than the rejected-credentials path (401).
        $response = $this->postJson(self::BASE . '/auth/admin/login', [
            'email'    => $admin->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401)->assertJson(['status' => 'error']);
    }

    public function test_admin_me_returns_profile(): void
    {
        ['headers' => $headers, 'model' => $admin] = $this->adminToken();

        $response = $this->withHeaders($headers)->getJson(self::BASE . '/auth/admin/me');

        $this->assertSuccess($response);
        $response->assertJsonPath('result.email', $admin->email);
    }

    public function test_admin_cannot_access_user_me(): void
    {
        ['headers' => $headers] = $this->adminToken();

        $response = $this->withHeaders($headers)->getJson(self::BASE . '/auth/user/me');

        $this->assertForbidden($response);
    }

    public function test_admin_logout_invalidates_token(): void
    {
        ['headers' => $headers] = $this->adminToken();

        $logout = $this->withHeaders($headers)->postJson(self::BASE . '/auth/admin/logout');
        $this->assertSuccess($logout);

        $me = $this->withHeaders($headers)->getJson(self::BASE . '/auth/admin/me');
        $this->assertUnauthorized($me);
    }
}
