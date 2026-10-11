<?php

namespace Tests\Feature\Api\Course;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\User;
use App\Support\Notifications\CourseContentNotifier;
use ArrayObject;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\ApiTestCase;

/**
 * NEW2B-5803: enrolled learners get a bell notification when content is added
 * to their course: a module, or a quiz once it is published.
 */
class CourseContentNotificationTest extends ApiTestCase
{
    private function setUpCourse(): array
    {
        $course = Course::factory()->create(['title' => ['en' => 'Excel Basics', 'ar' => 'أساسيات إكسل']]);
        $cohort = CourseSection::factory()->create(['course_id' => $course->id]);
        $enrolled = User::factory()->create();
        $inactive = User::factory()->create(['status' => 'deactivated']);
        $outsider = User::factory()->create();
        foreach ([$enrolled, $inactive] as $u) {
            DB::table('users_courses')->insert(['user_id' => $u->id, 'course_id' => $course->id, 'group_id' => $cohort->id, 'created_at' => now(), 'updated_at' => now()]);
        }

        return [$course, $enrolled, $inactive, $outsider];
    }

    private function notificationsOf(User $user): array
    {
        return DB::table('notifications')->where('notifiable_type', $user->getMorphClass())->where('notifiable_id', $user->id)
            ->pluck('data')->map(fn ($d) => json_decode($d, true))->all();
    }

    public function test_adding_a_module_notifies_the_enrolled_learners_only(): void
    {
        [$course, $enrolled, $inactive, $outsider] = $this->setUpCourse();
        ['headers' => $h] = $this->adminToken();

        $this->postJson(self::BASE."/courses/{$course->id}/lectures", [
            'title' => ['en' => 'Formulas', 'ar' => 'المعادلات'], 'content_type' => 'article', 'duration_minutes' => 30,
            'type' => 'article', 'video' => null, 'content' => '<p>Body</p>', 'require_completion' => false,
        ], $h)->assertSuccessful();

        $rows = $this->notificationsOf($enrolled);
        $this->assertCount(1, $rows);
        $this->assertSame('course_content_added', $rows[0]['type']);
        $this->assertSame('"Formulas" was added to "Excel Basics".', $rows[0]['body_en']);
        $this->assertStringContainsString('المعادلات', $rows[0]['body_ar']);
        $this->assertSame([], $this->notificationsOf($inactive));
        $this->assertSame([], $this->notificationsOf($outsider));
    }

    public function test_a_quiz_notifies_when_it_is_published_not_while_it_is_a_draft(): void
    {
        [$course] = $this->setUpCourse();
        ['headers' => $h] = $this->adminToken();
        // Record the announcements: the test app is reused across requests, so
        // after-response work would run again on every later request here.
        $calls = new ArrayObject();
        $this->app->instance(CourseContentNotifier::class, new class($calls) extends CourseContentNotifier {
            public function __construct(private readonly ArrayObject $calls) {}

            public function added(Course $course, string $kind, array $title, int $itemId): void
            {
                $this->calls->append([$kind, $title['en'], $itemId]);
            }
        });
        $quiz = [
            'course_id' => $course->id, 'title' => 'Check', 'title_ar' => 'اختبار', 'type' => 'mid', 'cohort_scope' => 'all', 'status' => 'draft',
            'questions' => [[
                'type' => 'mcq', 'score' => 10, 'question_en' => 'Pick', 'question_ar' => 'اختر',
                'options_en' => ['a', 'b'], 'options_ar' => ['أ', 'ب'], 'correct_answer_en' => 'a', 'correct_answer_ar' => 'أ',
            ]],
        ];

        $id = $this->postJson(self::BASE.'/admin/quizzes', $quiz, $h)->assertSuccessful()->json('result.id');
        $this->assertCount(0, $calls);

        $this->putJson(self::BASE."/admin/quizzes/{$id}", ['status' => 'active'] + $quiz, $h)->assertOk();
        $this->putJson(self::BASE."/admin/quizzes/{$id}", ['status' => 'active'] + $quiz, $h)->assertOk();

        $this->assertSame([['quiz', 'Check', $id]], $calls->getArrayCopy());
    }
}
