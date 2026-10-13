<?php

namespace Tests\Feature\Certificates;

use App\Models\Course;
use App\Models\CourseExam;
use App\Models\CourseLecture;
use App\Models\CourseSection;
use App\Models\User;
use App\Models\UserCertificate;
use App\Models\UsersCourse;
use App\Services\CertificatePolicy;
use App\Services\CertificateService;
use App\Support\CourseCompletion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ConfiguresCertificateRule;
use Tests\TestCase;

/**
 * D-094 (human, 2026-10-13): every course issues a certificate; eligibility
 * follows the applicable configuration (the course's own rule when it opted
 * out of the general one, else Platform Config), never the course type;
 * completion = holding the active certificate; a revoked certificate is never
 * re-issued automatically.
 *
 * Each case: a 4-session cohort, 2 modules, a final exam out of 100. Platform
 * Config thresholds are 75% attendance (3 of 4 sessions) and 60% score.
 */
class CertificateEligibilityMatrixTest extends TestCase
{
    use ConfiguresCertificateRule;
    use RefreshDatabase;

    private const TYPES = ['online', 'offline', 'hybrid', 'external_link'];

    /** @return array{0: User, 1: Course, 2: CourseSection, 3: list<int>, 4: list<int>, 5: CourseExam} */
    private function course(string $type, array $attributes = []): array
    {
        $user = User::factory()->create();
        $course = Course::factory()->create(['course_type' => $type, 'certificate' => true, 'is_evaluate' => false] + $attributes);
        $cohort = CourseSection::factory()->create(['course_id' => $course->id, 'start_date' => now()->subDays(20)->toDateString(), 'end_date' => now()->addDays(10)->toDateString()]);
        UsersCourse::factory()->create(['user_id' => $user->id, 'course_id' => $course->id, 'group_id' => $cohort->id]);
        $sessions = [];
        for ($i = 0; $i < 4; $i++) {
            $sessions[] = DB::table('course_sessions')->insertGetId([
                'course_id' => $course->id, 'section_id' => $cohort->id, 'title' => 'S'.($i + 1),
                'session_date' => now()->subDays(10 - $i)->toDateString(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $lectures = CourseLecture::factory()->count(2)->create(['course_id' => $course->id])->pluck('id')->all();
        $exam = CourseExam::factory()->final()->create(['course_id' => $course->id, 'degree' => 100]);

        return [$user, $course, $cohort, $sessions, $lectures, $exam];
    }

    private function attend(User $user, Course $course, CourseSection $cohort, array $sessions, int $count): void
    {
        foreach (array_slice($sessions, 0, $count) as $sessionId) {
            DB::table('attendances')->insert(['user_id' => $user->id, 'course_id' => $course->id, 'section_id' => $cohort->id, 'session_id' => $sessionId, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function sit(User $user, Course $course, CourseExam $exam, int $score): void
    {
        DB::table('user_exams')->insert([
            'user_id' => $user->id, 'course_id' => $course->id, 'exam_id' => $exam->id,
            'user_degree' => $score, 'status' => $score * 2 >= 100 ? 'success' : 'fail',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function issued(User $user, Course $course): bool
    {
        app(CertificateService::class)->evaluate($user, $course);

        return in_array($course->id, CourseCompletion::courseIdsFor($user->id)->all(), true);
    }

    /** @return iterable<string, array{string}> */
    public static function types(): iterable
    {
        foreach (self::TYPES as $type) {
            yield $type => [$type];
        }
    }

    /** @dataProvider types */
    public function test_attendance_rule_applies_to_every_type(string $type): void
    {
        $this->configureCertificateRule(CertificatePolicy::BASIS_ATTENDANCE, minAttendance: 75, minScore: 60);
        [$user, $course, $cohort, $sessions, , $exam] = $this->course($type);
        $this->sit($user, $course, $exam, 90); // the completion event (final exam passed)

        $this->attend($user, $course, $cohort, $sessions, 2);
        $this->assertFalse($this->issued($user, $course), "$type: 2 of 4 sessions is 50%");

        $this->attend($user, $course, $cohort, array_slice($sessions, 2), 1);
        $this->assertTrue($this->issued($user, $course), "$type: 3 of 4 sessions is 75%");
    }

    /** @dataProvider types */
    public function test_score_rule_applies_to_every_type(string $type): void
    {
        $this->configureCertificateRule(CertificatePolicy::BASIS_SCORE, minAttendance: 75, minScore: 60);
        [$user, $course, , , , $exam] = $this->course($type);

        $this->sit($user, $course, $exam, 55); // a pass by half the mark, below the 60% rule
        $this->assertFalse($this->issued($user, $course), "$type: 55% is below 60%");

        DB::table('user_exams')->where('user_id', $user->id)->update(['user_degree' => 80, 'status' => 'success']);
        $this->assertTrue($this->issued($user, $course), "$type: 80% meets 60%, no attendance needed");
    }

    /** @dataProvider types */
    public function test_both_rule_needs_both_on_every_type(string $type): void
    {
        $this->configureCertificateRule(CertificatePolicy::BASIS_BOTH, minAttendance: 75, minScore: 60);
        [$user, $course, $cohort, $sessions, , $exam] = $this->course($type);
        $this->sit($user, $course, $exam, 90);
        $this->assertFalse($this->issued($user, $course), "$type: score alone is not enough");

        $this->attend($user, $course, $cohort, $sessions, 3);
        $this->assertTrue($this->issued($user, $course), "$type: score and attendance both met");
    }

    /** @dataProvider types */
    public function test_a_course_rule_overrides_platform_config_on_every_type(string $type): void
    {
        // Platform Config asks for attendance; this course asks only for 80% score.
        $this->configureCertificateRule(CertificatePolicy::BASIS_ATTENDANCE, minAttendance: 75, minScore: 60);
        [$user, $course, , , , $exam] = $this->course($type, [
            'certificate_custom_rule' => true, 'certificate_mode' => 'score', 'certificate_score_threshold' => 80,
        ]);

        $this->sit($user, $course, $exam, 70);
        $this->assertFalse($this->issued($user, $course), "$type: 70% is below the course's 80%");

        DB::table('user_exams')->where('user_id', $user->id)->update(['user_degree' => 85]);
        $this->assertTrue($this->issued($user, $course), "$type: 85% meets the course's own rule, no attendance");
    }

    public function test_a_course_rule_missing_a_threshold_falls_back_to_platform_config(): void
    {
        $this->configureCertificateRule(CertificatePolicy::BASIS_ATTENDANCE, minAttendance: 75, minScore: 60);
        [$user, $course, , , , $exam] = $this->course('hybrid', [
            'certificate_custom_rule' => true, 'certificate_mode' => 'score', 'certificate_score_threshold' => null,
        ]);

        $this->sit($user, $course, $exam, 59);
        $this->assertFalse($this->issued($user, $course), 'falls back to the general 60%');
        DB::table('user_exams')->where('user_id', $user->id)->update(['user_degree' => 60]);
        $this->assertTrue($this->issued($user, $course));
    }

    /** @dataProvider types */
    public function test_content_courses_are_earned_by_modules_plus_the_rule(string $type): void
    {
        // No final exam: the modules are the event, the configured rule still applies.
        $this->configureCertificateRule(CertificatePolicy::BASIS_ATTENDANCE, minAttendance: 75, minScore: 60);
        [$user, $course, $cohort, $sessions, $lectures, $exam] = $this->course($type);
        $exam->delete();
        $this->attend($user, $course, $cohort, $sessions, 4);
        $this->assertFalse($this->issued($user, $course), "$type: attendance alone does not skip the modules");

        foreach ($lectures as $id) {
            DB::table('user_lecture_progress')->insert(['user_id' => $user->id, 'lecture_id' => $id, 'progress' => 100, 'completed' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->assertTrue($this->issued($user, $course), "$type: modules + rule");
    }

    public function test_the_certificate_switch_cannot_remove_the_rule(): void
    {
        $this->configureCertificateRule(CertificatePolicy::BASIS_ATTENDANCE, minAttendance: 75, minScore: 60);
        [$user, $course, , , , $exam] = $this->course('offline', ['certificate' => false]);
        $this->sit($user, $course, $exam, 100);

        // A passed final exam with no attendance: neither completed nor issued.
        $this->assertFalse($this->issued($user, $course));
        $this->assertSame(0, UserCertificate::query()->where('user_id', $user->id)->count());
    }

    public function test_a_revoked_certificate_is_never_reissued_automatically(): void
    {
        $this->configureCertificateRule(CertificatePolicy::BASIS_SCORE, minAttendance: 75, minScore: 60);
        [$user, $course, , , , $exam] = $this->course('online');
        $this->sit($user, $course, $exam, 90);
        $this->assertTrue($this->issued($user, $course));

        $certificate = UserCertificate::query()->where('user_id', $user->id)->firstOrFail();
        app(CertificateService::class)->revoke($certificate);

        $this->assertFalse($this->issued($user, $course), 'no trigger re-issues it');
        $this->assertSame(1, UserCertificate::query()->where('user_id', $user->id)->count());
    }
}
