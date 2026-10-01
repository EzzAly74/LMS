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
        // HR accepts the machine code + national id (D-075).
        $user = User::factory()->create(['machine_code' => '5501', 'system_id' => 5501]);
        $this->fakeHr(null, $user);

        $response = $this->postJson(self::BASE . '/auth/user/login', [
            'email'    => '5501',
            'password' => '29001011234567',
        ]);

        $this->assertSuccess($response);
        $response->assertJsonStructure(['result' => ['token', 'user']]);
    }

    /**
     * Stand-in for the HR system (B-145): tests never call the real one.
     * `$error` is what HRSystemService reports: 'invalid' (HR rejected the
     * credential) or 'unreachable'.
     */
    private function fakeHr(?string $error, ?User $accepts = null): object
    {
        $fake = new class($error, $accepts) extends HRSystemService {
            /** @var list<string> */
            public array $calls = [];

            public function __construct(private readonly ?string $error, private readonly ?User $accepts)
            {
                parent::__construct();
            }

            public function getAccessToken($email, $password, $getUserDetails = false, string $endpoint = 'Auth/login')
            {
                $this->calls[]     = (string) $email;
                $this->lastError   = $this->error;
                $this->lastMessage = $this->error === 'invalid' ? 'Invalid password' : null;

                if ($this->error === null && $this->accepts !== null) {
                    return (object) ['employee' => (object) [
                        'employeeId'     => $this->accepts->system_id,
                        'name'           => $this->accepts->name,
                        'email'          => $this->accepts->email,
                        'machineCode'    => $this->accepts->machine_code,
                        'departmentName' => $this->accepts->department_name,
                        'nationalId'     => (string) $password,
                    ]];
                }

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

    public function test_a_local_password_no_longer_signs_a_learner_in(): void
    {
        // D-075: HR is the only authority; a password set in the Academy is ignored.
        $user = User::factory()->create(['password' => bcrypt('secret123')]);
        $hr   = $this->fakeHr('invalid');

        $this->postJson(self::BASE . '/auth/user/login', ['email' => $user->email, 'password' => 'secret123'])
            ->assertStatus(401);
        $this->assertSame([$user->email], $hr->calls);
    }

    public function test_hr_decides_even_when_the_mirrored_national_id_matches(): void
    {
        $user = User::factory()->create(['machine_code' => '5502', 'national_id' => '29001011234500']);
        $this->fakeHr('invalid');

        $this->postJson(self::BASE . '/auth/user/login', ['email' => '5502', 'password' => '29001011234500'])
            ->assertStatus(401);
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    public function test_the_mirrored_national_id_stands_in_only_while_hr_is_unreachable(): void
    {
        User::factory()->create(['machine_code' => '5503', 'national_id' => '29001011234511']);
        $this->fakeHr('unreachable');

        $this->postJson(self::BASE . '/auth/user/login', ['email' => '5503', 'password' => '29001011234511'])
            ->assertOk()->assertJsonStructure(['result' => ['token', 'user']]);
    }

    public function test_a_deactivated_learner_is_refused_even_when_hr_accepts(): void
    {
        $user = User::factory()->create(['machine_code' => '5504', 'system_id' => 5504, 'status' => 'deactivated']);
        $this->fakeHr(null, $user);

        $this->postJson(self::BASE . '/auth/user/login', ['email' => '5504', 'password' => '29001011234522'])
            ->assertStatus(403);
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    public function test_learner_tokens_last_seven_days(): void
    {
        $user = User::factory()->create(['machine_code' => '5505', 'system_id' => 5505]);
        $this->fakeHr(null, $user);

        $this->postJson(self::BASE . '/auth/user/login', ['email' => '5505', 'password' => '29001011234533'])->assertOk();

        $expires = \Illuminate\Support\Carbon::parse(DB::table('personal_access_tokens')->value('expires_at'));
        $this->assertTrue($expires->between(now()->addDays(6), now()->addDays(7)->addMinute()));
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
