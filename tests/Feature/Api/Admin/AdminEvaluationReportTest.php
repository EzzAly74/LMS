<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Admin;
use App\Models\Course;
use App\Models\Evaluation;
use App\Models\EvaluationCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Api\ApiTestCase;

/**
 * Stage B / B3 — admin evaluation reporting.
 *
 * Covers Figma 2266:128869 (course histogram), 2169:108198 (per-question
 * distribution), 2017:52260 (learner scores list) and 2169:108801 / 2169:109264
 * (one learner's submission, passing and failing).
 *
 * FG-12 note: the design shows "/105" on one screen and "4.3/5.0" on another.
 * These tests assert the API returns total, max_total and a normalised ratio
 * and does NOT pick a scale — that remains the designer's call.
 */
class AdminEvaluationReportTest extends ApiTestCase
{
    private EvaluationCategory $template;

    private Course $course;

    /** user_course_evaluations.instructor_id is NOT NULL with no default. */
    private int $instructorId = 1;

    /** A real learner, so route-model binding resolves before the authz check. */
    private User $someLearner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->template = EvaluationCategory::query()->create(['name' => 'Course Feedback '.uniqid()]);
        $this->course      = Course::factory()->create();
        $this->someLearner = User::factory()->create();
    }

    private function question(string $type, string $title): Evaluation
    {
        return Evaluation::query()->create([
            'evaluation_category_id' => $this->template->id,
            'type'                   => $type,
            'title'                  => $title,
            'is_required'            => true,
        ]);
    }

    /** One denormalised answer row, as the app writes them. */
    private function answer(User $user, Evaluation $question, string $answer, int $scale): void
    {
        DB::table('user_course_evaluations')->insert([
            'user_id'                  => $user->id,
            'user_machine_code'        => $user->machine_code,
            'user_department'          => $user->department_name,
            'course_id'                => $this->course->id,
            'course_name'              => $this->course->title,
            'instructor_id'            => $this->instructorId,
            'instructor_name'          => 'Instructor',
            'evaluation_category_id'   => $this->template->id,
            'evaluation_category_name' => $this->template->name,
            'evaluation_id'            => $question->id,
            'evaluation_title'         => $question->title,
            'evaluation_type'          => $scale,
            'answer'                   => $answer,
            'created_at'               => now()->subDay(),
            'updated_at'               => now()->subDay(),
        ]);
    }

    /** A second course, for scoping tests. */
    private function otherCourse(): Course
    {
        return Course::factory()->create();
    }

    /** One answer on a given course (answer() always writes to $this->course). */
    private function answerOn(Course $course, User $user, Evaluation $question, string $answer, int $scale): void
    {
        $previous     = $this->course;
        $this->course = $course;
        $this->answer($user, $question, $answer, $scale);
        $this->course = $previous;
    }

    // ------------------------------------------------- course summary (2266:128869)

    /**
     * Built on evaluation responses, not course_ratings (Q-050): one review per
     * (learner, template), the /5 score of each review rounded into a star
     * bucket, comments = reviews with a written answer.
     */
    public function test_the_course_summary_is_built_from_evaluation_submissions(): void
    {
        $stars = $this->question('five', 'Overall');
        $scale = $this->question('ten', 'Pace');
        $text  = $this->question('text', 'Anything else?');

        [$a, $b, $c] = [User::factory()->create(), User::factory()->create(), User::factory()->create()];

        // a: 5/5 and 10/10 -> 5.0, with a comment
        $this->answer($a, $stars, '5', 5);
        $this->answer($a, $scale, '10', 10);
        $this->answer($a, $text, 'Great course', 0);
        // b: 5/5 and 8/10 -> (1 + 0.8) / 2 * 5 = 4.5 -> rounds to 5 stars; blank text is not a comment
        $this->answer($b, $stars, '5', 5);
        $this->answer($b, $scale, '8', 10);
        $this->answer($b, $text, '   ', 0);
        // c: 3/5 and 6/10 -> 3.0
        $this->answer($c, $stars, '3', 5);
        $this->answer($c, $scale, '6', 10);

        // Legacy ratings must not leak in.
        DB::table('course_ratings')->insert([
            'user_id' => $c->id, 'course_id' => $this->course->id, 'rating' => 1,
            'comment' => 'legacy', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Another course's answers must not either.
        $this->answerOn($this->otherCourse(), $c, $stars, '1', 5);

        ['headers' => $headers] = $this->adminToken();

        $result = $this->getJson(self::BASE."/admin/courses/{$this->course->id}/evaluation-summary", $headers)
            ->assertOk()->json('result');

        $this->assertSame(3, $result['reviews']);
        $this->assertSame(1, $result['with_comments']);
        $this->assertSame(3, $result['learners_scored']);
        // Mean of every scaled answer normalised: (1+1+1+0.8+0.6+0.6)/6*5 = 4.2
        $this->assertEqualsWithDelta(4.2, $result['average'], 0.001);
        $this->assertSame(5, $result['scale_max']);

        // 5 down to 1, gaps filled, so the UI never reconstructs buckets.
        $this->assertSame([5, 4, 3, 2, 1], array_column($result['distribution'], 'value'));
        $this->assertSame([2, 0, 1, 0, 0], array_column($result['distribution'], 'count'));

        $this->assertCount(1, $result['templates']);
        $this->assertSame($this->template->id, $result['templates'][0]['id']);
        $this->assertSame(3, $result['templates'][0]['submissions']);
        $this->assertSame(3, $result['templates'][0]['questions']);
        $this->assertTrue($result['templates'][0]['all_courses']);
    }

    /** The summary average is the header's score, so the two can never disagree. */
    public function test_the_summary_average_equals_the_course_header_score(): void
    {
        $stars = $this->question('five', 'Overall');
        $this->answer(User::factory()->create(), $stars, '4', 5);
        $this->answer(User::factory()->create(), $stars, '3', 5);

        ['headers' => $headers] = $this->adminToken();

        $summary = $this->getJson(self::BASE."/admin/courses/{$this->course->id}/evaluation-summary", $headers)
            ->assertOk()->json('result.average');
        $header = $this->getJson(self::BASE."/courses/{$this->course->id}", $headers)
            ->assertOk()->json('result.evaluation_score');

        $this->assertEquals($header, $summary);
    }

    public function test_a_course_with_no_evaluations_reports_null_average_not_zero(): void
    {
        // A template scoped to this course is offered even before anyone answers.
        $scoped = EvaluationCategory::query()->create(['name' => 'Scoped', 'course_id' => $this->course->id]);

        ['headers' => $headers] = $this->adminToken();

        $result = $this->getJson(self::BASE."/admin/courses/{$this->course->id}/evaluation-summary", $headers)
            ->assertOk()->json('result');

        $this->assertSame(0, $result['reviews']);
        // Null, not 0 - a course nobody evaluated has no average, and 0 would
        // read as "scored zero by everyone".
        $this->assertNull($result['average']);
        $this->assertSame([0, 0, 0, 0, 0], array_column($result['distribution'], 'count'));
        $this->assertSame([$scoped->id], array_column($result['templates'], 'id'));
        $this->assertFalse($result['templates'][0]['all_courses']);
    }

    // ------------------------------- template results scoped to a course (2266:130142)

    public function test_template_results_can_be_scoped_to_one_course(): void
    {
        $stars = $this->question('five', 'Overall');
        $other = $this->otherCourse();

        $this->answer(User::factory()->create(), $stars, '5', 5);
        $this->answerOn($other, User::factory()->create(), $stars, '1', 5);
        $this->answerOn($other, User::factory()->create(), $stars, '1', 5);

        ['headers' => $headers] = $this->adminToken();
        $url = self::BASE."/admin/evaluations/{$this->template->id}/results";

        $all = $this->getJson($url, $headers)->assertOk()->json('result');
        $this->assertSame(3, $all['questions'][0]['responses']);

        $scoped = $this->getJson($url.'?course_id='.$this->course->id, $headers)->assertOk()->json('result');
        $this->assertSame(1, $scoped['questions'][0]['responses']);
        $this->assertSame(1, $scoped['summary']['learners_scored']);
        $this->assertEqualsWithDelta(5.0, $scoped['summary']['score'], 0.001);
        $this->assertSame([1, 0, 0, 0, 0], array_column($scoped['questions'][0]['distribution'], 'count'));
        // Editing lock is about the template, not the course view.
        $this->assertTrue($scoped['template']['locked']);

        $this->getJson($url.'?course_id=999999', $headers)->assertStatus(422);
    }

    // ---------------------------------------------- template results (2169:108198)

    public function test_template_results_report_per_question_distribution_and_average(): void
    {
        $q = $this->question('five', 'Was the content useful?');

        foreach ([5, 5, 5, 4, 1] as $value) {
            $this->answer(User::factory()->create(), $q, (string) $value, 5);
        }

        ['headers' => $headers] = $this->adminToken();

        $result = $this->getJson(self::BASE."/admin/evaluations/{$this->template->id}/results", $headers)
            ->assertOk()->json('result');

        $this->assertSame(1, $result['template']['questions']);

        $question = $result['questions'][0];
        $this->assertSame('five', $question['type']);
        $this->assertSame(5, $question['scale_max']);
        $this->assertSame(5, $question['responses']);
        $this->assertEqualsWithDelta(4.0, $question['average'], 0.05);
        $this->assertSame([3, 1, 0, 0, 1], array_column($question['distribution'], 'count'));
    }

    public function test_a_ten_point_question_uses_a_ten_point_scale(): void
    {
        $q = $this->question('ten', 'How likely are you to recommend it?');
        $this->answer(User::factory()->create(), $q, '9', 10);
        $this->answer(User::factory()->create(), $q, '7', 10);

        ['headers' => $headers] = $this->adminToken();

        $question = $this->getJson(self::BASE."/admin/evaluations/{$this->template->id}/results", $headers)
            ->assertOk()->json('result.questions.0');

        $this->assertSame(10, $question['scale_max']);
        $this->assertCount(10, $question['distribution']);
        $this->assertEqualsWithDelta(8.0, $question['average'], 0.05);
    }

    public function test_a_text_question_is_counted_but_never_averaged(): void
    {
        $q = $this->question('text', 'Anything else?');
        $this->answer(User::factory()->create(), $q, 'The room was cold', 0);
        $this->answer(User::factory()->create(), $q, 'More examples please', 0);

        ['headers' => $headers] = $this->adminToken();

        $question = $this->getJson(self::BASE."/admin/evaluations/{$this->template->id}/results", $headers)
            ->assertOk()->json('result.questions.0');

        $this->assertSame(2, $question['responses']);
        // Averaging prose would be meaningless; bucketing it would be invented.
        $this->assertNull($question['average']);
        $this->assertNull($question['distribution']);
    }

    public function test_a_question_with_no_answers_reports_zero_responses(): void
    {
        $this->question('five', 'Unanswered');
        ['headers' => $headers] = $this->adminToken();

        $question = $this->getJson(self::BASE."/admin/evaluations/{$this->template->id}/results", $headers)
            ->assertOk()->json('result.questions.0');

        $this->assertSame(0, $question['responses']);
        $this->assertNull($question['average']);
        $this->assertSame([0, 0, 0, 0, 0], array_column($question['distribution'], 'count'));
    }

    // ------------------------------------------------- scores list (2017:52260)

    public function test_the_scores_list_returns_one_row_per_submission(): void
    {
        $q1 = $this->question('five', 'Q1');
        $q2 = $this->question('five', 'Q2');

        $learner = User::factory()->create();
        $this->answer($learner, $q1, '5', 5);
        $this->answer($learner, $q2, '3', 5);

        ['headers' => $headers] = $this->adminToken();

        $rows = $this->getJson(self::BASE.'/admin/evaluations/scores', $headers)->assertOk()->json('result');

        // Two answers, one submission.
        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows[0]['answers_count']);
        $this->assertEqualsWithDelta(8.0, $rows[0]['total'], 0.01);
        $this->assertEqualsWithDelta(10.0, $rows[0]['max_total'], 0.01);
        $this->assertEqualsWithDelta(0.8, $rows[0]['ratio'], 0.001);
        $this->assertSame($learner->id, $rows[0]['learner']['id']);
    }

    public function test_free_text_answers_do_not_inflate_the_score(): void
    {
        $scale = $this->question('five', 'Rate it');
        $text  = $this->question('text', 'Comments');

        $learner = User::factory()->create();
        $this->answer($learner, $scale, '4', 5);
        $this->answer($learner, $text, 'some prose', 0);

        ['headers' => $headers] = $this->adminToken();
        $row = $this->getJson(self::BASE.'/admin/evaluations/scores', $headers)->assertOk()->json('result.0');

        $this->assertSame(2, $row['answers_count']);
        // Only the scale question contributes to total and max.
        $this->assertEqualsWithDelta(4.0, $row['total'], 0.01);
        $this->assertEqualsWithDelta(5.0, $row['max_total'], 0.01);
    }

    public function test_the_scores_list_can_be_filtered_by_course_and_template(): void
    {
        $q = $this->question('five', 'Q');
        $this->answer(User::factory()->create(), $q, '5', 5);

        ['headers' => $headers] = $this->adminToken();

        $this->assertCount(1, $this->getJson(
            self::BASE.'/admin/evaluations/scores?course_id='.$this->course->id, $headers
        )->assertOk()->json('result'));

        $other = Course::factory()->create();
        $this->assertCount(0, $this->getJson(
            self::BASE.'/admin/evaluations/scores?course_id='.$other->id, $headers
        )->assertOk()->json('result'));
    }

    public function test_unknown_filters_are_rejected_rather_than_silently_empty(): void
    {
        ['headers' => $headers] = $this->adminToken();

        $this->getJson(self::BASE.'/admin/evaluations/scores?course_id=999999', $headers)->assertStatus(422);
        $this->getJson(self::BASE.'/admin/evaluations/scores?template_id=999999', $headers)->assertStatus(422);
    }

    public function test_the_scores_list_bounds_per_page(): void
    {
        ['headers' => $headers] = $this->adminToken();

        $this->getJson(self::BASE.'/admin/evaluations/scores?per_page=100000', $headers)->assertStatus(422);
        $this->getJson(self::BASE.'/admin/evaluations/scores?per_page=0', $headers)->assertStatus(422);
        $this->getJson(self::BASE.'/admin/evaluations/scores?per_page=20', $headers)->assertOk();
    }

    // --------------------------------------- one submission (2169:108801 / 109264)

    public function test_a_single_submission_returns_every_answer(): void
    {
        $q1 = $this->question('five', 'Q1');
        $q2 = $this->question('text', 'Q2');

        $learner = User::factory()->create();
        $this->answer($learner, $q1, '4', 5);
        $this->answer($learner, $q2, 'good session', 0);

        ['headers' => $headers] = $this->adminToken();

        $result = $this->getJson(
            self::BASE."/admin/evaluations/scores/{$learner->id}/{$this->course->id}", $headers
        )->assertOk()->json('result');

        $this->assertSame($learner->id, $result['learner']['id']);
        $this->assertCount(2, $result['answers']);
        $this->assertEqualsWithDelta(4.0, $result['total'], 0.01);
        $this->assertEqualsWithDelta(5.0, $result['max_total'], 0.01);
        $this->assertEqualsWithDelta(0.8, $result['ratio'], 0.001);

        $text = collect($result['answers'])->firstWhere('is_text', true);
        $this->assertSame('good session', $text['answer']);
        $this->assertNull($text['scale_max']);
    }

    public function test_the_submission_totals_match_the_list_row(): void
    {
        $q = $this->question('five', 'Q');
        $learner = User::factory()->create();
        $this->answer($learner, $q, '2', 5);

        ['headers' => $headers] = $this->adminToken();

        $row = $this->getJson(self::BASE.'/admin/evaluations/scores', $headers)->assertOk()->json('result.0');
        $detail = $this->getJson(
            self::BASE."/admin/evaluations/scores/{$learner->id}/{$this->course->id}", $headers
        )->assertOk()->json('result');

        // The list and the detail must never disagree about the same submission.
        $this->assertEqualsWithDelta($row['total'], $detail['total'], 0.01);
        $this->assertEqualsWithDelta($row['max_total'], $detail['max_total'], 0.01);
        $this->assertEqualsWithDelta($row['ratio'], $detail['ratio'], 0.001);
    }

    public function test_a_learner_who_never_submitted_is_404(): void
    {
        $learner = User::factory()->create();
        ['headers' => $headers] = $this->adminToken();

        $this->getJson(
            self::BASE."/admin/evaluations/scores/{$learner->id}/{$this->course->id}", $headers
        )->assertStatus(404);
    }

    // ----------------------------------------------------------------- authz

    public function test_an_admin_without_view_evaluations_is_refused(): void
    {
        $role = Role::findOrCreate('eval-restricted', 'admin');
        $role->givePermissionTo(Permission::findOrCreate('view-dashboard', 'admin'));
        $admin = Admin::factory()->create();
        $admin->assignRole($role);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $headers = ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken];

        foreach ($this->allUrls() as $url) {
            $this->getJson($url, $headers)->assertStatus(403, "Expected 403 on {$url}");
        }
    }

    public function test_a_learner_cannot_read_evaluation_reports(): void
    {
        ['headers' => $headers] = $this->userToken();

        foreach ($this->allUrls() as $url) {
            $this->getJson($url, $headers)->assertStatus(403, "Expected 403 on {$url}");
        }
    }

    public function test_a_guest_is_unauthenticated(): void
    {
        foreach ($this->allUrls() as $url) {
            $this->getJson($url)->assertStatus(401, "Expected 401 on {$url}");
        }
    }

    /** @return list<string> */
    private function allUrls(): array
    {
        return [
            self::BASE."/admin/courses/{$this->course->id}/evaluation-summary",
            self::BASE."/admin/evaluations/{$this->template->id}/results",
            self::BASE.'/admin/evaluations/scores',
            self::BASE."/admin/evaluations/scores/{$this->someLearner->id}/{$this->course->id}",
            self::BASE.'/admin/evaluations/templates',
            self::BASE.'/admin/evaluations/filter-options',
            self::BASE.'/admin/evaluations/learner-options',
        ];
    }

    // ------------------------------------------------------------------- N+1

    public function test_template_results_do_not_query_per_question(): void
    {
        ['headers' => $headers] = $this->adminToken();
        $url = self::BASE."/admin/evaluations/{$this->template->id}/results";

        $measure = function () use ($url, $headers): int {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->getJson($url, $headers)->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $this->question('five', 'Q1');
        $withOne = $measure();

        for ($i = 0; $i < 6; $i++) {
            $this->question('five', "Extra {$i}");
        }
        $withSeven = $measure();

        $this->assertLessThanOrEqual(
            1,
            $withSeven - $withOne,
            "Query count grew from {$withOne} to {$withSeven} when six questions were added — that is an N+1.",
        );
    }
}
