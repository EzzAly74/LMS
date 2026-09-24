<?php

use App\Http\Middleware\AdminLogMiddleware;
use App\Http\Middleware\ApiProtectMiddleware;
use App\Http\Middleware\AuthenticationMiddleware;
use App\Http\Middleware\OptionalAuthenticationMiddleware;
use App\Http\Middleware\ResolveMobileEmployeeMiddleware;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SwitchLanguageMiddleware;
use App\Http\Middleware\TrustApiMiddleware;
use App\Http\Middleware\VerifyMobileSharedTokenMiddleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Web routes — Swagger URL aliases and the public storage
            // fallback only. This project is API-only (Q-005).
            Route::middleware('web')
                ->group(base_path('routes/web.php'));

            // The Blade user-auth and admin-panel route files were removed in
            // Phase 4 / Stage A: the Blade surface is not live and carried
            // B-04 (Critical), B-07, B-11, B-18 and B-29. See 05-plan.md §3
            // and 04-decisions.md D-041.

            // API routes — versioned at /api/v1
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));
        },
    )
    // Broadcasting channel auth — registered separately (rather than via
    // withRouting's `channels:` param) so it sits behind our own bearer-token
    // `auth.user` middleware instead of the framework's default `web`
    // session guard, matching how every other API route authenticates.
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api', 'middleware' => ['api', 'auth.user']],
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Append custom middleware to the web group
        $middleware->web(append: [
            SwitchLanguageMiddleware::class,
        ]);

        // Append custom middleware to the api group.
        //
        // B-06 (High): `RateLimiter::for('api', 60/min)` has been defined in
        // AppServiceProvider all along, but Laravel 11 no longer adds
        // `throttle:api` to the api group by default and nothing here applied
        // it — so the limiter was dead code and all 334 api/* routes were
        // unthrottled. Only the two logins and POST contact carried an
        // explicit throttle.
        //
        // 60/min is the limiter's existing definition and is keyed per
        // authenticated user (falling back to IP). Dashboard list screens fire
        // several requests per navigation, so this is set deliberately at the
        // group level rather than lower; expensive report and export routes
        // should get their own tighter limiter as they are built (plan B4).
        $middleware->api(append: [
            SetLocale::class,
            'throttle:api',
        ]);

        // Guests hitting protected routes are redirected to the appropriate
        // login page. There is no `login` named route in this project; admin
        // routes use `admin.login_page` and the user area uses `front.auth.login`.
        // API-only project (Q-005): there is no login page to redirect a guest
        // to, so always return null and let the framework raise a 401. The
        // previous closure pointed at `admin.login_page` / `front.auth.login`,
        // which were removed with the Blade surface in Phase 4 / Stage A.
        $middleware->redirectGuestsTo(fn (Request $request) => null);

        // Named middleware aliases
        $middleware->alias([
            // API authentication — validates Sanctum bearer token
            'auth.user'          => AuthenticationMiddleware::class,
            // API optional authentication — resolves the user when a valid
            // token is present, but never rejects guests (public browse).
            'auth.user.optional' => OptionalAuthenticationMiddleware::class,
            // API role check — role:Admin | role:User | role:Admin,User
            'role'               => RoleMiddleware::class,
            // Spatie permission package middlewares (Laravel 11 no longer
            // auto-registers these; the admin panel relies on `permission:*`)
            'permission'         => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            // Legacy / Blade middleware
            'admin.logs'         => AdminLogMiddleware::class,
            'api-protect'        => ApiProtectMiddleware::class,
            'switch-language'    => SwitchLanguageMiddleware::class,
            'language'           => SetLocale::class,
            'trust'              => TrustApiMiddleware::class,
            // 📱 Mobile API (S2S, HR integration) — shared bearer token
            // gate + employee resolver. Used in pairs:
            // ->middleware(['mobile.token', 'mobile.employee'])
            'mobile.token'       => VerifyMobileSharedTokenMiddleware::class,
            'mobile.employee'    => ResolveMobileEmployeeMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // JSON error responses for all API routes — format matches ApiResponse trait
        $exceptions->render(function (AuthenticationException $_e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('messages.unauthenticated'),
                    'errors'  => [],
                ], 401);
            }
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('messages.validation_failed'),
                    'errors'  => $e->errors(),
                ], 422);
            }
        });

        $exceptions->render(function (NotFoundHttpException $_e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('messages.not_found'),
                    'errors'  => [],
                ], 404);
            }
        });

        // Catch-all API error renderer.
        //
        // B-05 (High): this used to return `$e->getMessage()` verbatim for any
        // throwable, at the exception's own status code, ignoring APP_DEBUG.
        // For a 500 that publishes internal detail to unauthenticated callers —
        // SQL fragments and bound values from a QueryException, absolute file
        // paths, class and vendor names.
        //
        // The rule now: messages on 4xx HttpExceptions are developer-authored
        // (`abort(403, '...')`) and safe to return. Anything 5xx, or any
        // non-HTTP throwable, gets a generic message unless APP_DEBUG is on —
        // in which case the real message is returned so local debugging is
        // unaffected.
        $exceptions->render(function (\Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $isHttp = $e instanceof HttpExceptionInterface;
            $status = $isHttp ? $e->getStatusCode() : 500;

            $safeToExpose = ($isHttp && $status < 500) || config('app.debug') === true;

            return response()->json([
                'status'  => 'error',
                'message' => $safeToExpose && $e->getMessage() !== ''
                    ? $e->getMessage()
                    : __('messages.server_error'),
                'errors'  => [],
            ], $status);
        });
    })
    ->create();
