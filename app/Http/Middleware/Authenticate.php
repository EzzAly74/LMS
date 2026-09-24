<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     *
     * This project is API-only (Q-005). There is no login page to redirect to,
     * so returning null makes the framework raise a 401 instead of attempting a
     * redirect. The previous implementation pointed at `admin.login_page` and
     * `front.auth.login`, both of which were removed with the Blade surface in
     * Phase 4 / Stage A — calling `route()` on them now throws, turning a 401
     * into a 500.
     */
    protected function redirectTo(Request $request): ?string
    {
        return null;
    }
}
