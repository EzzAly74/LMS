<?php

namespace Tests\Feature\Certificates;

use App\Models\Course;
use App\Models\CourseLecture;
use App\Models\CourseSection;
use App\Models\User;
use App\Models\UserCertificate;
use App\Models\UsersCourse;
use App\Services\CertificatePolicy;
use App\Services\CertificateProjectionService;
use App\Services\CertificateService;
use App\Services\LectureProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ConfiguresCertificateRule;
use Tests\TestCase;

/**
 * D-091: a certificate is issued when the course's completion event AND the
 * certificate rule are both met, whichever comes last.
 * - B-166: content finished before the sessions; attendance later issues it.
 * - B-166: attendance alone never skips the content.
 * - B-167: before any session is held the learner is on track, not at risk.
 * - B-180: a revoked certificate is not minted again by a later trigger.
 * - B-183: watching a finished video again does not undo it.
 */
class CertificateFlowTest extends TestCase
{
    use ConfiguresCertificateRule;
    use RefreshDatabase;

    private User $user;
    private Course $course;
    private CourseSection $cohort;
    /** @var list<int> */
    private array $sessions = [];
    /** @var list<int> */
    private array $lectures = [];

    /** A content course with 2 modules and a 4-session cohort; 75% attendance required. */
    private function setUpCourse(bool $started = true): void
    {
        $this->configureCertificateRule(CertificatePolicy::BASIS_ATTENDANCE, minAttendance: 75);
        $this->user = User::factory()->create();
        $this->course = Course::factory()->create(['course_type' => 'hybrid', 'certificate' => true, 'is_evaluate' => false]);
        $this->cohort = CourseSection::factory()->create([
            'course_id' => $this->course->id,
            'start_date' => now()->addDays($started ? -10 : 10)->toDateString(),
            'end_date' => now()->addDays($started ? 20 : 40)->toDateString(),
        ]);
        UsersCourse::factory()->create(['user_id' => $this->user->id, 'course_id' => $this->course->id, 'group_id' => $this->cohort->id]);
        for ($i = 0; $i < 4; $i++) {
            $this->sessions[] = DB::table('course_sessions')->insertGetId([
                'course_id' => $this->course->id, 'section_id' => $this->cohort->id, 'title' => 'S'.($i + 1),
                'session_date' => now()->addDays(($started ? -2 : 10) + $i * ($started ? 3 : 1))->toDateString(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach ([1, 2] as $_) {
            $this->lectures[] = CourseLecture::factory()->create(['course_id' => $this->course->id, 'content_type' => 'video'])->id;
        }
    }

    private function attend(int $session): void
    {
        DB::table('attendances')->insert([
            'user_id' => $this->user->id, 'course_id' => $this->course->id, 'section_id' => $this->cohort->id,
            'session_id' => $this->sessions[$session], 'created_at' => now(), 'updated_at' => now(),
        ]);
        app(CertificateService::class)->issueFromAttendance($this->user, $this->course);
    }

    private function finishContent(): void
    {
        foreach ($this->lectures as $id) {
            app(LectureProgressService::class)->track($this->user->id, $id, 100);
        }
    }

    private function certificate(): ?UserCertificate
    {
        return UserCertificate::query()->where('user_id', $this->user->id)->where('course_id', $this->course->id)->active()->first();
    }

    public function test_content_first_then_attendance_issues_the_certificate(): void
    {
        $this->setUpCourse();
        $this->finishContent();
        $this->assertNull($this->certificate(), 'no attendance yet');

        $this->attend(0);
        $this->attend(1);
        $this->assertNull($this->certificate(), '2 of 4 is 50%');
        $this->attend(2);

        $this->assertNotNull($this->certificate(), '3 of 4 is 75%: the attendance was the last piece');
        $this->assertSame(UserCertificate::SOURCE_CONTENT, $this->certificate()->source_type);
    }

    public function test_attendance_alone_does_not_skip_the_content(): void
    {
        $this->setUpCourse();
        foreach ([0, 1, 2, 3] as $s) {
            $this->attend($s);
        }
        $this->assertNull($this->certificate());

        $this->finishContent();
        $this->assertNotNull($this->certificate());
    }

    public function test_before_the_first_session_the_learner_is_on_track_not_at_risk(): void
    {
        $this->setUpCourse(started: false);
        $this->finishContent();

        $status = app(CertificateProjectionService::class)->projectForCourse($this->user, $this->course);
        $this->assertSame(CertificateProjectionService::STATUS_ON_TRACK, $status['status']);

        $batch = app(CertificateProjectionService::class)->projectForCourses($this->user, collect([$this->course]));
        $this->assertSame(CertificateProjectionService::STATUS_ON_TRACK, $batch[$this->course->id]['status']);
    }

    public function test_behind_on_held_sessions_is_at_risk(): void
    {
        $this->setUpCourse();
        // Sessions 1 (2 days ago) and 2 (tomorrow+) : one held, not attended.
        $status = app(CertificateProjectionService::class)->projectForCourse($this->user, $this->course);
        $this->assertSame(CertificateProjectionService::STATUS_AT_RISK, $status['status']);
    }

    public function test_the_outline_says_what_the_certificate_still_waits_for(): void
    {
        $this->setUpCourse(started: false);
        $this->finishContent();
        ['headers' => $headers] = $this->tokenFor($this->user);

        $waiting = $this->getJson('/api/v1/my/courses/'.$this->course->id.'/outline', $headers)->assertOk()->json('result.completion.waiting_for');

        $this->assertSame('starts', $waiting[0]['reason']);
        $this->assertSame(['reason' => 'attendance', 'attended' => 0, 'needed' => 3, 'total' => 4], $waiting[1]);
    }

    public function test_a_revoked_certificate_is_not_minted_again_by_a_trigger(): void
    {
        $this->setUpCourse();
        $this->finishContent();
        foreach ([0, 1, 2] as $s) {
            $this->attend($s);
        }
        $issued = $this->certificate();
        $this->assertNotNull($issued);

        app(CertificateService::class)->revoke($issued);
        $this->attend(3);

        $this->assertNull($this->certificate());
    }

    public function test_watching_a_finished_video_again_keeps_it_completed(): void
    {
        $this->setUpCourse();
        $service = app(LectureProgressService::class);
        $service->track($this->user->id, $this->lectures[0], 100);
        $service->track($this->user->id, $this->lectures[0], 10);

        $this->assertTrue((bool) DB::table('user_lecture_progress')->where('user_id', $this->user->id)->where('lecture_id', $this->lectures[0])->value('completed'));

        // The learner's own "No" still un-marks it.
        $service->track($this->user->id, $this->lectures[0], null, false);
        $this->assertFalse((bool) DB::table('user_lecture_progress')->where('user_id', $this->user->id)->where('lecture_id', $this->lectures[0])->value('completed'));
    }

    public function test_an_online_course_follows_the_configured_rule_too(): void
    {
        // D-094 (human, 2026-10-13): the admin-configured rule decides,
        // whatever the course type. Attendance is required here, so the
        // content alone does not earn it; the attendance does.
        $this->setUpCourse();
        $this->course->update(['course_type' => 'online']);
        $this->finishContent();
        $this->assertNull($this->certificate());

        foreach ([0, 1, 2] as $s) {
            $this->attend($s);
        }
        $this->assertNotNull($this->certificate());
    }

    /** @return array{headers: array<string, string>} */
    private function tokenFor(User $user): array
    {
        $token = $user->createToken('test')->plainTextToken;

        return ['headers' => ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json']];
    }
}
