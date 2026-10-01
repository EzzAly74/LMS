<?php

namespace Tests\Feature\Api\Course;

use App\Http\Requests\Api\CourseLectureRequest;
use App\Models\Admin;
use App\Models\Course;
use App\Models\CourseLecture;
use App\Models\CourseSection;
use App\Models\Instructor;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Api\ApiTestCase;

/**
 * Saving course content (modules) from the Dashboard Content tab
 * (NEW2B-5763): an instructor limited to their own courses adds an article,
 * including a long one pasted from a document.
 */
class CourseModuleSaveTest extends ApiTestCase
{
    private function instructorFor(Course $course): array
    {
        $instructor = Instructor::query()->create(['name' => ['en' => 'Mona', 'ar' => 'منى'], 'email' => 'mona@academy.test']);
        $course->instructors()->attach($instructor->id);

        $role = Role::findOrCreate('module-instructor', 'admin');
        DB::table('roles')->where('id', $role->id)->update(['course_scope' => 'assigned']);
        $role->givePermissionTo([
            Permission::findOrCreate('view-courses', 'admin'),
            Permission::findOrCreate('edit-courses', 'admin'),
        ]);
        $account = Admin::factory()->create();
        $account->forceFill(['instructor_id' => $instructor->id])->save();
        $account->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->adminToken($account)['headers'];
    }

    private function article(string $body, array $extra = []): array
    {
        return array_merge([
            'title' => ['en' => 'Reading', 'ar' => 'قراءة'],
            'content_type' => 'article',
            'learner_scope' => 'all',
            'session_number' => 1,
            'duration_minutes' => 30,
            'type' => 'article',
            'video' => null,
            'content' => $body,
            'require_completion' => false,
        ], $extra);
    }

    public function test_an_instructor_adds_an_article_to_their_own_course(): void
    {
        $course = Course::factory()->create();
        CourseSection::factory()->create(['course_id' => $course->id]);
        $headers = $this->instructorFor($course);

        $this->postJson(self::BASE."/courses/{$course->id}/lectures", $this->article('<p>Short body</p>'), $headers)
            ->assertSuccessful();

        $lecture = CourseLecture::query()->where('course_id', $course->id)->latest('id')->firstOrFail();
        $this->assertSame('article', $lecture->content_type);
        $this->assertSame('<p>Short body</p>', $lecture->content);
        $this->assertNull($lecture->video);
    }

    public function test_an_article_pasted_from_a_document_is_saved(): void
    {
        $course = Course::factory()->create();
        CourseSection::factory()->create(['course_id' => $course->id]);
        $headers = $this->instructorFor($course);

        // A few pages pasted from Word keep their inline styles: well past
        // 64 KB of markup for a modest amount of text.
        $paragraph = '<p style="margin:0cm;line-height:150%;font-family:Calibri,sans-serif;font-size:11pt;color:#1f1f1f">'
            .str_repeat('Customer experience starts with listening. ', 6).'</p>';
        $body = str_repeat($paragraph, 400);
        $this->assertGreaterThan(65535, strlen($body));

        $this->postJson(self::BASE."/courses/{$course->id}/lectures", $this->article($body), $headers)
            ->assertSuccessful();

        $this->assertSame(strlen($body), strlen((string) CourseLecture::query()->latest('id')->value('content')));
    }

    public function test_an_instructor_cannot_add_content_to_another_course(): void
    {
        $mine = Course::factory()->create();
        $other = Course::factory()->create();
        CourseSection::factory()->create(['course_id' => $other->id]);
        $headers = $this->instructorFor($mine);

        $this->postJson(self::BASE."/courses/{$other->id}/lectures", $this->article('<p>x</p>'), $headers)
            ->assertNotFound();
        $this->assertSame(0, CourseLecture::query()->where('course_id', $other->id)->count());
    }

    public function test_an_article_without_a_body_or_over_the_limit_is_rejected(): void
    {
        $course = Course::factory()->create();
        ['headers' => $headers] = $this->adminToken();

        $this->postJson(self::BASE."/courses/{$course->id}/lectures", $this->article(''), $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('content');

        $tooLong = str_repeat('a', CourseLectureRequest::ARTICLE_MAX + 1);
        $this->postJson(self::BASE."/courses/{$course->id}/lectures", $this->article($tooLong), $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('content');
        $this->assertSame(0, CourseLecture::query()->where('course_id', $course->id)->count());
    }
}
