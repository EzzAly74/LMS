<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Course;
use App\Models\CourseExam;
use App\Models\JobTitle;
use App\Models\QualificationSkill;
use App\Models\User;
use App\Models\UserCertificate;
use App\Services\CertificateService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\ApiTestCase;

/**
 * B-169: the report exports use the shared rules, not enrolment counts.
 */
class ReportDatasetsTest extends ApiTestCase
{
    /** @return list<list<string>> the CSV rows after the heading */
    private function export(string $type): array
    {
        $response = $this->get(self::BASE."/admin/reports/{$type}/export?format=csv", $this->adminToken()['headers'])->assertOk();
        $csv = $response->streamedContent();
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $rows = array_map('str_getcsv', array_values(array_filter(preg_split('/\r?\n/', $csv), fn ($l) => $l !== '')));
        array_shift($rows);

        return $rows;
    }

    public function test_compliance_by_job_title_counts_holders_not_enrolments(): void
    {
        $job = JobTitle::query()->create(['name' => 'Report Analyst '.uniqid(), 'name_en' => 'Report Analyst']);
        $q = QualificationSkill::factory()->create();
        $job->qualificationSkills()->attach($q->id);
        $course = Course::factory()->create(['certificate' => true]);
        DB::table('course_qualification_skills')->insert(['course_id' => $course->id, 'qualification_skill_id' => $q->id]);

        $holder = User::factory()->create(['job_title_id' => $job->id]);
        $enrolledOnly = User::factory()->create(['job_title_id' => $job->id]);
        foreach ([$holder, $enrolledOnly] as $u) {
            DB::table('users_courses')->insert(['user_id' => $u->id, 'course_id' => $course->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        UserCertificate::factory()->create(['user_id' => $holder->id, 'course_id' => $course->id]);

        $row = collect($this->export('compliance-by-job-title'))->firstWhere(0, 'Report Analyst');
        $this->assertSame(['2', '1', '50%'], [$row[2], $row[3], $row[4]]);
    }

    public function test_certificate_status_lists_issued_certificates_with_revocations(): void
    {
        $course = Course::factory()->create(['certificate' => true]);
        $active = UserCertificate::factory()->create(['course_id' => $course->id]);
        $revoked = UserCertificate::factory()->create(['course_id' => $course->id]);
        app(CertificateService::class)->revoke($revoked);
        // An enrolment alone is not a certificate.
        DB::table('users_courses')->insert(['user_id' => User::factory()->create()->id, 'course_id' => $course->id, 'created_at' => now(), 'updated_at' => now()]);

        $rows = collect($this->export('certificate-status'));
        $this->assertCount(2, $rows);
        $this->assertSame(['Active', 'Revoked'], $rows->pluck(4)->sort()->values()->all());
        $this->assertContains((string) $active->fresh()->certificate_number, $rows->pluck(3)->all());
    }

    public function test_scores_skip_unsent_attempts_and_judge_by_the_pass_rule(): void
    {
        $course = Course::factory()->create();
        $exam = CourseExam::factory()->create(['course_id' => $course->id, 'title' => ['en' => 'Final', 'ar' => 'نهائي'], 'degree' => 20, 'pass_score' => null]);
        DB::table('course_exam_questions')->insert(['course_exam_id' => $exam->id, 'position' => 1, 'type' => 'mcq', 'score' => 20, 'question_en' => 'Q', 'question_ar' => 'س', 'created_at' => now(), 'updated_at' => now()]);
        $sent = fn (float $score, ?string $state) => DB::table('user_exams')->insert([
            'user_id' => User::factory()->create()->id, 'course_id' => $course->id, 'exam_id' => $exam->id,
            'user_degree' => 0, 'total_score' => $score, 'max_score' => 20, 'status' => 'success',
            'submission_status' => $state, 'submitted_at' => $state ? now() : null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $sent(15, 'submitted');
        $sent(4, 'submitted');   // stored 'success', but 4/20 is below half
        $sent(18, 'in_progress'); // opened, never sent

        $results = collect($this->export('scores'))->pluck(7)->sort()->values()->all();
        $this->assertSame(['Fail', 'Pass'], $results);
    }

    public function test_completion_reports_status_from_the_one_rule(): void
    {
        $course = Course::factory()->create(['certificate' => true]);
        $done = User::factory()->create(['email' => 'done@example.test']);
        $open = User::factory()->create(['email' => 'open@example.test']);
        foreach ([$done, $open] as $u) {
            DB::table('users_courses')->insert(['user_id' => $u->id, 'course_id' => $course->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        UserCertificate::factory()->create(['user_id' => $done->id, 'course_id' => $course->id]);

        $rows = collect($this->export('completion'))->keyBy(1);
        $this->assertSame('Completed', $rows['done@example.test'][6]);
        $this->assertNotSame('', $rows['done@example.test'][8]);
        $this->assertSame('Not started', $rows['open@example.test'][6]);
    }
}
