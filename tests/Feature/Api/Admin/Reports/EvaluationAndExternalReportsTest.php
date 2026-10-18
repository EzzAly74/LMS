<?php

namespace Tests\Feature\Api\Admin\Reports;

use App\Models\Admin;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Evaluation;
use App\Models\EvaluationCategory;
use App\Models\QualificationSkill;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 8. Evaluation Summary, 8b. Evaluation Responses and 9. External Training
 * (D-112).
 */
class EvaluationAndExternalReportsTest extends ReportTestCase
{
    private EvaluationCategory $template;

    private Course $course;

    private CourseSection $cohort;

    private int $instructorId;

    /** @var array<string, Evaluation> */
    private array $questions = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->template = EvaluationCategory::query()->create(['name' => ['en' => 'Course Feedback', 'ar' => 'تقييم الدورة']]);
        $this->course = Course::factory()->create();
        $this->course->setTranslations('title', ['en' => 'Selling Skills', 'ar' => 'مهارات البيع'])->save();
        $this->cohort = CourseSection::factory()->create(['course_id' => $this->course->id]);
        $this->cohort->setTranslations('name', ['en' => 'Group one', 'ar' => 'المجموعة الأولى'])->save();
        $this->instructorId = $this->instructor()->id;
        foreach (['clarity' => ['five', 'Clarity of explanation'], 'examples' => ['five', 'Practical examples'],
            'recommend' => ['ten', 'Would you recommend the trainer?'], 'improve' => ['text', 'What to improve']] as $key => [$type, $title]) {
            $this->questions[$key] = Evaluation::query()->create([
                'evaluation_category_id' => $this->template->id, 'type' => $type, 'title' => ['en' => $title, 'ar' => $title], 'is_required' => true,
            ]);
        }
    }

    /** @param  array<string, string>  $answers  question key => answer */
    private function respond(User $user, array $answers, string $at = '2026-05-22 16:10:00'): void
    {
        foreach ($answers as $key => $answer) {
            $question = $this->questions[$key];
            DB::table('user_course_evaluations')->insert([
                'user_id' => $user->id, 'user_machine_code' => $user->machine_code, 'user_department' => $user->department_name,
                'course_id' => $this->course->id, 'course_name' => 'Selling Skills', 'instructor_id' => $this->instructorId,
                'instructor_name' => 'Ahmed Zidan', 'evaluation_category_id' => $this->template->id, 'evaluation_category_name' => 'Course Feedback',
                'evaluation_id' => $question->id, 'evaluation_title' => 'q', 'answer' => $answer,
                'evaluation_type' => ['five' => 5, 'ten' => 10, 'text' => 0][$question->type],
                'created_at' => $at, 'updated_at' => $at,
            ]);
        }
    }

    private function learner(string $name, string $department): User
    {
        $user = User::factory()->create(['name_ar' => $name, 'name_en' => $name, 'department_name' => $department, 'department_name_en' => $department]);
        DB::table('users_courses')->insert(['user_id' => $user->id, 'course_id' => $this->course->id, 'group_id' => $this->cohort->id, 'created_at' => now(), 'updated_at' => now()]);

        return $user;
    }

    public function test_the_summary_averages_each_question_per_course_group_and_instructor(): void
    {
        $a = $this->learner('A', 'Human Resources');
        $b = $this->learner('B', 'Sales');
        $this->learner('C', 'Sales');
        $this->learner('D', 'Sales');
        $this->respond($a, ['clarity' => '5', 'examples' => '4', 'recommend' => '10', 'improve' => 'More practice'], '2026-05-20 10:00:00');
        $this->respond($b, ['clarity' => '2', 'examples' => '4', 'recommend' => '6', 'improve' => ''], '2026-05-27 10:00:00');

        $result = $this->page('evaluation-summary', ['template_id' => $this->template->id])->assertOk()->json('result');
        $labels = array_column($result['columns'], 'label', 'key');
        $row = $result['rows'][0];
        $q = fn (string $key) => 'q_'.$this->questions[$key]->id;

        $this->assertSame('Avg Clarity of explanation', $labels[$q('clarity')]);
        $this->assertArrayNotHasKey($q('improve'), $labels);
        $this->assertSame(['Selling Skills', 'Ahmed Zidan', 'Group one', '2026-05-20 - 2026-05-27', 4, 2, 'Human Resources, Sales'],
            [$row['course_name'], $row['instructor'], $row['group_cohort'], $row['evaluation_period'], $row['enrolled'], $row['respondents'], $row['departments_covered']]);
        $this->assertEquals([50, 3.5, 4.0, 3.8, 8.0, 0], [$row['response_rate'], $row[$q('clarity')], $row[$q('examples')], $row['avg_overall_score'], $row['avg_recommendation'], $row['nps']]);
        $this->assertSame(['1 / 0 / 1', 1, 'Clarity of explanation'], [$row['nps_bands'], $row['comments_received'], $row['lowest_rated_question']]);
        $this->assertNull($row['avg_trainer_score']);
        $this->assertNull($row['course_code']);
        $this->assertCount(1, $result['rows']);
        $this->assertCount(0, $this->page('evaluation-summary', ['template_id' => $this->template->id, 'submitted_to' => '2026-05-01'])->json('result.rows'));
        $this->assertCount(1, $this->page('evaluation-summary', ['template_id' => $this->template->id, 'search' => 'selling'])->json('result.rows'));
        $this->assertCount(0, $this->page('evaluation-summary', ['template_id' => $this->template->id, 'search' => 'nothing'])->json('result.rows'));
    }

    public function test_the_response_detail_has_one_row_per_submission_with_every_answer(): void
    {
        $a = $this->learner('أحمد', 'الموارد البشرية');
        $this->respond($a, ['clarity' => '5', 'examples' => '4', 'recommend' => '9', 'improve' => 'More practice']);

        $result = $this->page('evaluation-responses', ['template_id' => $this->template->id])->assertOk()->json('result');
        $row = $result['rows'][0];
        $q = fn (string $key) => 'q_'.$this->questions[$key]->id;

        $this->assertSame(['response_id', 'submission_date', 'full_name_ar', 'employee_id', 'department', 'job_title', 'course_name', 'instructor', 'group_cohort'],
            array_slice(array_column($result['columns'], 'key'), 0, 9));
        $this->assertSame('What to improve', collect($result['columns'])->firstWhere('key', $q('improve'))['label']);
        $this->assertSame(['2026-05-22 16:10', 'أحمد', $a->machine_code, 'الموارد البشرية', 'Selling Skills', 'Group one'],
            [$row['submission_date'], $row['full_name_ar'], $row['employee_id'], $row['department'], $row['course_name'], $row['group_cohort']]);
        $this->assertEquals([5, 4, 9], [$row[$q('clarity')], $row[$q('examples')], $row[$q('recommend')]]);
        $this->assertSame('More practice', $row[$q('improve')]);
        $this->assertCount(1, $result['rows']);
        $this->assertSame(1, $result['total']);
        $this->assertSame(1, $this->page('evaluation-responses', ['template_id' => $this->template->id, 'search' => $a->machine_code])->json('result.total'));
        $this->assertSame(0, $this->page('evaluation-responses', ['template_id' => $this->template->id, 'search' => 'nobody'])->json('result.total'));

        $file = $this->csv('evaluation-responses', ['template_id' => $this->template->id]);
        $this->assertSame('Evaluation template = Course Feedback', $file['header']['Filters Applied']);
        $this->assertSame('More practice', $file['rows'][0]['What to improve']);
    }

    public function test_external_training_reports_each_request_with_its_decision(): void
    {
        $user = User::factory()->create(['name_en' => 'Sara Ali']);
        $approver = Admin::factory()->create(['name' => 'Ahmed Salem']);
        $qualification = QualificationSkill::factory()->create(['name' => ['en' => 'Talent Acquisition', 'ar' => 'استقطاب']]);
        $request = fn (array $values) => DB::table('external_training_requests')->insertGetId($values + [
            'user_id' => $user->id, 'title' => 'SHRM-CP Prep', 'provider' => 'SHRM', 'start_date' => '2026-04-05', 'end_date' => '2026-04-30',
            'hours' => 32, 'cost' => 12500, 'currency' => 'EGP', 'certificate_path' => 'external/x.pdf', 'certificate_name' => 'x.pdf',
            'certificate_mime' => 'application/pdf', 'certificate_size' => 100, 'created_at' => '2026-05-02 09:00:00', 'updated_at' => now(),
        ]);
        $approved = $request(['status' => 'approved', 'decided_by' => $approver->id, 'decided_at' => '2026-05-06 10:00:00', 'qualification_skill_id' => $qualification->id]);
        $request(['status' => 'rejected', 'decided_by' => $approver->id, 'decided_at' => '2026-05-07 10:00:00', 'rejection_reason' => 'No proof of attendance', 'provider' => 'Coursera']);
        $request(['status' => 'pending']);

        $rows = collect($this->rows('external-training'))->keyBy('claim_status');

        $a = $rows['Approved'];
        $this->assertSame([(string) $approved, 'SHRM-CP Prep', 'SHRM', '2026-04-05', '2026-04-30', 'EGP', 'Talent Acquisition', true, '2026-05-02', 'Ahmed Salem', '2026-05-06', null],
            [$a['claim_id'], $a['training_title'], $a['provider'], $a['training_start_date'], $a['training_end_date'], $a['currency'], $a['linked_qualification'], $a['attachment'], $a['submission_date'], $a['approver'], $a['decision_date'], $a['rejection_reason']]);
        $this->assertEquals([32, 32, 12500], [$a['hours_claimed'], $a['hours_approved'], $a['cost']]);
        $this->assertSame([null, 'No proof of attendance'], [$rows['Rejected']['hours_approved'], $rows['Rejected']['rejection_reason']]);
        $this->assertSame([null, null], [$rows['Pending']['approver'], $rows['Pending']['decision_date']]);
        $this->assertNull($a['training_type']);

        $this->assertSame(['Rejected'], array_column($this->rows('external-training', ['providers' => ['Coursera']]), 'claim_status'));
        $this->assertSame(['Pending'], array_column($this->rows('external-training', ['statuses' => ['pending']]), 'claim_status'));
        $this->assertCount(0, $this->rows('external-training', ['training_from' => '2026-06-01']));
        $providers = array_column($this->getJson(self::BASE.'/admin/reports/options/providers', $this->admin())->assertOk()->json('result'), 'id');
        $this->assertSame(['Coursera', 'SHRM'], $providers);
    }
}
