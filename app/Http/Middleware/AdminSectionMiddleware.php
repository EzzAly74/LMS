<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use App\Services\Admin\CourseScope;
use App\Support\Permissions\AdminSections;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-action admin authorization (D-073): `section:courses` lets a request
 * through only when the admin holds `{action}-courses`.
 *
 * The action comes from the route's declared ability (`->ability('edit')`,
 * a Route macro registered in AppServiceProvider) or, when none is declared,
 * from the HTTP method: GET/HEAD view, POST create, PUT/PATCH edit, DELETE
 * delete. AdminRouteGatingTest requires every POST to declare its ability,
 * because a POST is as often an edit (approve, grade, upload, sync) as a create.
 *
 * Deny by default: an unknown section, an action the section does not have,
 * or a principal that is not an Admin is refused. Super admins pass
 * (Admin::canInSection). Like AdminPermissionMiddleware this reads
 * `$request->user()`, which AuthenticationMiddleware populates.
 */
class AdminSectionMiddleware
{
    private const METHOD_ACTIONS = [
        'GET'    => AdminSections::VIEW,
        'HEAD'   => AdminSections::VIEW,
        'POST'   => AdminSections::CREATE,
        'PUT'    => AdminSections::EDIT,
        'PATCH'  => AdminSections::EDIT,
        'DELETE' => AdminSections::DELETE,
    ];

    public function handle(Request $request, Closure $next, string $section): Response
    {
        $principal = $request->user();

        if ($principal === null) {
            return response()->json(['status' => 'error', 'message' => __('messages.unauthenticated')], 401);
        }

        $action = self::actionFor($request->route(), $request->getMethod());

        if (! $principal instanceof Admin
            || $action === null
            || ! AdminSections::has($section)
            || ! $principal->canInSection($section, $action)) {
            return response()->json(['status' => 'error', 'message' => __('messages.forbidden')], 403);
        }

        // Course scope (D-074): an account limited to its own courses cannot
        // use organisation-wide sections, and gets a 404 for any course, or
        // record of one, that is not assigned to it.
        $scope = app(CourseScope::class);
        if ($scope->isScoped($principal) && ! AdminSections::availableToScoped($section)) {
            return response()->json(['status' => 'error', 'message' => __('messages.section_org_wide')], 403);
        }
        $scope->assertRoute($request);

        return $next($request);
    }

    /** The action a route needs: its declared ability, else its method's. */
    public static function actionFor(?Route $route, string $method): ?string
    {
        $declared = $route?->getAction('ability');

        if (is_string($declared) && $declared !== '') {
            return $declared;
        }

        return self::METHOD_ACTIONS[strtoupper($method)] ?? null;
    }
}
