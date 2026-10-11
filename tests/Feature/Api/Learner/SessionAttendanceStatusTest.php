<?php

namespace Tests\Feature\Api\Learner;

use App\Models\Course;
use App\Models\CourseSection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\ApiTestCase;

/**
 * NEW2B-6215: a session is Absent only once it has ended without attendance,
 * judged in the academy's time zone with the session's times.
 */
class SessionAttendanceStatusTest extends ApiTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_sessions_are_absent_only_after_they_end(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-11 10:00:00', config('app.timezone')));
        ['model' => $user, 'headers' => $h] = $this->userToken();
        $course = Course::factory()->create();
        $cohort = CourseSection::factory()->create(['course_id' => $course->id]);
        DB::table('users_courses')->insert(['user_id' => $user->id, 'course_id' => $course->id, 'group_id' => $cohort->id, 'created_at' => now(), 'updated_at' => now()]);

        $add = fn (string $title, string $date, string $from, string $to) => DB::table('course_sessions')->insertGetId([
            'course_id' => $course->id, 'section_id' => $cohort->id, 'title' => $title, 'session_date' => $date,
            'time_from' => $from, 'time_to' => $to, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $attendedId = $add('attended', '2026-10-08', '09:00:00', '12:00:00');
        $add('missed', '2026-10-09', '09:00:00', '12:00:00');
        $add('ended this morning', '2026-10-11', '07:00:00', '09:00:00');
        $add('running now', '2026-10-11', '09:30:00', '12:00:00');
        $add('later today', '2026-10-11', '13:00:00', '16:00:00');
        $add('tomorrow', '2026-10-12', '09:00:00', '12:00:00');
        DB::table('attendances')->insert(['user_id' => $user->id, 'course_id' => $course->id, 'section_id' => $cohort->id, 'session_id' => $attendedId, 'created_at' => now(), 'updated_at' => now()]);

        $rows = $this->getJson(self::BASE."/learner/profile/courses/{$course->id}/sessions", $h)->assertOk()->json('result');

        $this->assertSame([
            'attended' => 'present', 'missed' => 'absent', 'ended this morning' => 'absent',
            'running now' => 'in_progress', 'later today' => 'upcoming', 'tomorrow' => 'upcoming',
        ], array_column($rows, 'status', 'title'));
    }
}
