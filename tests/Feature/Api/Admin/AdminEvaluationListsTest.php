<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Course;
use App\Models\Evaluation;
use App\Models\EvaluationCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\ApiTestCase;

/**
 * D4 additions to the B3 evaluation API: the templates list (Figma 2009:88432),
 * the /5 score with its pass verdict (D-054), localised names, the chip filters
 * and their option lists.
 */
class AdminEvaluationListsTest extends ApiTestCase
{
    private int $instructorId = 1;

    private function template(array|string $name): EvaluationCategory
    {
        return EvaluationCategory::query()->create(['name' => $name]);
    }

    private function question(EvaluationCategory $t, string $type, array|string $title = 'Q'): Evaluation
    {
        return Evaluation::query()->create([
            'evaluation_category_id' => $t->id,
            'type'                   => $type,
            'title'                  => $title,
            'is_required'            => true,
        ]);
    }

    /** Explicit array titles: CourseFactory's own title is double-encoded (B-108). */
    private function course(string $en = 'Leadership', string $ar = 'القيادة', bool $evaluable = true): Course
    {
        $course = Course::factory()->create(['title' => ['en' => $en, 'ar' => $ar]]);
        DB::table('courses')->where('id', $course->id)->update(['is_evaluate' => $evaluable ? 1 : 0]);

        return $course;
    }

    /** One denormalised answer row, written the way UserCourseEvaluationService does. */
    private function answer(User $user, Course $course, Evaluation $q, string $answer, array $extra = []): void
    {
        $scale = ['five' => 5, 'ten' => 10][$q->type] ?? 0;

        DB::table('user_course_evaluations')->insert(array_merge([
            'user_id'                  => $user->id,
            'user_machine_code'        => 'EMP-'.$user->id,
            'user_department'          => 'Ops',
            'course_id'                => $course->id,
            // The snapshot is in the learner's locale at the time - Arabic here,
            // so a test reading English proves the live row is used.
            'course_name'              => 'لقطة',
            'instructor_id'            => $this->instructorId,
            'instructor_name'          => 'Snapshot Instructor',
            'evaluation_category_id'   => $q->evaluation_category_id,
            'evaluation_category_name' => 'لقطة',
            'evaluation_id'            => $q->id,
            'evaluation_title'         => 'لقطة',
            'evaluation_type'          => $scale,
            'answer'                   => $answer,
            'created_at'               => now()->subDay(),
            'updated_at'               => now()->subDay(),
        ], $extra));
    }

    private function fetch(string $path, array $headers): array
    {
        return $this->getJson(self::BASE.$path, $headers + ['Accept-Language' => 'en'])->assertOk()->json();
    }

    // ───────────────────────────────────────────── templates list (2009:88432)

    public function test_the_templates_list_aggregates_each_template(): void
    {
        $t      = $this->template(['en' => 'Course Feedback', 'ar' => 'تقييم الدورة']);
        $five   = $this->question($t, 'five');
        $ten    = $this->question($t, 'ten');
        $this->question($t, 'text');
        $course = $this->course();
        $a      = User::factory()->create();
        $b      = User::factory()->create();

        // a: 4/5 and 5/10 -> (0.8 + 0.5) / 2 * 5 = 3.25; b: 5/5 -> 1.0 * 5.
        // Template mean over the three scaled answers: (0.8 + 0.5 + 1.0) / 3 * 5 = 3.8333.
        $this->answer($a, $course, $five, '4');
        $this->answer($a, $course, $ten, '5');
        $this->answer($b, $course, $five, '5');
        DB::table('users_courses')->insert([
            ['user_id' => $a->id, 'course_id' => $course->id],
            ['user_id' => $b->id, 'course_id' => $course->id],
            ['user_id' => User::factory()->create()->id, 'course_id' => $course->id],
        ]);

        ['headers' => $headers] = $this->adminToken();
        $body = $this->fetch('/admin/evaluations/templates', $headers);
        $row  = collect($body['result'])->firstWhere('id', $t->id);

        $this->assertSame('Course Feedback', $row['name']);
        $this->assertSame(3, $row['questions']);
        $this->assertSame(2, $row['submissions']);
        $this->assertSame(2, $row['learners_scored']);
        // Enrolled in the one evaluable course: a, b and one who has not answered.
        $this->assertSame(3, $row['learners_eligible']);
        $this->assertEqualsWithDelta(3.8, $row['score'], 0.001);
        $this->assertTrue($row['passed']);
        $this->assertNotNull($row['last_scored_at']);
        $this->assertSame(1, $body['meta']['total']);
    }

