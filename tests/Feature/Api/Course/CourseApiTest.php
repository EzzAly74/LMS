<?php

namespace Tests\Feature\Api\Course;

use App\Models\Category;
use App\Models\Course;
use App\Models\Instructor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\ApiTestCase;

class CourseApiTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    /** There is no Instructor factory; create one the way the other suites do. */
    private function instructor(): Instructor
    {
        return Instructor::query()->create([
            'name'  => ['en' => 'Teacher', 'ar' => 'معلم'],
            'email' => 'teacher'.uniqid().'@example.test',
        ]);
    }

    // =========================================================================
    // GET /api/v1/courses  (auth.user — any authenticated)
    // =========================================================================

    public function test_index_returns_paginated_courses_for_admin(): void
    {
        Course::factory()->count(3)->create();
        ['headers' => $headers] = $this->adminToken();

        $response = $this->withHeaders($headers)->getJson(self::BASE . '/courses');

        $this->assertPaginated($response);
    }

    public function test_index_returns_paginated_courses_for_user(): void
    {
        Course::factory()->count(2)->create();
        ['headers' => $headers] = $this->userToken();

        $response = $this->withHeaders($headers)->getJson(self::BASE . '/courses');

        $this->assertPaginated($response);
    }

    public function test_index_requires_authentication(): void
    {
        $response = $this->getJson(self::BASE . '/courses');
        $this->assertUnauthorized($response);
    }

    public function test_index_filters_by_search(): void
    {
        Course::factory()->create([
            'title' => json_encode(['ar' => 'لغة بايثون', 'en' => 'Python Course']),
        ]);
        Course::factory()->create([
            'title' => json_encode(['ar' => 'جافاسكريبت', 'en' => 'JavaScript']),
        ]);
        ['headers' => $headers] = $this->adminToken();

        $response = $this->withHeaders($headers)->getJson(self::BASE . '/courses?search=Python');

        $this->assertPaginated($response);
        $response->assertJsonPath('meta.total', 1);
    }

    // =========================================================================
    // GET /api/v1/courses/{id}
    // =========================================================================

    public function test_show_returns_course(): void
    {
        $course = Course::factory()->create();
        ['headers' => $headers] = $this->adminToken();

        $response = $this->withHeaders($headers)->getJson(self::BASE . '/courses/' . $course->id);

        $this->assertSuccess($response);
        $response->assertJsonPath('result.id', $course->id);
    }

    public function test_show_returns_404_for_unknown_course(): void
    {
        ['headers' => $headers] = $this->adminToken();

        $response = $this->withHeaders($headers)->getJson(self::BASE . '/courses/99999');

        $this->assertNotFound($response);
    }

    // =========================================================================
    // POST /api/v1/courses  (admin only)
    // =========================================================================

    public function test_store_creates_course(): void
    {
        $category   = Category::factory()->create();
        $instructor = $this->instructor();
        ['headers' => $headers] = $this->adminToken();

        // The D6 modal's contract (both titles, type, level, image, rule).
        $response = $this->withHeaders($headers)->postJson(self::BASE . '/courses', [
            'title'            => ['en' => 'New Course', 'ar' => 'دورة جديدة'],
            'description'      => 'Description text',
            'category_id'      => $category->id,
            'course_type'      => 'online',
            'level'            => 'beginner',
            'hours'            => 10,
            'certificate_rule' => 'general',
            'image'            => UploadedFile::fake()->image('course.jpg'),
            'instructors'      => [$instructor->id],
        ]);

        $this->assertCreated($response);
        $this->assertDatabaseHas('courses', ['category_id' => $category->id]);
    }

    public function test_store_requires_admin_role(): void
    {
        ['headers' => $headers] = $this->userToken();

        $response = $this->withHeaders($headers)->postJson(self::BASE . '/courses', [
            'title' => 'Should fail',
        ]);

        $this->assertForbidden($response);
    }

    public function test_store_validates_required_fields(): void
    {
        ['headers' => $headers] = $this->adminToken();

        $response = $this->withHeaders($headers)->postJson(self::BASE . '/courses', []);

        $this->assertValidationError($response);
    }

    // =========================================================================
    // PUT /api/v1/courses/{id}
    // =========================================================================

    public function test_update_modifies_course(): void
    {
        $course     = Course::factory()->create();
        $instructor = $this->instructor();
        ['headers' => $headers] = $this->adminToken();

        $response = $this->withHeaders($headers)->putJson(self::BASE . '/courses/' . $course->id, [
            'title'       => ['en' => 'Updated Title', 'ar' => 'عنوان محدث'],
            'description' => 'Updated description',
            'category_id' => $course->category_id,
            'hours'       => 20,
            'certificate' => false,
            'instructors' => [$instructor->id],
        ]);

        $this->assertSuccess($response);
    }

    public function test_update_returns_404_for_unknown_course(): void
    {
        ['headers' => $headers] = $this->adminToken();

        $response = $this->withHeaders($headers)->putJson(self::BASE . '/courses/99999', [
            'title' => 'Ghost',
        ]);

        $this->assertNotFound($response);
    }

    // =========================================================================
    // DELETE /api/v1/courses/{id}
    // =========================================================================

    public function test_destroy_deletes_course(): void
    {
        $course = Course::factory()->create();
        ['headers' => $headers] = $this->adminToken();

        $response = $this->withHeaders($headers)->deleteJson(self::BASE . '/courses/' . $course->id);

        $this->assertSuccess($response);
        $this->assertDatabaseMissing('courses', ['id' => $course->id]);
    }

    public function test_destroy_forbids_regular_user(): void
    {
        $course = Course::factory()->create();
        ['headers' => $headers] = $this->userToken();

        $response = $this->withHeaders($headers)->deleteJson(self::BASE . '/courses/' . $course->id);

        $this->assertForbidden($response);
    }
}
