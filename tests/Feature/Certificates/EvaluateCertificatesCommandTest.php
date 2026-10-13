<?php

namespace Tests\Feature\Certificates;

use App\Models\Course;
use App\Models\CourseExam;
use App\Models\User;
use App\Models\UserCertificate;
use App\Services\CertificatePolicy;
use App\Services\CertificateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ConfiguresCertificateRule;
use Tests\TestCase;

/** D-094: the backfill uses the live decision and never re-issues a revoked certificate. */
class EvaluateCertificatesCommandTest extends TestCase
{
    use ConfiguresCertificateRule;
    use RefreshDatabase;

    public function test_it_issues_only_the_eligible_and_skips_revoked(): void
    {
        $this->configureCertificateRule(CertificatePolicy::BASIS_SCORE, minScore: 60);
        $course = Course::factory()->create(['certificate' => false]);
        $exam = CourseExam::factory()->final()->create(['course_id' => $course->id, 'degree' => 100]);
        $pass = fn (User $u, int $score) => DB::table('user_exams')->insert(['user_id' => $u->id, 'course_id' => $course->id, 'exam_id' => $exam->id, 'user_degree' => $score, 'status' => $score >= 50 ? 'success' : 'fail', 'created_at' => now(), 'updated_at' => now()]);
        [$eligible, $below, $revoked] = User::factory()->count(3)->create()->all();
        foreach ([$eligible, $below, $revoked] as $u) {
            DB::table('users_courses')->insert(['user_id' => $u->id, 'course_id' => $course->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $pass($eligible, 80);
        $pass($below, 55);
        $pass($revoked, 90);
        app(CertificateService::class)->revoke(UserCertificate::factory()->create(['user_id' => $revoked->id, 'course_id' => $course->id]));

        $this->artisan('certificates:evaluate --dry-run')->assertSuccessful();
        $this->assertSame(1, UserCertificate::query()->count(), 'dry run writes nothing');

        $this->artisan('certificates:evaluate')->assertSuccessful();
        $this->assertTrue(UserCertificate::query()->where('user_id', $eligible->id)->active()->exists());
        $this->assertFalse(UserCertificate::query()->where('user_id', $below->id)->exists());
        $this->assertFalse(UserCertificate::query()->where('user_id', $revoked->id)->active()->exists());
    }
}