    public function test_a_template_nobody_answered_is_unscored_not_zero(): void
    {
        $t = $this->template('Never Used');
        $this->question($t, 'five');

        ['headers' => $headers] = $this->adminToken();
        $row = $this->fetch('/admin/evaluations/templates', $headers)['result'][0];

        $this->assertNull($row['score']);
        $this->assertNull($row['passed']);
        $this->assertSame(0, $row['submissions']);
        $this->assertNull($row['last_scored_at']);
    }

    public function test_the_templates_list_names_follow_the_request_locale(): void
    {
        $this->template(['en' => 'Course Feedback', 'ar' => 'تقييم الدورة']);
        ['headers' => $headers] = $this->adminToken();

        $ar = $this->getJson(self::BASE.'/admin/evaluations/templates', $headers + ['Accept-Language' => 'ar'])
            ->assertOk()->json('result.0.name');

        $this->assertSame('تقييم الدورة', $ar);
    }

    public function test_the_templates_list_filters_by_result(): void
    {
        $course = $this->course();
        $user   = User::factory()->create();

        $good = $this->template('Good');
        $this->answer($user, $course, $this->question($good, 'five'), '5');
        $poor = $this->template('Poor');
        $this->answer($user, $course, $this->question($poor, 'five'), '1');
        $none = $this->template('None');
        $this->question($none, 'five');

        ['headers' => $headers] = $this->adminToken();
        $names = fn (string $q) => collect($this->fetch('/admin/evaluations/templates?'.$q, $headers)['result'])
            ->pluck('name')->sort()->values()->all();

        $this->assertSame(['Good'], $names('results[]=passed'));
        $this->assertSame(['Poor'], $names('results[]=failed'));
        $this->assertSame(['None'], $names('results[]=unscored'));
        $this->assertSame(['Good', 'None'], $names('results[]=passed&results[]=unscored'));
    }

    public function test_a_score_that_rounds_up_to_the_threshold_passes_everywhere(): void
    {
        // 19 answers of 3/5 and one of 2/5: mean 0.59 * 5 = 2.95, shown as 3.0.
        // The verdict, the result filter and the detail must all read 3.0 as a
        // pass - none may compare the unrounded 2.95.
        $t      = $this->template('Boundary');
        $course = $this->course();
        $user   = User::factory()->create();
        for ($i = 0; $i < 20; $i++) {
            $this->answer($user, $course, $this->question($t, 'five', "Q{$i}"), $i === 0 ? '2' : '3');
        }

        ['headers' => $headers] = $this->adminToken();

        $row = $this->fetch('/admin/evaluations/templates?results[]=passed', $headers)['result'][0];
        $this->assertEqualsWithDelta(3.0, $row['score'], 0.001);
        $this->assertTrue($row['passed']);
        $this->assertSame([], $this->fetch('/admin/evaluations/templates?results[]=failed', $headers)['result']);

        $score = $this->fetch('/admin/evaluations/scores?results[]=passed', $headers)['result'][0];
        $this->assertEqualsWithDelta(3.0, $score['score'], 0.001);
        $this->assertTrue($score['passed']);

        $detail = $this->fetch("/admin/evaluations/scores/{$user->id}/{$course->id}?template_id={$t->id}", $headers)['result'];
        $this->assertEqualsWithDelta(3.0, $detail['score'], 0.001);
        $this->assertTrue($detail['passed']);
        $this->assertEqualsWithDelta(3.0, $detail['pass_threshold'], 0.001);
        $this->assertSame(5, $detail['score_max']);
    }

    public function test_a_course_filter_narrows_the_responses_and_hides_unused_templates(): void
    {
        $leadership = $this->course('Leadership');
        $safety     = $this->course('Safety');
        $user       = User::factory()->create();

        $shared = $this->template('Shared');
        $q      = $this->question($shared, 'five');
        $this->answer($user, $leadership, $q, '5');
        $this->answer($user, $safety, $q, '1');
        $other = $this->template('Only Safety');
        $this->answer($user, $safety, $this->question($other, 'five'), '4');

        ['headers' => $headers] = $this->adminToken();
        $rows = $this->fetch("/admin/evaluations/templates?course_ids[]={$leadership->id}", $headers)['result'];

        $this->assertSame(['Shared'], array_column($rows, 'name'));
        // Only the Leadership answer (5/5) counts.
        $this->assertEqualsWithDelta(5.0, $rows[0]['score'], 0.001);
        $this->assertSame(1, $rows[0]['submissions']);
    }

