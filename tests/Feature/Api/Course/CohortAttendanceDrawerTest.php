<?php

namespace Tests\Feature\Api\Course;

use App\Models\Admin;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Instructor;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Api\ApiTestCase;

/**
 * The cohort "Attendance Record" drawer (human, 2026-10-15): a learner who
 * attended two sessions showed "no attendance records" for the course's
 * instructor. The endpoint sat under a permission section no role can hold
 * and scoped accounts cannot use, so every account but a super admin got a
 * 403. It now follows the course's permission and scope.
 */
class CohortAttendanceDrawerTest extends ApiTestCase
{
    private Course $course;
    private CourseSection $cohort;

    protected function setUp(): void
    {
        parent::setUp();
        $this->course = Course::factory()->create();
        $this->cohort = CourseSection::factory()->create(['course_id' => $this->course->id]);
        $learner = User::factory()->create();
        DB::table('users_courses')->insert(['user_id' => $learner->id, 'course_id' => $this->course->id, 'group_id' => $this->cohort->id]);
        foreach (['2026-10-14', '2026-10-15', '2026-10-20'] as $i => $date) {
            $sid = DB::table('course_sessions')->insertGetId([
                'course_id' => $this->course->id, 'section_id' => $this->cohort->id, 'title' => 'Session '.($i + 1),
                'session_date' => $date, 'time_from' => '10:00:00', 'time_to' => '12:00:00', 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($i < 2) {
                DB::table('attendances')->insert(['user_id' => $learner->id, 'course_id' => $this->course->id, 'section_id' => $this->cohort->id,
                    'session_id' => $sid, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    private function url(?Course $course = null, ?CourseSection $cohort = null): string
    {
        return self::BASE.'/courses/'.($course ?? $this->course)->id.'/cohorts/'.($cohort ?? $this->cohort)->id.'/attendance';
    }

    /** A Dashboard account with these permissions; for an instructor, limited to their courses. */
    private function account(array $permissions, ?Course $teaches = null): array
    {
        $role = Role::findOrCreate('role-'.uniqid(), 'admin');
        $role->givePermissionTo(array_map(fn ($n) => Permission::findOrCreate($n, 'admin'), $permissions));
        $admin = Admin::factory()->create();
        if ($teaches !== null) {
            DB::table('roles')->where('id', $role->id)->update(['course_scope' => 'assigned']);
            $instructor = Instructor::query()->create(['name' => ['en' => 'Mohamed', 'ar' => 'محمد'], 'email' => 'm-'.uniqid().'@academy.test']);
            $teaches->instructors()->attach($instructor->id);
            $admin->forceFill(['instructor_id' => $instructor->id])->save();
        }
        $admin->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken];
    }

    public function test_the_courses_instructor_sees_the_cohorts_attendance(): void
    {
        $h = $this->account(['view-courses'], $this->course);

        // After every session: the third was missed.
        \Illuminate\Support\Carbon::setTestNow('2026-10-21 12:00:00');
        $this->getJson($this->url(), $h)->assertOk()
            ->assertJsonPath('result.totals.sessions', 3)
            ->assertJsonPath('result.totals.learners', 1)
            ->assertJsonPath('result.totals.attended', 2)
            ->assertJsonPath('result.totals.absent', 1);
        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_an_upcoming_or_live_session_has_no_absentees(): void
    {
        $h = $this->account(['view-courses'], $this->course);
        // 15 Oct 11:00: the 14 Oct session is held, 15 Oct is live, 20 Oct upcoming.
        \Illuminate\Support\Carbon::setTestNow('2026-10-15 11:00:00');

        $body = $this->getJson($this->url(), $h)->assertOk()->json('result');
        $this->assertSame(['held', 'live', 'upcoming'], array_column($body['sessions'], 'status'));
        $this->assertSame([0, 0, 0], array_column($body['sessions'], 'absent_count'));
        $this->assertSame(0, $body['totals']['absent']);
        $this->assertSame(1, $body['totals']['upcoming']);
        $this->assertSame(0, $body['learners'][0]['absent_count']);
        $this->assertSame(1, $body['learners'][0]['upcoming_count']);
        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_an_admin_with_the_course_permission_sees_it_too(): void
    {
        $this->getJson($this->url(), $this->account(['view-courses']))->assertOk()->assertJsonPath('result.totals.attended', 2);
    }

    public function test_another_courses_cohort_and_other_accounts_are_refused(): void
    {
        $other = Course::factory()->create();
        $h = $this->account(['view-courses'], $other);
        $this->getJson($this->url(), $h)->assertNotFound();

        // A cohort of another course under this course's URL.
        $foreign = CourseSection::factory()->create(['course_id' => $other->id]);
        $this->getJson($this->url($this->course, $foreign), $this->account(['view-courses']))->assertNotFound();

        $this->getJson($this->url(), $this->account(['view-learners']))->assertStatus(403);
        $this->getJson($this->url(), $this->userToken()['headers'])->assertStatus(403);
    }
}
