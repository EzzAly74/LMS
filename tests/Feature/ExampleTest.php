<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The application boots and serves its health endpoint.
     *
     * This used to assert `GET /` returned 200, which was the Blade
     * front-end home page. That surface was removed in Phase 4 / Stage A
     * (the project is API-only — Q-005), so `/` correctly 404s now and the
     * smoke test points at the framework health route instead.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $this->get('/up')->assertStatus(200);
    }

    /**
     * The removed Blade surface stays removed.
     *
     * Regression guard for B-04 (Critical — arbitrary model/column write via
     * `admin/quickChange`), B-07 (unauthenticated employee enumeration),
     * B-11 (`/test/hr` exposing the HR roster) and B-18 (unthrottled Blade
     * logins). Each is closed by deletion rather than by a fix, so the test
     * that matters is that none of these paths is routable.
     */
    public function test_removed_blade_routes_are_not_registered(): void
    {
        $gone = [
            ['POST', '/admin/quickChange'],          // B-04
            ['POST', '/admin/deleteSelectedItems'],  // B-04
            ['POST', '/admin/login'],                // B-18
            ['GET', '/2b/attendance/getUser'],       // B-07
            ['GET', '/test/hr'],                     // B-11
            ['POST', '/user/login'],                 // B-18
            ['GET', '/'],                            // Blade home
        ];

        foreach ($gone as [$method, $uri]) {
            $this->call($method, $uri)->assertNotFound();
        }
    }
}