    public function test_the_templates_list_search_treats_wildcards_literally(): void
    {
        $this->template('Survey');
        $this->template('100% Feedback');
        ['headers' => $headers] = $this->adminToken();

        $rows = $this->fetch('/admin/evaluations/templates?search='.urlencode('%'), $headers)['result'];

        $this->assertSame(['100% Feedback'], array_column($rows, 'name'));
    }

    public function test_the_templates_list_sorts_by_localised_name(): void
    {
        $this->template(['en' => 'Beta', 'ar' => 'أ']);
        $this->template(['en' => 'Alpha', 'ar' => 'ب']);
        ['headers' => $headers] = $this->adminToken();

        $en = array_column($this->fetch('/admin/evaluations/templates?sort=name&dir=asc', $headers)['result'], 'name');
        $this->assertSame(['Alpha', 'Beta'], $en);

        $ar = $this->getJson(self::BASE.'/admin/evaluations/templates?sort=name&dir=asc', $headers + ['Accept-Language' => 'ar'])
            ->assertOk()->json('result.*.name');
        $this->assertSame(['أ', 'ب'], $ar);
    }

    public function test_the_templates_list_filters_by_last_response_date(): void
    {
        $course = $this->course();
        $user   = User::factory()->create();
        $old    = $this->template('Old');
        $this->answer($user, $course, $this->question($old, 'five'), '4', ['created_at' => '2026-01-10 12:00:00']);
        $recent = $this->template('Recent');
        $this->answer($user, $course, $this->question($recent, 'five'), '4', ['created_at' => '2026-03-10 23:30:00']);

        ['headers' => $headers] = $this->adminToken();
        $rows = $this->fetch('/admin/evaluations/templates?scored_from=2026-03-01&scored_to=2026-03-10', $headers)['result'];

        // scored_to is inclusive of the whole day.
        $this->assertSame(['Recent'], array_column($rows, 'name'));
    }

    public function test_the_templates_list_rejects_unknown_sorts_and_bad_ranges(): void
    {
        ['headers' => $headers] = $this->adminToken();

        foreach ([
            'sort=password',
            'dir=sideways',
            'per_page=101',
            'results[]=excellent',
            'scored_from=2026-03-10&scored_to=2026-03-01',
            'scored_from=10/03/2026',
        ] as $query) {
            $this->getJson(self::BASE.'/admin/evaluations/templates?'.$query, $headers)
                ->assertStatus(422, "Expected 422 for {$query}");
        }
    }

    // ───────────────────────────────────────────── scores list (2017:52260)

    public function test_a_score_row_carries_the_learner_name_and_live_localised_names(): void
    {
        $t      = $this->template(['en' => 'Course Feedback', 'ar' => 'تقييم الدورة']);
        $course = $this->course('Leadership', 'القيادة');
        $user   = User::factory()->create(['name' => 'Fixture Learner']);
        $this->answer($user, $course, $this->question($t, 'five'), '2');

        ['headers' => $headers] = $this->adminToken();
        $row = $this->fetch('/admin/evaluations/scores', $headers)['result'][0];

        $this->assertSame('Fixture Learner', $row['learner']['name']);
        $this->assertSame('EMP-'.$user->id, $row['learner']['employee_id']);
        $this->assertSame('Leadership', $row['course']['name']);
        $this->assertSame('Course Feedback', $row['template']['name']);
        $this->assertNotNull($row['template']['created_at']);
        $this->assertEqualsWithDelta(2.0, $row['score'], 0.001);
        $this->assertFalse($row['passed']);
    }

    public function test_a_deleted_course_falls_back_to_the_snapshot_name(): void
    {
        $t      = $this->template('T');
        $course = $this->course();
        $user   = User::factory()->create();
        $this->answer($user, $course, $this->question($t, 'five'), '4');
        DB::table('courses')->where('id', $course->id)->delete();

        ['headers' => $headers] = $this->adminToken();
        $row = $this->fetch('/admin/evaluations/scores', $headers)['result'][0];

        $this->assertSame('لقطة', $row['course']['name']);
    }

