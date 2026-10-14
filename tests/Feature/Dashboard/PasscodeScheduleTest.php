<?php

namespace Tests\Feature\Dashboard;

use App\Models\Admin;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\CourseSession;
use App\Models\Instructor;
use App\Models\Setting;
use App\Models\User;
use App\Models\UsersCourse;
use App\Services\Mobile\SessionPasscodeService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Api\Mobile\MobileTestCase;

/**
 * "Generate Passcode" opens attendance for the cohort's PLANNED session
 * (D-105): on time, early, late or on another day it is the same session,
 * moved to now when needed, so the session count, hours and numbering stay
 * as planned; learners mark against the session that holds the code.
 * Clock frozen at 10:00.
 */
class PasscodeScheduleTest extends MobileTestCase
{
    private Course $course;
    private CourseSection $cohort;
    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::today()->setTime(10, 0, 0));
        Setting::where('key', 'course_attendance_enabled')->update(['value' => '1']); // a code for the whole session
        Cache::flush();

        $instructor   = Instructor::query()->create(['name' => ['en' => 'Mona Adel', 'ar' => 'منى عادل'], 'email' => 'mona@academy.test']);
        $this->course = Course::factory()->create();
        $this->course->instructors()->attach($instructor->id);
        $this->cohort = CourseSection::factory()->running()->create(['course_id' => $this->course->id]);

        $role = Role::findOrCreate('passcode-'.uniqid(), 'admin');
        $role->givePermissionTo(Permission::findOrCreate('view-dashboard', 'admin'));
        $admin = Admin::factory()->create();
        $admin->forceFill(['instructor_id' => $instructor->id])->save();
        $admin->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->headers = ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function planned(string $date, string $from, string $to): CourseSession
    {
        return CourseSession::factory()->create([
            'course_id' => $this->course->id, 'section_id' => $this->cohort->id,
            'session_date' => $date, 'time_from' => $from, 'time_to' => $to,
            'passcode' => null, 'passcode_issued_at' => null, 'passcode_expires_at' => null,
        ]);
    }

    private function plan(): array
    {
        return $this->withHeaders($this->headers)
            ->getJson(self::BASE."/dashboard/passcode/plan?course_id={$this->course->id}&cohort_id={$this->cohort->id}")
            ->assertOk()->json('result');
    }

    private function start(?int $sessionId): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($this->headers)->postJson(self::BASE.'/dashboard/passcode', array_filter([
            'course_id' => $this->course->id, 'cohort_id' => $this->cohort->id, 'session_id' => $sessionId,
        ]));
    }

    private function learnerMarks(User $learner, string $code): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($this->headersFor($learner))
            ->postJson(self::BASE.'/mobile/attendance/mark', ['course_id' => $this->course->id, 'passcode' => $code]);
    }

    private function enrolled(): User
    {
        $learner = $this->employee();
        UsersCourse::factory()->create(['user_id' => $learner->id, 'course_id' => $this->course->id, 'group_id' => $this->cohort->id]);

        return $learner;
    }

    public function test_on_time_the_planned_session_gets_the_code_and_learners_mark_it(): void
    {
        $today = now()->toDateString();
        $this->planned($today, '08:00:00', '09:00:00');
        $planned = $this->planned($today, '09:45:00', '11:45:00');

        $plan = $this->plan();
        $this->assertSame('on_time', $plan['case']);
        $this->assertSame($planned->id, $plan['session']['id']);
        $this->assertSame(2, $plan['session']['number']);

        $code = $this->start($planned->id)->assertOk()->json('result.passcode.code');
        $this->assertSame(2, CourseSession::query()->where('section_id', $this->cohort->id)->count(), 'no extra session');
        $this->assertNotNull($planned->fresh()->started_at);

        $learner = $this->enrolled();
        $this->learnerMarks($learner, $code)->assertCreated();
        $this->assertDatabaseHas('attendances', ['user_id' => $learner->id, 'session_id' => $planned->id]);
    }

    public function test_started_early_the_next_planned_session_moves_to_now_and_counts_once(): void
    {
        $planned = $this->planned(now()->toDateString(), '14:00:00', '16:00:00');

        $plan = $this->plan();
        $this->assertSame('early', $plan['case']);
        $this->assertSame('14:00', $plan['session']['time_from']);

        $code = $this->start($planned->id)->assertOk()->json('result.passcode.code');
        $fresh = $planned->fresh();
        $this->assertSame('10:00:00', substr((string) $fresh->time_from, 0, 8));
        $this->assertSame('12:00:00', substr((string) $fresh->time_to, 0, 8), 'keeps its planned length');
        $this->assertSame('14:00:00', substr((string) $fresh->planned_time_from, 0, 8));
        $this->assertSame(1, CourseSession::query()->where('section_id', $this->cohort->id)->count());

        $this->learnerMarks($this->enrolled(), $code)->assertCreated();
    }

    public function test_started_late_the_missed_session_gets_a_code_that_is_valid_now(): void
    {
        $planned = $this->planned(now()->toDateString(), '07:00:00', '08:00:00');

        $this->assertSame('late', $this->plan()['case']);
        $result = $this->start($planned->id)->assertOk()->json('result');

        // The code is not dead on arrival (it used to expire at 08:15).
        $this->assertTrue(Carbon::parse($result['passcode']['expires_at'])->isFuture());
        $this->learnerMarks($this->enrolled(), $result['passcode']['code'])->assertCreated();
        $this->assertDatabaseHas('attendances', ['session_id' => $planned->id]);
    }

    public function test_on_a_day_without_a_session_the_next_planned_one_runs_now(): void
    {
        $this->planned(now()->subDays(2)->toDateString(), '10:00:00', '12:00:00')
            ->forceFill(['started_at' => now()->subDays(2)])->save();
        $next = $this->planned(now()->addDays(2)->toDateString(), '10:00:00', '11:30:00');
        $this->planned(now()->addDays(4)->toDateString(), '10:00:00', '11:30:00');

        $plan = $this->plan();
        $this->assertSame('moved', $plan['case']);
        $this->assertSame($next->id, $plan['session']['id']);
        $this->assertSame(2, $plan['session']['number']);

        $this->start($next->id)->assertOk();
        $fresh = $next->fresh();
        $this->assertSame(now()->toDateString(), substr((string) $fresh->session_date, 0, 10));
        $this->assertSame(now()->addDays(2)->toDateString(), substr((string) $fresh->planned_date, 0, 10));
        $this->assertSame(3, CourseSession::query()->where('section_id', $this->cohort->id)->count());
    }

    public function test_when_every_planned_session_is_over_nothing_starts(): void
    {
        $this->planned(now()->subDay()->toDateString(), '10:00:00', '12:00:00');

        $this->assertSame('none', $this->plan()['case']);
        $this->start(null)->assertStatus(422)->assertJsonPath('errors.plan.case', 'none');
        $this->assertSame(1, CourseSession::query()->where('section_id', $this->cohort->id)->count());
    }

    public function test_a_cohort_without_a_schedule_gets_a_session_for_today(): void
    {
        $this->assertSame('unscheduled', $this->plan()['case']);
        $this->start(null)->assertOk()->assertJsonPath('result.state', 'live');
        $this->assertSame(1, CourseSession::query()->where('section_id', $this->cohort->id)->count());
    }

    public function test_starting_twice_reuses_the_live_session_and_a_stale_confirmation_is_refused(): void
    {
        $first = $this->planned(now()->toDateString(), '09:30:00', '11:30:00');
        $code  = $this->start($first->id)->assertOk()->json('result.passcode.code');

        $this->assertSame('live', $this->plan()['case']);
        $this->assertSame($code, $this->start($first->id)->assertOk()->json('result.passcode.code'));
        $this->assertSame(1, CourseSession::query()->where('section_id', $this->cohort->id)->count());

        $this->start($first->id + 999)->assertStatus(409)->assertJsonPath('errors.plan.session.id', $first->id);
    }

    public function test_end_keeps_the_planned_times_and_a_restart_reuses_the_session(): void
    {
        $planned = $this->planned(now()->toDateString(), '09:30:00', '11:30:00');
        $this->start($planned->id)->assertOk();

        $this->withHeaders($this->headers)->postJson(self::BASE.'/dashboard/passcode/end')->assertOk();
        $fresh = $planned->fresh();
        $this->assertNull($fresh->passcode);
        $this->assertSame('11:30:00', substr((string) $fresh->time_to, 0, 8));

        $this->assertSame('on_time', $this->plan()['case']);
        $this->start($planned->id)->assertOk();
        $this->assertSame(1, CourseSession::query()->where('section_id', $this->cohort->id)->count());
    }

    public function test_a_code_replaced_by_a_rotation_still_counts_for_a_few_seconds(): void
    {
        $planned = $this->planned(now()->toDateString(), '09:30:00', '11:30:00');
        $old = $this->start($planned->id)->assertOk()->json('result.passcode.code');
        app(SessionPasscodeService::class)->issue($planned->fresh(), null, null);

        $learner = $this->enrolled();
        $this->learnerMarks($learner, $old)->assertCreated();

        Carbon::setTestNow(now()->addSeconds(SessionPasscodeService::PREVIOUS_CODE_GRACE_SECONDS + 1));
        // Two rotations later the first code is neither current nor just replaced.
        app(SessionPasscodeService::class)->issue($planned->fresh(), null, null);
        $this->learnerMarks($this->enrolled(), $old)->assertOk()->assertJsonPath('result.failure', 'invalid_code');
    }

    public function test_only_the_courses_instructor_may_plan_or_start(): void
    {
        $other = Course::factory()->create();
        $cohort = CourseSection::factory()->running()->create(['course_id' => $other->id]);

        $this->withHeaders($this->headers)
            ->getJson(self::BASE."/dashboard/passcode/plan?course_id={$other->id}&cohort_id={$cohort->id}")->assertForbidden();
        $this->withHeaders($this->headers)
            ->postJson(self::BASE.'/dashboard/passcode', ['course_id' => $other->id, 'cohort_id' => $cohort->id])->assertForbidden();
        $this->withHeaders($this->headers)
            ->getJson(self::BASE."/dashboard/passcode/plan?course_id={$this->course->id}&cohort_id={$cohort->id}")->assertStatus(422);
        $this->withHeaders($this->headers)
            ->postJson(self::BASE.'/dashboard/passcode', ['course_id' => $this->course->id, 'cohort_id' => $this->cohort->id, 'length' => 3])
            ->assertStatus(422)->assertJsonValidationErrors('length');
    }

    public function test_the_schedule_sheet_still_recognises_a_moved_session(): void
    {
        $next = $this->planned(now()->addDays(2)->toDateString(), '10:00:00', '11:30:00');
        $this->start($next->id)->assertOk();

        $rows = \Illuminate\Support\Facades\DB::table('course_sessions')->where('section_id', $this->cohort->id)
            ->get(['planned_date', 'planned_time_from', 'planned_time_to']);
        $this->assertCount(1, $rows);
        $this->assertSame(now()->addDays(2)->toDateString(), substr((string) $rows[0]->planned_date, 0, 10));
        $this->assertArrayNotHasKey('passcode', $next->fresh()->toArray(), 'codes are never serialised');
    }
}
