<?php

namespace Tests\Feature\Api;

use App\Models\Course;
use App\Models\CourseNotifyInterest;
use App\Models\CourseSection;
use App\Models\User;
use App\Notifications\CohortOpenedNotification;
use App\Services\Learner\CohortInterestNotifier;
use Database\Seeders\MobileSettingSeeder;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * NEW2B-5780: "Notify me" / "Get notified" used to only store a row nobody
 * read. Now the learner is told, by bell and email, once a cohort they can
 * join opens (the catalogue's own rule), and only once.
 */
class CohortInterestNotifyTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MobileSettingSeeder::class);
        Notification::fake();
    }

    private function ask(User $user, Course $course): void
    {
        CourseNotifyInterest::query()->create(['user_id' => $user->id, 'course_id' => $course->id]);
    }

    private function notifier(): CohortInterestNotifier
    {
        return app(CohortInterestNotifier::class);
    }

    public function test_nobody_is_told_while_no_cohort_can_be_joined_and_the_request_is_kept(): void
    {
        $course = Course::factory()->create();
        CourseSection::factory()->create(['course_id' => $course->id, 'start_date' => now()->subDay()->toDateString()]); // already started
        $learner = User::factory()->create();
        $this->ask($learner, $course);

        $this->assertSame(0, $this->notifier()->notifyForCourse($course->id));
        Notification::assertNothingSent();
        $this->assertSame(1, CourseNotifyInterest::query()->count());
    }

    public function test_an_open_cohort_tells_each_waiting_learner_once_by_bell_and_email(): void
    {
        $course = Course::factory()->create();
        $cohort = CourseSection::factory()->create(['course_id' => $course->id, 'status' => 'open_for_enrollment']);
        [$a, $b] = User::factory()->count(2)->create();
        $noEmail = User::factory()->create(['email' => null]);
        $enrolled = User::factory()->create();
        DB::table('users_courses')->insert(['user_id' => $enrolled->id, 'course_id' => $course->id, 'group_id' => $cohort->id]);
        foreach ([$a, $b, $noEmail, $enrolled] as $u) {
            $this->ask($u, $course);
        }

        $this->assertSame(3, $this->notifier()->notifyForCourse($course->id));

        Notification::assertSentTo($a, CohortOpenedNotification::class, fn ($n, array $channels) => $channels === ['database', 'mail']);
        Notification::assertSentTo($b, CohortOpenedNotification::class);
        Notification::assertSentTo($noEmail, CohortOpenedNotification::class, fn ($n, array $channels) => $channels === ['database']);
        Notification::assertNotSentTo($enrolled, CohortOpenedNotification::class);
        $this->assertSame(0, CourseNotifyInterest::query()->count());

        // A second run (the daily job) tells nobody again.
        $this->assertSame(0, $this->notifier()->notifyAll());
        Notification::assertSentToTimes($a, CohortOpenedNotification::class, 1);
    }

    public function test_the_bell_row_names_the_course_and_date_in_both_languages(): void
    {
        $course = Course::factory()->create(['title' => ['en' => 'Management Course', 'ar' => 'كورس الإدارة']]);
        $cohort = CourseSection::factory()->create(['course_id' => $course->id, 'start_date' => '2026-12-06']);
        $data = (new CohortOpenedNotification($course, $cohort))->toDatabase(User::factory()->make());

        $this->assertSame('cohort_opened', $data['type']);
        $this->assertStringContainsString('Management Course', $data['body_en']);
        $this->assertStringContainsString('6 December 2026', $data['body_en']);
        $this->assertStringContainsString('كورس الإدارة', $data['body_ar']);
        $this->assertSame(['course_id' => $course->id, 'cohort_id' => $cohort->id], $data['meta']);
    }

    public function test_saving_a_cohort_and_the_daily_command_both_trigger_it(): void
    {
        $course = Course::factory()->create();
        $learner = User::factory()->create();
        $this->ask($learner, $course);

        CourseSection::factory()->create(['course_id' => $course->id, 'status' => 'open_for_enrollment']);
        app(DeferredCallbackCollection::class)->invoke(); // what the end of the request does
        Notification::assertSentTo($learner, CohortOpenedNotification::class);

        $other = Course::factory()->create();
        CourseSection::withoutEvents(fn () => CourseSection::factory()->create(['course_id' => $other->id, 'status' => 'open_for_enrollment']));
        $this->ask($learner, $other);
        $this->artisan('cohorts:notify-interests')->assertSuccessful();
        Notification::assertSentToTimes($learner, CohortOpenedNotification::class, 2);
    }

    public function test_the_profile_says_which_courses_the_learner_is_waiting_for(): void
    {
        $jobTitle = \App\Models\JobTitle::query()->create(['name' => 'Ops', 'name_en' => 'Ops', 'name_ar' => 'عمليات']);
        $qual = DB::table('qualification_skills')->insertGetId(['name' => json_encode(['en' => 'Team Leadership', 'ar' => 'قيادة الفرق'])]);
        DB::table('job_title_qualification_skill')->insert(['job_title_id' => $jobTitle->id, 'qualification_skill_id' => $qual]);
        [$asked, $notAsked] = Course::factory()->count(2)->create();
        DB::table('course_qualification_skills')->insert([
            ['course_id' => $asked->id, 'qualification_skill_id' => $qual],
            ['course_id' => $notAsked->id, 'qualification_skill_id' => $qual],
        ]);
        $learner = $this->userToken(User::factory()->create(['job_title_id' => $jobTitle->id]));
        $this->ask($learner['model'], $asked);

        $rows = collect($this->getJson(self::BASE.'/learner/profile/qualifications', $learner['headers'])->assertOk()
            ->json('result.0.uncovered_courses'))->keyBy('course_id');
        $this->assertTrue($rows[$asked->id]['notify_requested']);
        $this->assertFalse($rows[$notAsked->id]['notify_requested']);
    }

    public function test_notify_me_needs_a_signed_in_learner_and_is_idempotent(): void
    {
        $course = Course::factory()->create();
        $this->postJson(self::BASE."/learner/academy/courses/{$course->id}/notify-me")->assertUnauthorized();

        $learner = $this->userToken();
        $this->postJson(self::BASE."/learner/academy/courses/{$course->id}/notify-me", [], $learner['headers'])->assertOk();
        $this->postJson(self::BASE."/learner/academy/courses/{$course->id}/notify-me", [], $learner['headers'])->assertOk(); // idempotent
        $this->assertSame(1, CourseNotifyInterest::query()->where('user_id', $learner['model']->id)->count());
        $this->postJson(self::BASE.'/learner/academy/courses/999999/notify-me', [], $learner['headers'])->assertNotFound();
    }
}
