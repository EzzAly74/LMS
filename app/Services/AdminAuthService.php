<?php

namespace App\Services;

use App\Http\Traits\TracksLastActive;
use App\Models\Admin;
use App\Repositories\Contracts\AdminRepositoryInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class AdminAuthService
{
    use TracksLastActive;

    public function __construct(
        private readonly AdminRepositoryInterface $repo
    ) {}

    public function login(string $email, string $password): ?array
    {
        $admin = $this->repo->findByEmail($email);

        if (!$admin || !Hash::check($password, $admin->password)) {
            return null;
        }

        // Correct password, deactivated account: refused, with a message that
        // says so rather than "invalid credentials" (D-073).
        if (! $admin->isActive()) {
            abort(403, __('messages.account_inactive'));
        }

        // Dashboard tokens last 12 h (D-007), not 30 days.

        $token = $admin->createToken(
            'admin-api-token',
            ['role:admin'],
            Carbon::now()->addHours(12)
        )->plainTextToken;

        // Register activity on every successful login — across all tables
        // sharing this email (admin / instructor / user) for this person.
        $this->stampLastActive($admin, force: true);
        $this->stampLastActiveByEmail($admin->email ?? $email);

        return ['token' => $token, 'admin' => $this->repo->findWithRoles($admin->id)];
    }

    public function getWithRoles(Admin $admin): Admin
    {
        return $this->repo->findWithRoles($admin->id);
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
    public function logout(Admin $admin, ?string $rawToken): void
    {
        if (!$rawToken) {
            return;
        }

        $accessToken = PersonalAccessToken::findToken($rawToken);

        // Scope the delete to the caller: a token id alone must never
        // let one account revoke another account's session.
        if ($accessToken
            && (int) $accessToken->tokenable_id === (int) $admin->getKey()
            && $accessToken->tokenable_type === $admin->getMorphClass()) {
            $accessToken->delete();
        }
    }

    public function logoutAll(Admin $admin): void
    {
        $admin->tokens()->delete();
    }
}