    public function test_the_scores_list_filters_by_chip_and_result(): void
    {
        $t      = $this->template('T');
        $q      = $this->question($t, 'five');
        $course = $this->course();
        $a      = User::factory()->create(['name' => 'Alpha Learner']);
        $b      = User::factory()->create(['name' => 'Beta Learner']);
        $this->answer($a, $course, $q, '5');
        $this->answer($b, $course, $q, '1', ['instructor_id' => 99]);

        ['headers' => $headers] = $this->adminToken();
        $names = fn (string $query) => array_column(
            array_column($this->fetch('/admin/evaluations/scores?'.$query, $headers)['result'], 'learner'), 'name');

        $this->assertSame(['Alpha Learner'], $names('learner_ids[]='.$a->id));
        $this->assertSame(['Beta Learner'], $names('instructor_ids[]=99'));
        $this->assertSame(['Beta Learner'], $names('results[]=failed'));
        $this->assertSame(['Alpha Learner'], $names('search=Alpha'));
        $this->assertSame([], $names('course_ids[]='.$this->course('Other')->id));
    }

    public function test_the_scores_list_sorts_by_last_scored_both_ways(): void
    {
        $t      = $this->template('T');
        $q      = $this->question($t, 'five');
        $course = $this->course();
        $early  = User::factory()->create(['name' => 'Early']);
        $late   = User::factory()->create(['name' => 'Late']);
        $this->answer($early, $course, $q, '4', ['created_at' => '2026-01-01 10:00:00']);
        $this->answer($late, $course, $q, '4', ['created_at' => '2026-02-01 10:00:00']);

        ['headers' => $headers] = $this->adminToken();
        $order = fn (string $dir) => array_column(array_column(
            $this->fetch('/admin/evaluations/scores?dir='.$dir, $headers)['result'], 'learner'), 'name');

        $this->assertSame(['Late', 'Early'], $order('desc'));
        $this->assertSame(['Early', 'Late'], $order('asc'));
    }

    public function test_the_scores_list_rejects_oversized_filters(): void
    {
        ['headers' => $headers] = $this->adminToken();
        $ids = implode('&', array_map(fn ($i) => "learner_ids[]={$i}", range(1, 101)));

        $this->getJson(self::BASE.'/admin/evaluations/scores?'.$ids, $headers)->assertStatus(422);
        $this->getJson(self::BASE.'/admin/evaluations/scores?results[]=great', $headers)->assertStatus(422);
    }

    // ───────────────────────────────────────── detail and results (2169:*)

    public function test_the_submission_localises_titles_and_names_the_learner(): void
    {
        $t      = $this->template('T');
        $course = $this->course();
        $user   = User::factory()->create(['name' => 'Fixture Learner']);
        $this->answer($user, $course, $this->question($t, 'five', ['en' => 'Clear?', 'ar' => 'واضح؟']), '4');
        $this->answer($user, $course, $this->question($t, 'text', ['en' => 'Comments', 'ar' => 'ملاحظات']), 'Good pace');

        ['headers' => $headers] = $this->adminToken();
        $result = $this->fetch("/admin/evaluations/scores/{$user->id}/{$course->id}?template_id={$t->id}", $headers)['result'];

        $this->assertSame('Fixture Learner', $result['learner']['name']);
        $this->assertSame(['Clear?', 'Comments'], array_column($result['answers'], 'title'));
        $this->assertSame(['five', 'text'], array_column($result['answers'], 'type'));
        $this->assertEqualsWithDelta(4.0, $result['score'], 0.001);
        $this->assertTrue($result['passed']);
    }

    public function test_template_results_localise_question_titles_and_report_the_header(): void
    {
        $t      = $this->template('T');
        $q      = $this->question($t, 'five', ['en' => 'Clear?', 'ar' => 'واضح؟']);
        $course = $this->course();
        $this->answer(User::factory()->create(), $course, $q, '4');
        $this->answer(User::factory()->create(), $course, $q, '2');

        ['headers' => $headers] = $this->adminToken();
        $result = $this->fetch("/admin/evaluations/{$t->id}/results", $headers)['result'];

        // Was the raw {"en":...,"ar":...} string before D4.
        $this->assertSame('Clear?', $result['questions'][0]['title']);
        $this->assertEqualsWithDelta(3.0, $result['summary']['score'], 0.001);
        $this->assertTrue($result['summary']['passed']);
        $this->assertSame(2, $result['summary']['learners_scored']);
        $this->assertSame(2, $result['summary']['submissions']);
        $this->assertSame(5, $result['summary']['score_max']);
        $this->assertNotNull($result['template']['created_at']);
    }

    // ─────────────────────────────── courses: evaluation score, no ratings

