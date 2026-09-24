<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Route;

/**
 * Regression tests for B-05 and B-06 (both High).
 */
class ApiHardeningTest extends ApiTestCase
{
    // ---------------------------------------------------------------------
    // B-05 — the catch-all renderer leaked internal exception messages
    // ---------------------------------------------------------------------

    private function registerThrowingRoute(string $uri, \Throwable $e): void
    {
        Route::middleware('api')->prefix('api')->get($uri, function () use ($e) {
            throw $e;
        });
    }

    public function test_internal_exception_message_is_not_leaked_when_debug_is_off(): void
    {
        config(['app.debug' => false]);
        $this->registerThrowingRoute(
            'b05-internal',
            new \RuntimeException('SQLSTATE[42S02]: Base table or view not found: secret_table'),
        );

        $response = $this->getJson('/api/b05-internal');

        $response->assertStatus(500);
        $this->assertStringNotContainsString('secret_table', $response->getContent());
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertSame(__('messages.server_error'), $response->json('message'));
    }

    public function test_internal_exception_message_is_shown_when_debug_is_on(): void
    {
        config(['app.debug' => true]);
        $this->registerThrowingRoute('b05-debug', new \RuntimeException('detailed developer message'));

        $this->getJson('/api/b05-debug')
            ->assertStatus(500)
            ->assertJsonPath('message', 'detailed developer message');
    }

    public function test_developer_authored_4xx_messages_are_still_returned(): void
    {
        config(['app.debug' => false]);
        $this->registerThrowingRoute(
            'b05-forbidden',
            new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('You may not edit this cohort.'),
        );

        $this->getJson('/api/b05-forbidden')
            ->assertStatus(403)
            ->assertJsonPath('message', 'You may not edit this cohort.');
    }

    public function test_server_side_http_exceptions_do_not_leak_their_message(): void
    {
        config(['app.debug' => false]);
        $this->registerThrowingRoute(
            'b05-unavailable',
            new \Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException(null, 'upstream HR host 10.0.0.5 refused'),
        );

        $response = $this->getJson('/api/b05-unavailable');

        $response->assertStatus(503);
        $this->assertStringNotContainsString('10.0.0.5', $response->getContent());
    }

    // ---------------------------------------------------------------------
    // B-06 — the `api` limiter was defined but never applied
    // ---------------------------------------------------------------------

    public function test_api_group_applies_the_rate_limiter(): void
    {
        $middleware = app(\Illuminate\Foundation\Http\Kernel::class)->getMiddlewareGroups()['api'] ?? [];

        $this->assertContains(
            'throttle:api',
            $middleware,
            'The api middleware group must apply throttle:api — RateLimiter::for(\'api\') is otherwise dead code (B-06).',
        );
    }

    public function test_public_api_endpoint_is_throttled_after_the_limit(): void
    {
        // The `api` limiter is 60/min keyed on user id or IP.
        $lastStatus = 200;

        for ($i = 0; $i < 65; $i++) {
            $lastStatus = $this->getJson(self::BASE.'/enums')->getStatusCode();
            if ($lastStatus === 429) {
                break;
            }
        }

        $this->assertSame(429, $lastStatus, 'Expected the api limiter to return 429 within 65 requests.');
    }
}
