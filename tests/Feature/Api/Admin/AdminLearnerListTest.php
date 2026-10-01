<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Course;
use App\Models\JobTitle;
use App\Models\User;
use App\Models\UserCertificate;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\ApiTestCase;

/**
 * Stage B / B2 — the learners list (Figma 1986:74701).
 *
 * Columns per the Phase 1 capture: "Learner Name (photo, name, job title),
 * Learner ID, Courses Earned, Qualification (%), Last Certification Date,
 * Last Activity".
 *
 * Photo, name, ID, qualification % and last activity already existed on
 * GET admin/learners (D-075). Job title, Courses Earned and Last Certification Date did
 * not, and are added here rather than duplicating the whole endpoint into a
 * parallel /admin/learners list — the existing one already supports
 * `role=learner`, which 02-figma-map.md recorded as "unverified".
 */
class AdminLearnerListTest extends ApiTestCase
{
    private function learner(string $name, ?JobTitle $jobTitle = null): User
    {
        return User::factory()->create([
            'name'         => $name,
            'job_title_id' => $jobTitle?->id,
        ]);
    }

    private function enrol(User $user, bool $passed): Course
    {
        $course = Course::factory()->create();

        DB::table('users_courses')->insert([
            'user_id'    => $user->id,
            'course_id'  => $course->id,
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        if ($passed) {
            DB::table('user_exams')->insert([
                'user_id'    => $user->id,
                'course_id'  => $course->id,
                'exam_id'    => \App\Models\CourseExam::factory()->create(['course_id' => $course->id])->id,
                'status'     => 'passed',
                'created_at' => now()->subDays(4),
                'updated_at' => now()->subDays(4),
            ]);
        }

        return $course;
    }

    private function rowFor(array $headers, User $user): ?array
    {
        return collect(
            $this->getJson(self::BASE.'/admin/learners?per_page=100', $headers)
                ->assertOk()
                ->json('result')
        )->first(fn ($r) => $r['source'] === 'user' && $r['id'] === $user->id);
    }

    // ----------------------------------------------------------- new columns

    public function test_the_list_exposes_job_title_courses_earned_and_last_certification(): void
    {
        $jobTitle = JobTitle::query()->create(['name' => 'Technician', 'name_en' => 'Technician']);
        $learner  = $this->learner('Sara', $jobTitle);

        $this->enrol($learner, passed: true);
        $this->enrol($learner, passed: false);

        UserCertificate::factory()->create([
            'user_id'   => $learner->id,
            'status'    => 'active',
            'issued_at' => '2026-03-04 10:00:00',
        ]);

        ['headers' => $headers] = $this->adminToken();
        $row = $this->rowFor($headers, $learner);

        $this->assertNotNull($row, 'The learner should appear in the list.');
        $this->assertSame('Technician', $row['job_title']);
        $this->assertSame(1, $row['courses_earned']);
        $this->assertStringStartsWith('2026-03-04', (string) $row['last_certification_at']);
    }

    public function test_courses_earned_agrees_with_the_qualification_percentage(): void
    {
        $learner = $this->learner('Consistent');
        $this->enrol($learner, passed: true);
        $this->enrol($learner, passed: true);
        $this->enrol($learner, passed: false);
        $this->enrol($learner, passed: false);

        ['headers' => $headers] = $this->adminToken();
        $row = $this->rowFor($headers, $learner);

        // Both columns come from the same passed-course count, so they can
        // never disagree on screen: 2 earned of 4 enrolled = 50%.
        $this->assertSame(2, $row['courses_earned']);
        $this->assertSame(4, $row['enrolled_courses_count']);
        $this->assertSame(50, $row['compliance_pct']);
    }

    public function test_a_learner_with_no_data_reports_zero_and_null_not_missing_keys(): void
    {
        $learner = $this->learner('Fresh');
        ['headers' => $headers] = $this->adminToken();

        $row = $this->rowFor($headers, $learner);

        $this->assertArrayHasKey('job_title', $row);
        $this->assertArrayHasKey('last_certification_at', $row);
        $this->assertSame(0, $row['courses_earned']);
        $this->assertNull($row['job_title']);
        $this->assertNull($row['last_certification_at']);
    }

    public function test_only_the_most_recent_active_certificate_is_reported(): void
    {
        $learner = $this->learner('Certified');

        foreach ([['2026-01-01 00:00:00', 'active'], ['2026-06-01 00:00:00', 'active'], ['2026-09-01 00:00:00', 'revoked']] as [$date, $status]) {
            UserCertificate::factory()->create([
                'user_id'   => $learner->id,
                'status'    => $status,
                'issued_at' => $date,
            ]);
        }

        ['headers' => $headers] = $this->adminToken();
        $row = $this->rowFor($headers, $learner);

        // The revoked September one must not win.
        $this->assertStringStartsWith('2026-06-01', (string) $row['last_certification_at']);
    }

    public function test_dashboard_accounts_are_not_listed_as_learners(): void
    {
        // Dashboard accounts live in Users since D-075, where the learner
        // figures are null rather than fake zeroes.
        ['model' => $admin, 'headers' => $headers] = $this->adminToken();

        $rows = $this->getJson(self::BASE.'/admin/learners?per_page=100', $headers)->assertOk()->json('result');
        $this->assertNull(collect($rows)->first(fn ($r) => $r['source'] === 'admin' && $r['id'] === $admin->id));

        $account = collect($this->getJson(self::BASE.'/admin/users?per_page=100', $headers)->assertOk()->json('result'))
            ->firstWhere('id', $admin->id);
        $this->assertNull($account['job_title']);
        $this->assertNull($account['compliance_pct']);
        $this->assertNull($account['last_certification_at']);
    }

    // ----------------------------------------------------- B-21 / pagination

    public function test_per_page_is_now_bounded(): void
    {
        ['headers' => $headers] = $this->adminToken();

        $this->getJson(self::BASE.'/admin/learners?per_page=100000', $headers)->assertStatus(422);
        $this->getJson(self::BASE.'/admin/learners?per_page=0', $headers)->assertStatus(422);
        $this->getJson(self::BASE.'/admin/learners?per_page=50', $headers)->assertOk();
    }

    public function test_existing_filters_still_work_including_the_comma_string_form(): void
    {
        ['headers' => $headers] = $this->adminToken();

        // intArray() accepts a comma-separated string as well as an array;
        // the FormRequest must not have broken that contract.
        $this->getJson(self::BASE.'/admin/learners?instructor_ids=1,2,3', $headers)->assertOk();
        $this->getJson(self::BASE.'/admin/learners?instructor_ids[]=1&instructor_ids[]=2', $headers)->assertOk();
        $this->getJson(self::BASE.'/admin/learners?status=deactivated', $headers)->assertOk();
        $this->getJson(self::BASE.'/admin/learners?status=nonsense', $headers)->assertStatus(422);
    }

    // ------------------------------------------------------------------- N+1

    public function test_the_new_columns_do_not_introduce_a_query_per_row(): void
    {
        ['headers' => $headers] = $this->adminToken();

        $measure = function () use ($headers): int {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->getJson(self::BASE.'/admin/learners?per_page=50', $headers)->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $this->learner('One');
        $withOne = $measure();

        for ($i = 0; $i < 8; $i++) {
            $l = $this->learner("Extra{$i}");
            $this->enrol($l, passed: true);
        }
        $withNine = $measure();

        $this->assertLessThanOrEqual(
            2,
            $withNine - $withOne,
            "Query count grew from {$withOne} to {$withNine} when eight learners were added — that is an N+1.",
        );
    }
}