    public function test_the_course_list_and_detail_carry_the_evaluation_score(): void
    {
        $t      = $this->template('T');
        $q      = $this->question($t, 'five');
        $scored = $this->course('Scored');
        $never  = $this->course('Never Evaluated');
        $this->answer(User::factory()->create(), $scored, $q, '4');
        $this->answer(User::factory()->create(), $scored, $q, '5');

        ['headers' => $headers] = $this->adminToken();
        $rows = collect($this->fetch('/courses?per_page=50', $headers)['result'])->keyBy('id');

        $this->assertEqualsWithDelta(4.5, $rows[$scored->id]['evaluation_score'], 0.001);
        // Never evaluated is null, not 0.
        $this->assertArrayHasKey('evaluation_score', $rows[$never->id]);
        $this->assertNull($rows[$never->id]['evaluation_score']);
        // The star rating left the admin payloads (2026-09-26).
        $this->assertArrayNotHasKey('rating', $rows[$scored->id]);

        $detail = $this->fetch("/courses/{$scored->id}", $headers)['result'];
        $this->assertEqualsWithDelta(4.5, $detail['evaluation_score'], 0.001);
        $this->assertSame(2, $detail['evaluation_submissions']);
        foreach (['rating', 'rating_count', 'rating_distribution', 'reviews', 'comments_count'] as $gone) {
            $this->assertArrayNotHasKey($gone, $detail);
        }
    }

    public function test_the_course_list_scores_every_row_in_one_query(): void
    {
        $t = $this->template('T');
        $q = $this->question($t, 'five');
        ['headers' => $headers] = $this->adminToken();

        $measure = function () use ($headers): int {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->fetch('/courses?per_page=50', $headers);
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $this->answer(User::factory()->create(), $this->course('One'), $q, '4');
        $measure(); // warm: the first request also loads the permission cache
        $withOne = $measure();
        for ($i = 0; $i < 5; $i++) {
            $this->answer(User::factory()->create(), $this->course("More {$i}"), $q, '3');
        }
        $withSix = $measure();

        $this->assertSame($withOne, $withSix, "Query count grew from {$withOne} to {$withSix} with five more scored courses.");
    }

    public function test_the_admin_rating_endpoints_are_gone(): void
    {
        ['headers' => $headers] = $this->adminToken();
        $course = $this->course();

        foreach ([
            ['GET', '/admin/ratings'],
            ['GET', '/admin/ratings/summary'],
            ['GET', '/admin/ratings/filter-options'],
            ['GET', '/ratings'],
            ['GET', "/courses/{$course->id}/ratings"],
            ['DELETE', "/courses/{$course->id}/ratings/1"],
        ] as [$method, $path]) {
            $status = $this->json($method, self::BASE.$path, [], $headers)->status();
            $this->assertContains($status, [404, 405], "{$method} {$path} answered {$status}");
        }
    }

    public function test_the_roles_editor_offers_view_evaluations_not_view_ratings(): void
    {
        ['headers' => $headers] = $this->adminToken();
        $groups = collect($this->fetch('/admin/roles/sections', $headers)['result']['groups'] ?? []);
        $keys   = $groups->flatMap(fn ($g) => array_column($g['items'], 'key'))->all();

        // Items are section keys since the permission matrix (D-073).
        $this->assertContains('evaluations', $keys);
        $this->assertNotContains('ratings', $keys);
        $learning = $groups->firstWhere('key', 'learning_operation');
        $this->assertContains('evaluations', array_column($learning['items'] ?? [], 'key'));
    }

    // ─────────────────────────────────────────────────────── filter choices

    public function test_filter_options_offer_only_values_found_in_responses(): void
    {
        $t    = $this->template('T');
        $q    = $this->question($t, 'five');
        $used = $this->course('Used Course');
        $this->course('Unused Course');
        $this->answer(User::factory()->create(), $used, $q, '4');

        ['headers' => $headers] = $this->adminToken();
        $result = $this->fetch('/admin/evaluations/filter-options', $headers)['result'];

        $this->assertSame([['id' => $used->id, 'name' => 'Used Course']], $result['courses']);
        $this->assertSame([$this->instructorId], array_column($result['instructors'], 'id'));
    }

    public function test_learner_options_list_only_learners_who_submitted(): void
    {
        $t      = $this->template('T');
        $q      = $this->question($t, 'five');
        $course = $this->course();
        $sub    = User::factory()->create(['name' => 'Submitter One']);
        User::factory()->create(['name' => 'Submitter Never']);
        $this->answer($sub, $course, $q, '4');

        ['headers' => $headers] = $this->adminToken();
        $body = $this->fetch('/admin/evaluations/learner-options?search=Submitter', $headers);

        $this->assertSame([['id' => $sub->id, 'name' => 'Submitter One', 'employee_id' => $sub->machine_code]], $body['result']);
        $this->assertSame(1, $body['meta']['total']);
    }
}
