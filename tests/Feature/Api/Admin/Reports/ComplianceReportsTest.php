<?php

namespace Tests\Feature\Api\Admin\Reports;

use App\Models\Course;
use App\Models\JobTitle;
use App\Models\QualificationSkill;
use App\Models\User;
use App\Models\UserCertificate;
use Illuminate\Support\Facades\DB;

/**
 * 1. Compliance by Job Title and 2. Individual Compliance (D-112): the
 * shared qualification rule (D-056) and course completion (D-094).
 */
class ComplianceReportsTest extends ReportTestCase
{
    private JobTitle $job;

    private QualificationSkill $qualification;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();
        $this->job = JobTitle::query()->create(['name' => 'محلل', 'name_en' => 'Analyst', 'name_ar' => 'محلل']);
        $this->qualification = QualificationSkill::factory()->create(['name' => ['en' => 'Interviewing', 'ar' => 'المقابلات']]);
        $this->job->qualificationSkills()->attach($this->qualification->id);
        $this->course = Course::factory()->create();
        DB::table('course_qualification_skills')->insert(['course_id' => $this->course->id, 'qualification_skill_id' => $this->qualification->id]);
    }

    private function employee(array $attributes = []): User
    {
        return User::factory()->create($attributes + ['job_title_id' => $this->job->id, 'department_name' => 'الموارد البشرية', 'department_name_en' => 'Human Resources']);
    }

    private function enrol(User $user): void
    {
        DB::table('users_courses')->insert(['user_id' => $user->id, 'course_id' => $this->course->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_compliance_by_job_title_counts_holders_against_the_target(): void
    {
        $holder = $this->employee();
        $started = $this->employee();
        $this->employee();
        $this->enrol($holder);
        $this->enrol($started);
        UserCertificate::factory()->create(['user_id' => $holder->id, 'course_id' => $this->course->id]);
        // A leaver and a deactivated account are not employees any more.
        $this->employee(['resignation_date' => now()->subDay()->toDateString()]);
        $this->employee(['status' => 'deactivated']);

        $row = collect($this->rows('compliance-by-job-title'))->firstWhere('job_title', 'Analyst');

        $this->assertSame('Human Resources', $row['department']);
        $this->assertSame([3, 1, 1, 1, 1], [$row['total_employees'], $row['required_qualifications'], $row['fully_compliant'], $row['in_progress'], $row['not_started']]);
        $this->assertEquals(33.3, $row['compliance_pct']);
        $this->assertEquals(90, $row['target_pct']);
        $this->assertSame('At Risk', $row['compliance_status']);
        // Not stored, so not in the report (human 2026-10-18).
        $this->assertArrayNotHasKey('job_title_code', $row);
        $this->assertArrayNotHasKey('overdue', $row);
    }

    public function test_the_target_comes_from_platform_config(): void
    {
        $holder = $this->employee();
        UserCertificate::factory()->create(['user_id' => $holder->id, 'course_id' => $this->course->id]);
        DB::table('settings')->where('key', 'compliance_target')->update(['value' => '100']);

        $row = collect($this->rows('compliance-by-job-title'))->firstWhere('job_title', 'Analyst');

        $this->assertEquals(100, $row['target_pct']);
        $this->assertSame('On Track', $row['compliance_status']);
    }

    public function test_as_of_counts_only_what_had_happened_by_that_day(): void
    {
        $holder = $this->employee();
        UserCertificate::factory()->create(['user_id' => $holder->id, 'course_id' => $this->course->id, 'issued_at' => now()->subDays(3)]);

        $before = collect($this->rows('compliance-by-job-title', ['as_of' => now()->subDays(10)->toDateString()]))->firstWhere('job_title', 'Analyst');
        $after = collect($this->rows('compliance-by-job-title', ['as_of' => now()->toDateString()]))->firstWhere('job_title', 'Analyst');

        $this->assertSame(0, $before['fully_compliant']);
        $this->assertSame(1, $after['fully_compliant']);
    }

    public function test_job_title_and_department_filters_and_the_arabic_names(): void
    {
        $this->employee();
        $other = JobTitle::query()->create(['name' => 'مدير', 'name_en' => 'Manager']);
        User::factory()->create(['job_title_id' => $other->id, 'department_name' => 'المبيعات']);

        $this->assertSame(['Analyst'], array_column($this->rows('compliance-by-job-title', ['job_title_ids' => [$this->job->id]]), 'job_title'));
        $this->assertSame(['مدير'], array_column($this->rows('compliance-by-job-title', ['departments' => ['المبيعات']], 'ar'), 'job_title'));
        $this->assertSame('الموارد البشرية', collect($this->rows('compliance-by-job-title', [], 'ar'))->firstWhere('job_title', 'محلل')['department']);
        $this->assertSame(['Manager'], array_column($this->rows('compliance-by-job-title', ['search' => 'mana']), 'job_title'));
    }

    public function test_individual_compliance_lists_each_employee_qualification_with_status_and_dates(): void
    {
        $holder = $this->employee(['name_en' => 'Sara Ali', 'name_ar' => 'سارة علي', 'hire_date' => '2021-03-12', 'location_name_en' => 'Cairo HQ', 'hr_employee_status' => 0]);
        $started = $this->employee(['name_en' => 'Omar Adel']);
        $idle = $this->employee(['name_en' => 'Mona Fady']);
        $this->enrol($holder);
        $this->enrol($started);
        UserCertificate::factory()->create(['user_id' => $holder->id, 'course_id' => $this->course->id, 'issued_at' => '2026-05-20 10:00:00']);
        // A qualification granted directly (approved external training) is listed too.
        $granted = QualificationSkill::factory()->create(['name' => ['en' => 'First Aid', 'ar' => 'الإسعافات']]);
        DB::table('user_qualification_skill')->insert(['user_id' => $idle->id, 'qualification_skill_id' => $granted->id, 'assigned_at' => '2026-04-01 09:00:00', 'created_at' => now(), 'updated_at' => now()]);

        $rows = collect($this->rows('individual-compliance'));
        $sara = $rows->firstWhere('full_name_en', 'Sara Ali');

        $this->assertSame(['Sara Ali', 'سارة علي', 'Analyst', 'Human Resources', 'Cairo HQ', 'Active', '2021-03-12'],
            [$sara['full_name_en'], $sara['full_name_ar'], $sara['job_title'], $sara['department'], $sara['location'], $sara['employment_status'], $sara['hire_date']]);
        $this->assertSame(['Interviewing', 1, 1, 'Complete', '2026-05-20'],
            [$sara['qualification_name'], $sara['required_courses'], $sara['completed_courses'], $sara['qualification_status'], $sara['completion_date']]);
        $this->assertEquals(100, $sara['completion_pct']);
        $this->assertNotNull($sara['assigned_date']);
        $this->assertArrayNotHasKey('line_manager', $sara);
        $this->assertSame('In Progress', $rows->firstWhere('full_name_en', 'Omar Adel')['qualification_status']);
        $mona = $rows->where('full_name_en', 'Mona Fady')->keyBy('qualification_name');
        $this->assertSame('Not Started', $mona['Interviewing']['qualification_status']);
        $this->assertSame(['Complete', '2026-04-01', '2026-04-01'], [$mona['First Aid']['qualification_status'], $mona['First Aid']['assigned_date'], $mona['First Aid']['completion_date']]);
        $this->assertCount(4, $rows);
    }

    public function test_individual_compliance_status_filter_matches_the_shown_status(): void
    {
        $holder = $this->employee(['name_en' => 'Sara Ali']);
        $started = $this->employee(['name_en' => 'Omar Adel']);
        $this->employee(['name_en' => 'Mona Fady']);
        $this->enrol($holder);
        $this->enrol($started);
        UserCertificate::factory()->create(['user_id' => $holder->id, 'course_id' => $this->course->id]);

        foreach (['complete' => 'Sara Ali', 'in_progress' => 'Omar Adel', 'not_started' => 'Mona Fady'] as $status => $name) {
            $this->assertSame([$name], array_column($this->rows('individual-compliance', ['statuses' => [$status]]), 'full_name_en'), $status);
        }
        $this->assertCount(2, $this->rows('individual-compliance', ['statuses' => ['complete', 'not_started']]));
    }

    public function test_individual_compliance_search_and_employee_filter(): void
    {
        $sara = $this->employee(['name_en' => 'Sara Ali', 'machine_code' => 'EMP-10245']);
        $this->employee(['name_en' => 'Omar Adel']);

        $this->assertSame(['Sara Ali'], array_column($this->rows('individual-compliance', ['search' => '10245']), 'full_name_en'));
        $this->assertSame(['Sara Ali'], array_column($this->rows('individual-compliance', ['employee_ids' => [$sara->id]]), 'full_name_en'));
    }

    public function test_employment_status_reads_terminated_after_the_resignation_date_and_blank_for_unknown_codes(): void
    {
        $this->employee(['name_en' => 'Left', 'hr_employee_status' => 0, 'resignation_date' => now()->subDay()->toDateString()]);
        $this->employee(['name_en' => 'Leaving', 'hr_employee_status' => 0, 'resignation_date' => now()->addMonth()->toDateString()]);
        $this->employee(['name_en' => 'Unknown', 'hr_employee_status' => 7]);

        $rows = collect($this->rows('individual-compliance'))->keyBy('full_name_en');

        $this->assertSame('Terminated', $rows['Left']['employment_status']);
        $this->assertSame('Active', $rows['Leaving']['employment_status']);
        $this->assertNull($rows['Unknown']['employment_status']);
    }
}
