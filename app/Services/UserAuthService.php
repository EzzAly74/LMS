<?php

namespace App\Services;

use App\Http\Traits\TracksLastActive;
use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;

class UserAuthService
{
    use TracksLastActive;

    public function __construct(
        private readonly UserRepositoryInterface $userRepo
    ) {}

    public function login(string $identifier, string $password): ?array
    {
        // Learners sign in with their machine code; the request field is named
        // "email" only because the frontend and the production app both inherit
        // that name (LIVE's own form labels it "الكود الوظيفي" — job code — and
        // types it as text, not email). An email or system id is accepted too,
        // for admin-managed accounts.
        //
        // The local lookup exists ONLY to support the dashboard-password path
        // below; the HR call never uses it as an identifier.
        $localUser = $this->resolveLocalUser($identifier);

        // Dashboard-password path: TEST-only addition (LIVE has no local
        // learner password — 0 of its 1220 users have one). Kept because the
        // admin dashboard can set one, and it is purely additive: it cannot
        // block or alter the machine-code + national-id route below.
        $localHash = $localUser?->getAttribute('password');
        if ($localUser && !empty($localHash) && Hash::check($password, $localHash)) {
            return $this->issueToken($localUser, $identifier);
        }

        // National-id path — the standing fallback for every learner.
        //
        // HR already treats the national id as the learner's password. The
        // HR call below now uses `Auth/Mobilelogin`, which does not gate on
        // the control-panel permission that made `Auth/login` answer
        // `ليس لديك صلاحية الوصول إلى لوحة التحكم` for ordinary employees —
        // but this local path stays as the standing fallback for when HR is
        // unavailable or has not yet seen a change to the employee's record.
        //
        // So accept the same secret locally, compared against the value
        // `sync:employees` mirrors onto each row (and that the HR-success path
        // below stores for brand-new employees). Nothing is hardcoded: the
        // comparison is always against that user's own synced national id, so
        // a newly synced employee works on first login with no extra setup.
        // A row with no national id is unaffected and falls through to HR.
        $nationalId = trim((string) ($localUser?->getAttribute('national_id') ?? ''));
        if ($localUser && $nationalId !== '' && hash_equals($nationalId, trim($password))) {
            return $this->issueToken($localUser, $identifier);
        }

        // HR is the authority for learner credentials — machine code as the
        // username, national id as the password. The production app
        // (AuthControllers\LoginController::postLogin) hands HR the value the
        // learner typed, verbatim, and nothing else. Do the same here.
        //
        // Deliberately NOT substituting the locally-stored email: HR accepts a
        // machine code directly, and swapping in an email breaks every learner
        // whose synced row has a blank email, a stale one, or one shared with a
        // second row. Machine code must never silently become email.
        $hrService = new HRSystemService();
        $result    = $hrService->getAccessToken(
            trim($identifier),
            $password,
            true,
            HRSystemService::LEARNER_LOGIN_ENDPOINT
        );

        if ($hrService->lastError === 'unreachable') {
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
                'local_user'   => $localUser?->id,
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

        return $this->issueToken($user, $identifier);
    }

    /**
     * Mint the learner API token and stamp activity.
     *
     * Shared by all three authentication routes (dashboard password,
     * national id, HR) so a token is never issued on slightly different
     * terms depending on which one matched.
     *
     * @return array{token: string, user: User}
     */
    private function issueToken(User $user, string $identifier): array
    {
        $token = $user->createToken(
            'user-api-token',
            ['role:user'],
            Carbon::now()->addDays(30)
        )->plainTextToken;

        // Register activity on every successful login — across all tables
        // sharing this email so an instructor row is stamped too.
        $this->stampLastActive($user, force: true);
        $this->stampLastActiveByEmail($user->email ?? $identifier);

        return ['token' => $token, 'user' => $this->userRepo->findWithRoles($user->id)];
    }

    /**
     * Resolve a login identifier (machine code, email, or system id) to a local
     * user, for the dashboard-password and national-id paths.
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
