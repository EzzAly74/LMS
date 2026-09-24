<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        // Reset rate-limiter buckets between tests.
        //
        // Applying `throttle:api` to the api group (B-06) made the suite fail
        // in bulk: every test hits 127.0.0.1, unauthenticated requests key the
        // limiter on IP, and the `array` cache driver persists for the whole
        // PHPUnit process — so the 60/min bucket was shared across all ~190
        // tests and drained after the first 60 requests.
        //
        // Flushing here isolates each test's bucket. It deliberately does NOT
        // disable throttling: ApiHardeningTest still drives a real 429, and the
        // login limiter is still exercised, so the protection stays under test
        // rather than being switched off to make the suite green.
        Cache::flush();
    }
}
