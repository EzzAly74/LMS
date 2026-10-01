<?php

namespace App\Services;

use App\Http\Traits\TracksLastActive;
use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;

class UserAuthService
{
    use TracksLastActive;

    public function __construct(
        private readonly UserRepositoryInterface $userRepo
    ) {}

    /**
     * Website learner sign-in (D-075): every learner authenticates through HR,
     * machine code as the username and national id as the password, exactly
     * as the production app does. HR is the authority: if HR says no, the
     * answer is no.
     *
     * Only when HR cannot be reached does the locally mirrored national id
     * (written by `sync:employees` and by every successful HR login) stand in,
     * so an HR outage does not lock every employee out (Q-013 keeps that
     * fallback under review). There is no local learner password any more:
     * the Dashboard no longer creates website learners, and LIVE had none.
     *
     * A learner deactivated in the Academy is refused even when HR accepts.
     */
    public function login(string $identifier, string $password): ?array
    {
        // From the container (same no-argument instance) so tests can fake HR.
        // Machine code is handed to HR verbatim: it must never silently become
        // an email (blank, stale or shared emails would break those learners).
        $hrService = app(HRSystemService::class);
        $result    = $hrService->getAccessToken(
            trim($identifier),
            $password,
            true,
            HRSystemService::LEARNER_LOGIN_ENDPOINT
        );

        if ($hrService->lastError === 'unreachable') {
            $localUser  = $this->resolveLocalUser($identifier);
            $nationalId = trim((string) ($localUser?->getAttribute('national_id') ?? ''));

            if ($localUser && $nationalId !== '' && hash_equals($nationalId, trim($password))) {
                Log::warning('HR unreachable: learner signed in with the mirrored national id', [
                    'user_id' => $localUser->id,
                ]);
                $this->assertActive($localUser);

                return $this->issueToken($localUser, $identifier);
            }

            abort(response()->json([
                'status'  => 'error',
                'message' => 'HR service is currently unreachable. Please try again later.',
            ], 503));
        }

        if (!$result || !isset($result->employee)) {
            // HR rejected the credential. Record its own wording — it separates
            // "unknown employee" from "wrong password", which is otherwise
            // indistinguishable because HR answers both with HTTP 200. The
            // machine code is the username, never the secret; the password and
            // any national id are never written here.
            Log::info('HR learner login rejected', [
                'machine_code' => trim($identifier),
                'hr_message'   => $hrService->lastMessage,
            ]);
            return null;
        }

        $employee = $result->employee;

        $user = $this->userRepo->updateOrCreateBySystemId($employee->employeeId, [
            'name'            => $employee->name,
            'email'           => $employee->email,
            'phone'           => $employee->phone           ?? null,
            'machine_code'    => $employee->machineCode,
            // Captured here as well as in `sync:employees` so an employee who
            // reaches us through HR before the nightly sync still gets the
            // national-id fallback on their next login.
            'national_id'     => ($employee->nationalId ?? null) ?: null,
            'department_name' => $employee->departmentName,
        ]);

        $this->assertActive($user);

        return $this->issueToken($user, $identifier);
    }

    /** A learner deactivated in the Academy may not sign in (D-075). */
    private function assertActive(User $user): void
    {
        if (in_array(strtolower((string) ($user->status ?? 'active')), ['inactive', 'deactivated'], true)) {
            abort(403, __('messages.account_inactive'));
        }
    }

    /**
     * Mint the learner API token and stamp activity.
     *
     * Shared by the HR route and the HR-outage fallback, so a token is never
     * issued on different terms depending on which one matched.
     *
     * @return array{token: string, user: User}
     */
    private function issueToken(User $user, string $identifier): array
    {
        $token = $user->createToken(
            'user-api-token',
            ['role:user'],
            Carbon::now()->addDays(7)
        )->plainTextToken;

        // Register activity on every successful login — across all tables
        // sharing this email so an instructor row is stamped too.
        $this->stampLastActive($user, force: true);
        $this->stampLastActiveByEmail($user->email ?? $identifier);

        return ['token' => $token, 'user' => $this->userRepo->findWithRoles($user->id)];
    }

    /**
     * Resolve a login identifier (machine code, email, or system id) to a local
     * user, for the HR-outage national-id fallback.
     *
     * Resolution is strictly ordered rather than one OR'd query. `machine_code`
     * and `system_id` share a numeric namespace — 61 rows currently hold a
     * `system_id` equal to some other row's `machine_code` — so an OR'd match
     * let the database pick the row, and a typed machine code could resolve to
     * a stranger's account. Machine code wins first because it is the
     * identifier learners are told to type.
     */
    private function resolveLocalUser(string $identifier): ?User
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        $byMachineCode = User::query()->where('machine_code', $identifier)->first();
        if ($byMachineCode) {
            return $byMachineCode;
        }

        $byEmail = User::query()->where('email', $identifier)->first();
        if ($byEmail) {
            return $byEmail;
        }

        if (ctype_digit($identifier)) {
            return User::query()->where('system_id', (int) $identifier)->first();
        }

        return null;
    }

    public function getWithRoles(User $user): User
    {
        return $this->userRepo->findWithRoles($user->id);
    }

    /**
     * Revoke exactly the token this request authenticated with.
     *
     * Sanctum hands the client "{id}|{plainTextToken}" but stores only
     * sha256($plainTextToken). Hashing the whole bearer string — as this did
     * before — can never match a stored hash, so the delete affected 0 rows and
     * the token stayed valid after logout. findToken() is Sanctum's own parser
     * and understands both the prefixed and bare forms.
     */
    public function logout(User $user, ?string $rawToken): void
    {
        if (!$rawToken) {
            return;
        }

        $accessToken = PersonalAccessToken::findToken($rawToken);

        // Scope the delete to the caller: a token id alone must never
        // let one account revoke another account's session.
        if ($accessToken
            && (int) $accessToken->tokenable_id === (int) $user->getKey()
            && $accessToken->tokenable_type === $user->getMorphClass()) {
            $accessToken->delete();
        }
    }

    public function logoutAll(User $user): void
    {
        $user->tokens()->delete();
    }
}
