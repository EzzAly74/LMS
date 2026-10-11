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

    /** NEW2B-6202 / NEW2B-6206: the card counts the cohort's sessions, not a stored planned number. */
    public function test_my_learnings_counts_the_cohorts_session_rows(): void
    {
        ['model' => $user, 'headers' => $h] = $this->userToken();
        $course = Course::factory()->create();
        DB::table('courses')->where('id', $course->id)->update(['number_of_sessions' => 12]);
        $cohort = CourseSection::factory()->create(['course_id' => $course->id]);
        DB::table('course_sections')->where('id', $cohort->id)->update(['number_of_sessions' => 4]);
        DB::table('users_courses')->insert(['user_id' => $user->id, 'course_id' => $course->id, 'group_id' => $cohort->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('course_sessions')->insert([
            'course_id' => $course->id, 'section_id' => $cohort->id, 'title' => 'Session 1', 'session_date' => '2026-11-02',
            'time_from' => '13:00:00', 'time_to' => '16:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $card = collect($this->getJson(self::BASE.'/my/learnings', $h)->assertOk()->json('result'))->firstWhere('id', $course->id);

        $this->assertSame(1, $card['cohort']['session_count']);
        $this->assertSame('2026-11-02', $card['cohort']['start_date']);
        $this->assertSame('2026-11-02', $card['cohort']['end_date']);
    }
}
