<?php

namespace Tests\Feature\Api\Learner;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\User;
use Database\Seeders\MobileSettingSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\ApiTestCase;

/**
 * NEW2B-5744: active and upcoming courses are not Completed. A cohort with
 * sessions has ended when its last session has ended; stale stored status /
 * end dates do not decide it.
 */
class MyLearningBucketsTest extends ApiTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function enrol(User $user, array $cohort, array $sessions): Course
    {
        $course = Course::factory()->create();
        $section = CourseSection::factory()->create(['course_id' => $course->id]);
        DB::table('course_sections')->where('id', $section->id)->update($cohort);
        DB::table('users_courses')->insert(['user_id' => $user->id, 'course_id' => $course->id, 'group_id' => $section->id, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($sessions as [$date, $from, $to]) {
            DB::table('course_sessions')->insert(['course_id' => $course->id, 'section_id' => $section->id, 'title' => 'S', 'session_date' => $date,
                'time_from' => $from, 'time_to' => $to, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $course;
    }

    public function test_courses_land_in_the_bucket_their_sessions_say(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-11 10:00:00', config('app.timezone')));
        $this->seed(MobileSettingSeeder::class);
        ['model' => $user, 'headers' => $h] = $this->userToken();

        // Stored as completed with an old end date, but rescheduled into the future.
        $upcoming = $this->enrol($user, ['status' => 'completed', 'start_date' => '2026-09-01', 'end_date' => '2026-09-10'], [['2026-10-20', '09:00:00', '12:00:00']]);
        // Under way: its last session is later today.
        $active = $this->enrol($user, ['status' => 'active', 'start_date' => '2026-10-01', 'end_date' => '2026-10-01'], [['2026-10-01', '09:00:00', '12:00:00'], ['2026-10-11', '13:00:00', '16:00:00']]);
        // Every session has ended.
        $done = $this->enrol($user, ['status' => 'active', 'start_date' => '2026-09-01', 'end_date' => '2026-12-01'], [['2026-09-01', '09:00:00', '12:00:00']]);

        $current = collect($this->getJson(self::BASE.'/learner/profile/learnings?per_page=50', $h)->assertOk()->json('result'));
        $completed = collect($this->getJson(self::BASE.'/learner/profile/completed?per_page=50', $h)->assertOk()->json('result'));

        $idsOf = fn ($rows) => $rows->map(fn ($r) => (int) ($r['course_id'] ?? $r['id'] ?? 0))->sort()->values()->all();
        $this->assertSame(collect([$upcoming->id, $active->id])->sort()->values()->all(), $idsOf($current));
        $this->assertSame([$done->id], $idsOf($completed->filter(fn ($r) => ($r['type'] ?? 'course') !== 'external')));

        $card = $current->first(fn ($r) => (int) ($r['course_id'] ?? $r['id']) === $upcoming->id);
        $this->assertTrue($card['cohort']['is_upcoming']);
        $this->assertSame('2026-10-20', $card['cohort']['start_date']);
        // NEW2B-6002: the upcoming course carries its days and time range.
        $this->assertSame(['weekdays' => [2], 'time_from' => '09:00', 'time_to' => '12:00'], $card['cohort']['schedule']);
        $activeCard = $current->first(fn ($r) => (int) ($r['course_id'] ?? $r['id']) === $active->id);
        $this->assertFalse($activeCard['cohort']['is_upcoming']);
    }
}
