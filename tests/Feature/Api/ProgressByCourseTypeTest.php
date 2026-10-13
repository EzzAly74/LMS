<?php

namespace Tests\Feature\Api;

use App\Models\Course;
use App\Models\CourseLecture;
use App\Models\CourseSection;
use App\Models\User;
use App\Services\LectureProgressService;
use Illuminate\Support\Facades\DB;

/**
 * D-092: progress follows the course type.
 * - online / external link: modules only;
 * - offline / hybrid without modules: attendance of the cohort's sessions;
 * - any course with modules: the learner's cohort modules (D-089).
 * The Website, the Dashboard learner detail and the course Learners tab agree.
 */
class ProgressByCourseTypeTest extends ApiTestCase
{
    /** Enrolled in a 4-session cohort, attended 3 sessions. */
    private function enrolled(string $type, int $modules = 0, int $modulesDone = 0): array
    {
        $user = User::factory()->create();
        $course = Course::factory()->create(['course_type' => $type]);
        $cohort = CourseSection::factory()->create(['course_id' => $course->id]);
        DB::table('users_courses')->insert(['user_id' => $user->id, 'course_id' => $course->id, 'group_id' => $cohort->id, 'created_at' => now(), 'updated_at' => now()]);
        $sessions = [];
        for ($i = 0; $i < 4; $i++) {
            $sessions[] = DB::table('course_sessions')->insertGetId([
                'course_id' => $course->id, 'section_id' => $cohort->id, 'title' => 'S'.$i,
                'session_date' => now()->subDays(10 - $i)->toDateString(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach (array_slice($sessions, 0, 3) as $sessionId) {
            DB::table('attendances')->insert(['user_id' => $user->id, 'course_id' => $course->id, 'section_id' => $cohort->id, 'session_id' => $sessionId, 'created_at' => now(), 'updated_at' => now()]);
        }
        for ($i = 0; $i < $modules; $i++) {
            $lecture = CourseLecture::factory()->create(['course_id' => $course->id]);
            if ($i < $modulesDone) {
                DB::table('user_lecture_progress')->insert(['user_id' => $user->id, 'lecture_id' => $lecture->id, 'progress' => 100, 'completed' => 1, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        return [$user, $course];
    }

    private function dashboardRow(User $user): array
    {
        return $this->getJson(self::BASE.'/admin/learners/'.$user->id.'/courses', $this->adminToken()['headers'])->assertOk()->json('result.0');
    }

    private function learnersTab(Course $course): array
    {
        return $this->getJson(self::BASE."/courses/{$course->id}/enrollments", $this->adminToken()['headers'])->assertOk()->json('result.0');
    }

    public function test_a_sessions_only_offline_course_progresses_by_attendance(): void
    {
        [$user, $course] = $this->enrolled('offline');

        $this->assertSame(75, app(LectureProgressService::class)->getCourseProgress($user->id, $course->id));
        $this->assertSame([$course->id => 75], app(LectureProgressService::class)->getCourseProgressBatch($user->id, [$course->id]));
        $this->assertSame(75, $this->dashboardRow($user)['progress_percent']);
        $this->assertSame(75, $this->learnersTab($course)['progress']);
    }

    public function test_a_course_with_modules_progresses_by_modules_whatever_the_attendance(): void
    {
        [$user, $course] = $this->enrolled('hybrid', modules: 4, modulesDone: 1);

        $this->assertSame(25, app(LectureProgressService::class)->getCourseProgress($user->id, $course->id));
        $this->assertSame(25, $this->dashboardRow($user)['progress_percent']);
        $this->assertSame(25, $this->learnersTab($course)['progress']);
    }

    public function test_an_online_course_with_sessions_and_no_modules_shows_attendance_too(): void
    {
        // D-094: decided by the data, not the course type.
        [$user, $course] = $this->enrolled('online');

        $this->assertSame(75, app(LectureProgressService::class)->getCourseProgress($user->id, $course->id));
        $this->assertSame(75, $this->dashboardRow($user)['progress_percent']);
        $this->assertSame(75, $this->learnersTab($course)['progress']);
    }
}
