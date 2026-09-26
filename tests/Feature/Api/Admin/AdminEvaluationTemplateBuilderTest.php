<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Admin;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Evaluation;
use App\Models\EvaluationCategory;
use App\Models\Instructor;
use App\Models\User;
use App\Notifications\CourseEvaluationDroppedNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Api\ApiTestCase;

/**
 * The evaluation template builder, import / export, the scoped learner form
 * and the below-limit alert (Figma 2409:132793 / 2409:133222 / 2171:111083;
 * D-054; "no edit once answered", decided 2026-09-26).
 */
class AdminEvaluationTemplateBuilderTest extends ApiTestCase
{
    private const URL = self::BASE.'/admin/evaluations/templates';

    private function course(bool $evaluable = true): Course
    {
        $course = Course::factory()->create(['title' => ['en' => 'Excel', 'ar' => 'إكسل']]);
        DB::table('courses')->where('id', $course->id)->update(['is_evaluate' => $evaluable ? 1 : 0]);

        return $course->refresh();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name'       => ['en' => 'Course Feedback', 'ar' => 'تقييم الدورة'],
            'course_id'  => null,
            'section_id' => null,
            'questions'  => [
                ['title' => ['en' => 'Clear?', 'ar' => 'واضح؟'], 'type' => 'five', 'required' => true],
                [
                    'title' => ['en' => 'Pace', 'ar' => 'السرعة'], 'type' => 'scale', 'required' => false,
                    'scale_label_min' => ['en' => 'Unsatisfied', 'ar' => 'غير راضٍ'],
                    'scale_label_max' => ['en' => 'Very satisfied', 'ar' => 'راضٍ جدًا'],
                ],
            ],
        ], $overrides);
    }

    private function answer(User $user, Course $course, Evaluation $q, string $answer): void
    {
        DB::table('user_course_evaluations')->insert([
            'user_id' => $user->id, 'course_id' => $course->id, 'instructor_id' => 1,
            'evaluation_category_id' => $q->evaluation_category_id, 'evaluation_id' => $q->id,
            'evaluation_type' => Evaluation::SCALE_MAX[$q->type] ?? 0, 'answer' => $answer,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function enrol(User $user, Course $course, ?CourseSection $cohort = null): void
    {
        DB::table('users_courses')->insert([
            'user_id' => $user->id, 'course_id' => $course->id, 'group_id' => $cohort?->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ───────────────────────────────────────────────────────────── create

    public function test_publishing_creates_the_template_with_its_questions(): void
    {
        ['headers' => $headers] = $this->adminToken();

        $result = $this->postJson(self::URL, $this->payload(), $headers)->assertCreated()->json('result');

        $this->assertSame(['en' => 'Course Feedback', 'ar' => 'تقييم الدورة'], $result['name']);
        $this->assertNull($result['course']);
        $this->assertFalse($result['locked']);
        $this->assertCount(2, $result['questions']);
        $this->assertSame(['five', 'scale'], array_column($result['questions'], 'type'));
        $this->assertSame(['en' => 'Unsatisfied', 'ar' => 'غير راضٍ'], $result['questions'][1]['scale_label_min']);
        $this->assertNull($result['questions'][0]['scale_label_min']);
        $this->assertSame([true, false], array_column($result['questions'], 'required'));
    }

    public function test_a_template_can_be_scoped_to_one_course_and_cohort(): void
    {
        $course = $this->course();
        $cohort = CourseSection::factory()->create(['course_id' => $course->id]);
        ['headers' => $headers] = $this->adminToken();

        $result = $this->postJson(self::URL, $this->payload(['course_id' => $course->id, 'section_id' => $cohort->id]), $headers)
            ->assertCreated()->json('result');

        $this->assertSame($course->id, $result['course']['id']);
        $this->assertSame($cohort->id, $result['cohort']['id']);
    }

    public function test_invalid_templates_are_refused(): void
    {
        $other     = $this->course();
        $evaluable = $this->course();
        $cohortOfOther = CourseSection::factory()->create(['course_id' => $other->id]);
        EvaluationCategory::query()->create(['name' => ['en' => 'Taken', 'ar' => 'مأخوذ']]);
        ['headers' => $headers] = $this->adminToken();

        $cases = [
            'no Arabic name'       => [$this->payload(['name' => ['en' => 'X']]), 'name.ar'],
            'no questions'         => [$this->payload(['questions' => []]), 'questions'],
            'text type'            => [$this->payload(['questions' => [['title' => ['en' => 'a', 'ar' => 'b'], 'type' => 'text', 'required' => true]]]), 'questions.0.type'],
            'scale without labels' => [$this->payload(['questions' => [['title' => ['en' => 'a', 'ar' => 'b'], 'type' => 'scale', 'required' => true]]]), 'questions.0.scale_label_min.en'],
            'course not evaluable' => [$this->payload(['course_id' => $this->course(false)->id]), 'course_id'],
            'cohort of another course' => [$this->payload(['course_id' => $evaluable->id, 'section_id' => $cohortOfOther->id]), 'section_id'],
            'cohort without course'    => [$this->payload(['section_id' => $cohortOfOther->id]), 'section_id'],
            'name taken, any case'     => [$this->payload(['name' => ['en' => 'TAKEN', 'ar' => 'جديد']]), 'name.en'],
            'too many questions'       => [$this->payload(['questions' => array_fill(0, 51, ['title' => ['en' => 'a', 'ar' => 'b'], 'type' => 'five', 'required' => true])]), 'questions'],
        ];

        foreach ($cases as $label => [$body, $field]) {
            $this->postJson(self::URL, $body, $headers)->assertStatus(422)->assertJsonValidationErrors($field, 'errors');
        }
        $this->assertSame(1, EvaluationCategory::query()->count(), 'Nothing was created by a refused request.');
    }

    public function test_scale_labels_are_capped_at_twenty_characters(): void
    {
        ['headers' => $headers] = $this->adminToken();
        $body = $this->payload();
        $body['questions'][1]['scale_label_max']['en'] = str_repeat('x', 21);

        $this->postJson(self::URL, $body, $headers)->assertStatus(422)
            ->assertJsonValidationErrors('questions.1.scale_label_max.en', 'errors');
    }

    public function test_builder_options_list_only_evaluable_courses_with_their_cohorts(): void
    {
        $evaluable = $this->course();
        $cohort    = CourseSection::factory()->create(['course_id' => $evaluable->id]);
        $this->course(false);
        ['headers' => $headers] = $this->adminToken();

        $result = $this->getJson(self::URL.'/options', $headers)->assertOk()->json('result');

        $this->assertSame([$evaluable->id], array_column($result['courses'], 'id'));
        $this->assertSame([$cohort->id], array_column($result['courses'][0]['cohorts'], 'id'));
        $this->assertEqualsWithDelta(3.0, $result['pass_threshold'], 0.001);
    }

    // ───────────────────────────────────────────────────────────── update

    public function test_an_unanswered_template_can_be_edited(): void
    {
        ['headers' => $headers] = $this->adminToken();
        $id = $this->postJson(self::URL, $this->payload(), $headers)->assertCreated()->json('result.id');

        $body = $this->payload([
            'name'      => ['en' => 'Renamed', 'ar' => 'اسم جديد'],
            'questions' => [['title' => ['en' => 'Only', 'ar' => 'فقط'], 'type' => 'five', 'required' => true]],
        ]);
        $result = $this->putJson(self::URL."/{$id}", $body, $headers)->assertOk()->json('result');

        $this->assertSame('Renamed', $result['name']['en']);
        $this->assertSame(['Only'], array_column(array_column($result['questions'], 'title'), 'en'));
        $this->assertSame(1, Evaluation::query()->where('evaluation_category_id', $id)->count());
    }

    public function test_an_answered_template_cannot_be_edited_at_all(): void
    {
        ['headers' => $headers] = $this->adminToken();
        $id       = $this->postJson(self::URL, $this->payload(), $headers)->assertCreated()->json('result.id');
        $question = Evaluation::query()->where('evaluation_category_id', $id)->first();
        $this->answer(User::factory()->create(), $this->course(), $question, '4');

        // Not even the wording (decided 2026-09-26).
        $body = $this->payload(['name' => ['en' => 'Course Feedback!', 'ar' => 'تقييم الدورة']]);
        $this->putJson(self::URL."/{$id}", $body, $headers)->assertStatus(422)->assertJsonValidationErrors('template', 'errors');

        $this->assertSame('Course Feedback', EvaluationCategory::find($id)->getTranslation('name', 'en'));
        $show = $this->getJson(self::URL."/{$id}", $headers)->assertOk()->json('result');
        $this->assertTrue($show['locked']);
        $this->assertEqualsWithDelta(4.0, $show['highest_score'], 0.001);
        $this->assertTrue(collect($this->getJson(self::URL, $headers)->json('result'))->firstWhere('id', $id)['locked']);
    }

    // ───────────────────────────────────────────────── list and results

    public function test_eligible_learners_follow_the_template_scope(): void
    {
        $a = $this->course();
        $b = $this->course();
        $cohortA = CourseSection::factory()->create(['course_id' => $a->id]);
        $this->enrol(User::factory()->create(), $a, $cohortA);
        $this->enrol(User::factory()->create(), $a);
        $this->enrol(User::factory()->create(), $b);
        ['headers' => $headers] = $this->adminToken();

        $all    = $this->postJson(self::URL, $this->payload(), $headers)->json('result.id');
        $onA    = $this->postJson(self::URL, $this->payload(['name' => ['en' => 'On A', 'ar' => 'أ'], 'course_id' => $a->id]), $headers)->json('result.id');
        $cohort = $this->postJson(self::URL, $this->payload(['name' => ['en' => 'Cohort A', 'ar' => 'مجموعة أ'], 'course_id' => $a->id, 'section_id' => $cohortA->id]), $headers)->json('result.id');

        $rows = collect($this->getJson(self::URL, $headers)->assertOk()->json('result'))->keyBy('id');
        $this->assertSame(3, $rows[$all]['learners_eligible']);
        $this->assertSame(2, $rows[$onA]['learners_eligible']);
        $this->assertSame(1, $rows[$cohort]['learners_eligible']);
        $this->assertSame($a->id, $rows[$onA]['course']['id']);
        $this->assertNull($rows[$all]['course']);

        $summary = $this->getJson(self::BASE."/admin/evaluations/{$onA}/results", $headers)->assertOk()->json('result');
        $this->assertSame(2, $summary['summary']['learners_eligible']);
        $this->assertSame('Unsatisfied', $summary['questions'][1]['scale_label_min']);
        $this->assertSame(5, $summary['questions'][1]['scale_max']);
    }

    // ─────────────────────────────────────────────── the learner's form

    public function test_a_learner_is_asked_only_the_templates_for_their_course_and_cohort(): void
    {
        $course = $this->course();
        $other  = $this->course();
        $mine   = CourseSection::factory()->create(['course_id' => $course->id]);
        $theirs = CourseSection::factory()->create(['course_id' => $course->id]);
        $learner = User::factory()->create();
        $this->enrol($learner, $course, $mine);
        ['headers' => $admin] = $this->adminToken();

        foreach ([
            ['All', null, null], ['This course', $course->id, null], ['My cohort', $course->id, $mine->id],
            ['Other cohort', $course->id, $theirs->id], ['Other course', $other->id, null],
        ] as [$name, $courseId, $sectionId]) {
            $this->postJson(self::URL, $this->payload(['name' => ['en' => $name, 'ar' => $name.' ع'], 'course_id' => $courseId, 'section_id' => $sectionId]), $admin)->assertCreated();
        }

        ['headers' => $headers] = $this->userToken($learner);
        $names = collect($this->getJson(self::BASE."/courses/{$course->id}/evaluate", $headers + ['Accept-Language' => 'en'])
            ->assertOk()->json('result.evaluation_categories'))->pluck('name')->sort()->values()->all();

        $this->assertSame(['All', 'My cohort', 'This course'], $names);
    }

    public function test_the_learner_submit_accepts_only_valid_answers_to_questions_they_were_asked(): void
    {
        $course     = $this->course();
        $instructor = Instructor::query()->create(['name' => ['en' => 'Teacher', 'ar' => 'معلم'], 'email' => 'teacher'.uniqid().'@example.test']);
        $course->instructors()->attach($instructor->id);
        $learner = User::factory()->create();
        $this->enrol($learner, $course);
        ['headers' => $admin] = $this->adminToken();
        $id = $this->postJson(self::URL, $this->payload(), $admin)->json('result.id');
        [$star, $scale] = Evaluation::query()->where('evaluation_category_id', $id)->orderBy('id')->get()->all();
        $elsewhere = EvaluationCategory::query()->create(['name' => ['en' => 'Elsewhere', 'ar' => 'مكان آخر'], 'course_id' => $this->course()->id]);
        $foreign = $elsewhere->evaluations()->create(['type' => 'five', 'title' => ['en' => 'x', 'ar' => 'x'], 'is_required' => true]);

        ['headers' => $headers] = $this->userToken($learner);
        $url    = self::BASE."/courses/{$course->id}/evaluate";
        $submit = fn (array $questions) => $this->postJson($url, ['instructor_id' => $instructor->id, 'questions' => $questions], $headers);

        $submit([$scale->id => '3'])->assertStatus(422);                           // required star missing
        $submit([$star->id => '6'])->assertStatus(422);                            // out of range
        $submit([$star->id => '4', $foreign->id => '5'])->assertStatus(422);       // not asked
        $this->postJson($url, ['instructor_id' => Instructor::query()->create(['name' => ['en' => 'N', 'ar' => 'ن'], 'email' => 'n'.uniqid().'@example.test'])->id, 'questions' => [$star->id => '4']], $headers)
            ->assertStatus(422);                                                   // not this course's instructor
        $this->assertSame(0, DB::table('user_course_evaluations')->count());

        $submit([$star->id => '4', $scale->id => '5'])->assertCreated();
        $this->assertSame([5, 5], DB::table('user_course_evaluations')->orderBy('evaluation_id')->pluck('evaluation_type')->map(fn ($v) => (int) $v)->all());
        $submit([$star->id => '4'])->assertStatus(409);                            // once per course
    }

    public function test_a_course_falling_below_the_limit_alerts_admins_and_instructors_once(): void
    {
        Notification::fake();
        $course     = $this->course();
        $instructor = Instructor::query()->create(['name' => ['en' => 'Teacher', 'ar' => 'معلم'], 'email' => 'teacher'.uniqid().'@example.test']);
        $course->instructors()->attach($instructor->id);
        ['model' => $admin, 'headers' => $adminHeaders] = $this->adminToken();
        $blind = Admin::factory()->create();
        $blind->assignRole(tap(Role::findOrCreate('no-eval', 'admin'))->givePermissionTo(Permission::findOrCreate('view-dashboard', 'admin')));
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $id = $this->postJson(self::URL, $this->payload(['questions' => [['title' => ['en' => 'Q', 'ar' => 'س'], 'type' => 'five', 'required' => true]]]), $adminHeaders)->json('result.id');
        $q  = Evaluation::query()->where('evaluation_category_id', $id)->first();

        $send = function (string $answer) use ($course, $instructor, $q) {
            $learner = User::factory()->create();
            $this->enrol($learner, $course);
            ['headers' => $h] = $this->userToken($learner);
            $this->postJson(self::BASE."/courses/{$course->id}/evaluate", ['instructor_id' => $instructor->id, 'questions' => [$q->id => $answer]], $h)->assertCreated();
        };

        $send('5');                                                  // 5.0: fine
        Notification::assertNothingSent();
        $send('1');                                                  // (5 + 1) / 2 = 3.0: at the limit, not below
        Notification::assertNothingSent();
        $send('1');                                                  // 7 / 3 = 2.3: crossed
        Notification::assertSentTo($admin, CourseEvaluationDroppedNotification::class);
        Notification::assertSentTo($instructor, CourseEvaluationDroppedNotification::class);
        Notification::assertNotSentTo($blind, CourseEvaluationDroppedNotification::class);
        $send('1');                                                  // still below: no repeat
        Notification::assertSentToTimes($admin, CourseEvaluationDroppedNotification::class, 1);
    }

    // ─────────────────────────────────────────────── import and export

    private function csv(array $rows): UploadedFile
    {
        $lines = [implode(',', \App\Exports\EvaluationTemplatesExport::COLUMNS)];
        foreach ($rows as $r) {
            $lines[] = implode(',', array_map(fn ($c) => '"'.str_replace('"', '""', (string) $c).'"', $r));
        }

        return UploadedFile::fake()->createWithContent('templates.csv', implode("\n", $lines)."\n");
    }

    /** A row in EvaluationTemplatesExport::COLUMNS order. */
    private function row(string $en, string $ar, string $q, string $type = 'star', array $labels = ['', '', '', ''], string $course = ''): array
    {
        return array_merge([$en, $ar, $course, '', '', '', $q, $q.' ع', $type, 'yes'], $labels);
    }

    public function test_an_import_creates_every_template_when_the_whole_file_is_valid(): void
    {
        ['headers' => $headers] = $this->adminToken();
        $file = $this->csv([
            $this->row('Imported One', 'مستورد ١', 'First'),
            $this->row('Imported One', 'مستورد ١', 'Second', 'scale', ['Low', 'منخفض', 'High', 'مرتفع']),
            $this->row('Imported Two', 'مستورد ٢', 'Only'),
        ]);

        $report = $this->post(self::URL.'/import', ['file' => $file], $headers)->assertOk()->json('result');

        $this->assertSame(['created' => 2, 'questions' => 3, 'errors' => []], $report);
        $two = EvaluationCategory::query()->get()->first(fn ($t) => $t->getTranslation('name', 'en') === 'Imported One');
        $this->assertSame(['five', 'scale'], $two->evaluations()->orderBy('id')->pluck('type')->all());
    }

    public function test_an_import_with_any_bad_row_writes_nothing_and_lists_every_problem(): void
    {
        EvaluationCategory::query()->create(['name' => ['en' => 'Existing', 'ar' => 'موجود']]);
        ['headers' => $headers] = $this->adminToken();
        $file = $this->csv([
            $this->row('Fine', 'سليم', 'Good question'),
            $this->row('Fine2', 'سليم٢', 'Bad type', 'emoji'),
            $this->row('Existing', 'جديد', 'Clashes'),
            $this->row('Scale', 'مقياس', 'No labels', 'scale'),
        ]);

        $report = $this->post(self::URL.'/import', ['file' => $file], $headers)->assertOk()->json('result');

        $this->assertSame(0, $report['created']);
        $this->assertSame(1, EvaluationCategory::query()->count(), 'All or nothing: the valid row was not written either.');
        $problems = array_map(fn ($e) => $e['row'].':'.$e['column'], $report['errors']);
        $this->assertContains('3:type', $problems);
        $this->assertContains('4:template_name_en', $problems);
        $this->assertContains('5:scale_min_label_en', $problems);
    }

    public function test_an_import_refuses_a_file_that_is_not_a_spreadsheet(): void
    {
        ['headers' => $headers] = $this->adminToken();
        // A real file, not UploadedFile::fake(): a fake reports the MIME type of
        // its extension, so it cannot show that the content is what is checked.
        $path = tempnam(sys_get_temp_dir(), 'eval');
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        $file = new UploadedFile($path, 'templates.csv', null, null, true);

        $this->post(self::URL.'/import', ['file' => $file], $headers + ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('file', 'errors');
        $this->assertSame(0, EvaluationCategory::query()->count());
    }

    public function test_export_follows_the_list_filters_and_the_template_has_the_same_columns(): void
    {
        Excel::fake();
        ['headers' => $headers] = $this->adminToken();
        $this->postJson(self::URL, $this->payload(), $headers)->assertCreated();
        $this->postJson(self::URL, $this->payload(['name' => ['en' => 'Other', 'ar' => 'آخر']]), $headers)->assertCreated();

        $this->get(self::URL.'/export?format=csv&search=Other', $headers)->assertOk();
        Excel::assertDownloaded('evaluation-templates-'.now()->format('Y-m-d').'.csv', function ($export) {
            $rows = $export->collection();

            return $rows->count() === 2 && $rows->every(fn ($r) => $r[0] === 'Other') && $export->headings() === \App\Exports\EvaluationTemplatesExport::COLUMNS;
        });

        $this->get(self::URL.'/import-template?format=xlsx', $headers)->assertOk();
        Excel::assertDownloaded('evaluation-templates-template.xlsx', fn ($export) => $export->collection()->isEmpty());
    }

    // ───────────────────────────────────────────────────────────── authz

    public function test_the_builder_routes_need_view_evaluations(): void
    {
        $template = EvaluationCategory::query()->create(['name' => ['en' => 'T', 'ar' => 'ت']]);
        $role = Role::findOrCreate('builder-restricted', 'admin');
        $role->givePermissionTo(Permission::findOrCreate('view-dashboard', 'admin'));
        $admin = Admin::factory()->create();
        $admin->assignRole($role);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $restricted = ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken];
        ['headers' => $learner] = $this->userToken();

        $calls = [
            ['POST', self::URL], ['GET', self::URL.'/options'], ['GET', self::URL."/{$template->id}"], ['PUT', self::URL."/{$template->id}"],
            ['GET', self::URL.'/export'], ['GET', self::URL.'/import-template'], ['POST', self::URL.'/import'],
        ];
        foreach ($calls as [$method, $url]) {
            $this->json($method, $url, $this->payload(), $restricted)->assertStatus(403);
            $this->json($method, $url, $this->payload(), $learner)->assertStatus(403);
            $this->json($method, $url, $this->payload())->assertStatus(401);
        }
    }

    public function test_the_legacy_template_crud_is_gone(): void
    {
        ['headers' => $headers] = $this->adminToken();
        foreach (['/evaluation-categories', '/evaluation-categories/all', '/evaluations'] as $path) {
            $this->getJson(self::BASE.$path, $headers)->assertNotFound();
        }
        $this->postJson(self::BASE.'/evaluation-categories', ['name' => 'x'], $headers)->assertNotFound();
    }
}
