<?php

namespace Tests\Feature\Api\Course;

use Tests\Feature\Api\ApiTestCase;

/**
 * B-21 for GET /courses: per_page was unbounded. It is clamped to 1..200
 * rather than rejected, because six Dashboard screens request 200 to fill a
 * course dropdown and must keep working.
 */
class CourseIndexBoundsTest extends ApiTestCase
{
    public function test_per_page_is_clamped_to_200(): void
    {
        ['headers' => $headers] = $this->adminToken();

        $this->getJson(self::BASE.'/courses?per_page=5000', $headers)
            ->assertOk()
            ->assertJsonPath('meta.per_page', 200);
    }

    public function test_the_existing_200_request_is_unchanged(): void
    {
        ['headers' => $headers] = $this->adminToken();

        $this->getJson(self::BASE.'/courses?per_page=200', $headers)
            ->assertOk()
            ->assertJsonPath('meta.per_page', 200);
    }

    public function test_zero_or_negative_per_page_becomes_one(): void
    {
        ['headers' => $headers] = $this->adminToken();

        $this->getJson(self::BASE.'/courses?per_page=0', $headers)
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1);
    }
}
