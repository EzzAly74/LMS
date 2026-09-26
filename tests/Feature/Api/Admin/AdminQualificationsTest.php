<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Admin;
use App\Models\Course;
use App\Models\CourseExam;
use App\Models\JobTitle;
use App\Models\QualificationSkill;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Api\ApiTestCase;

/**
 * D5 - the Dashboard Qualifications page (Figma 2066:100159) and its New /
 * Edit modal (2066:100876): the list's figures (D-056), one qualification with
 * its assignments, create / update with job titles and learners, the modal's
 * search, delete, and access.
 */
class AdminQualificationsTest extends ApiTestCase
{
    private const URL = self::BASE.'/admin/qualification-skills';

    private function skill(string $en, ?string $ar = null): QualificationSkill
    {
        return QualificationSkill::query()->create(['name' => ['en' => $en, 'ar' => $ar ?? $en.' ع']]);
    }

    private function linkedCourse(QualificationSkill $q): Course
    {
        $course = Course::factory()->create();
        DB::table('course_qualification_skills')->insert(['course_id' => $course->id, 'qualification_skill_id' => $q->id]);

        return $course;
    }

    private function enrol(User $u, Course $c, bool $passed = false): void
    {
        DB::table('users_courses')->insert(['user_id' => $u->id, 'course_id' => $c->id, 'created_at' => now(), 'updated_at' => now()]);
        if ($passed) {
            $exam = CourseExam::factory()->create(['course_id' => $c->id]);
            DB::table('user_exams')->insert([
                'user_id' => $u->id, 'course_id' => $c->id, 'exam_id' => $exam->id, 'status' => 'passed',
                'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function grant(User $u, QualificationSkill $q): void
    {
        DB::table('user_qualification_skill')->insert([
            'user_id' => $u->id, 'qualification_skill_id' => $q->id, 'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function row(array $headers, int $id, string $query = ''): array
    {
        $row = collect($this->getJson(self::URL.$query, $headers)->assertOk()->json('result'))->firstWhere('id', $id);
        $this->assertNotNull($row, "Qualification {$id} should be listed.");

        return $row;
    }

    // ---------------------------------------------------------------- the list

    public function test_the_list_measures_everything_against_the_people_it_applies_to(): void
    {
        $q  = $this->skill('Excel');
        $c1 = $this->linkedCourse($q);
        $c2 = $this->linkedCourse($q);
        $jt = JobTitle::query()->create(['name' => 'Analyst']);
        $jt->qualificationSkills()->attach($q->id);

        $all  = User::factory()->create(['job_title_id' => $jt->id]); // passed both: holds, 100%
        $half = User::factory()->create(['job_title_id' => $jt->id]); // passed one of two: 50%
        User::factory()->create(['job_title_id' => $jt->id]);          // nothing yet: 0%
        $granted = User::factory()->create();                         // outside the job title, granted: holds, 100%
        $outsider = User::factory()->create();                        // enrolled, not in the group

        $this->enrol($all, $c1, true);
        $this->enrol($all, $c2, true);
        $this->enrol($half, $c1, true);
        $this->enrol($half, $c2);
        $this->enrol($outsider, $c1);
        $this->grant($granted, $q);

        ['headers' => $headers] = $this->adminToken();
        $row = $this->row($headers, $q->id);

        $this->assertSame('Excel', $row['name']);
        $this->assertSame(2, $row['courses_count']);
        $this->assertSame(1, $row['job_titles_count']);
        $this->assertSame(3, $row['enrolled_count'], 'Anyone enrolled in a linked course.');
        $this->assertSame(4, $row['learners_count'], 'Three employees of the job title plus one direct grant.');
        $this->assertSame(2, $row['certified_count']);
        // (100 + 50 + 0 + 100) / 4 = 62.5, rounded half up.
        $this->assertSame(63, $row['completion_percent']);
    }

    public function test_passing_some_linked_courses_is_progress_not_certification(): void
    {
        $q = $this->skill('Safety');
        $c1 = $this->linkedCourse($q);
        $this->linkedCourse($q); // never enrolled in: still required
        $jt = JobTitle::query()->create(['name' => 'Operator']);
        $jt->qualificationSkills()->attach($q->id);
        $u = User::factory()->create(['job_title_id' => $jt->id]);
        $this->enrol($u, $c1, true);

        ['headers' => $headers] = $this->adminToken();
        $row = $this->row($headers, $q->id);

        $this->assertSame(0, $row['certified_count']);
        $this->assertSame(50, $row['completion_percent']);
    }

    public function test_someone_both_required_and_granted_counts_once(): void
    {
        $q  = $this->skill('Excel');
        $jt = JobTitle::query()->create(['name' => 'Analyst']);
        $jt->qualificationSkills()->attach($q->id);
        $u = User::factory()->create(['job_title_id' => $jt->id]);
        $this->grant($u, $q);

        ['headers' => $headers] = $this->adminToken();
        $row = $this->row($headers, $q->id);

        $this->assertSame(1, $row['learners_count']);
        $this->assertSame(1, $row['certified_count']);
        $this->assertSame(100, $row['completion_percent']);
    }

    public function test_a_qualification_nobody_needs_has_no_completion_figure(): void
    {
        $q = $this->skill('Unused');
        ['headers' => $headers] = $this->adminToken();
        $row = $this->row($headers, $q->id);

        $this->assertSame(0, $row['learners_count']);
        $this->assertNull($row['completion_percent'], 'A dash, not a 0% that reads as failure.');
    }

    public function test_search_ignores_case_matches_either_language_and_escapes_wildcards(): void
    {
        $this->skill('Microsoft Office', 'مايكروسوفت أوفيس');
        $this->skill('Email Etiquette', 'آداب البريد');
        $this->skill('100% Safety', 'سلامة');
        ['headers' => $headers] = $this->adminToken();

        $names = fn (string $q) => collect($this->getJson(self::URL.'?search='.urlencode($q), $headers)->assertOk()->json('result'))->pluck('name')->all();

        $this->assertSame(['Microsoft Office'], $names('microsoft'));
        $this->assertSame(['Email Etiquette'], $names('آداب'));
        $this->assertSame(['100% Safety'], $names('%'), 'A % in the search is a character, not a wildcard.');
    }

    public function test_the_list_is_paginated_and_bounded(): void
    {
        foreach (range(1, 3) as $i) {
            $this->skill("Q{$i}");
        }
        ['headers' => $headers] = $this->adminToken();

        $this->getJson(self::URL.'?per_page=2', $headers)->assertOk()->assertJsonCount(2, 'result')->assertJsonPath('meta.total', 3);
        $this->getJson(self::URL.'?per_page=1000', $headers)->assertStatus(422);
        $this->getJson(self::URL.'?per_page=0', $headers)->assertStatus(422);
    }

    public function test_the_list_costs_the_same_queries_whatever_the_page_size(): void
    {
        ['headers' => $headers] = $this->adminToken();
        $jt = JobTitle::query()->create(['name' => 'Analyst']);
        $count = function (int $n) use ($headers, $jt) {
            foreach (range(1, $n) as $i) {
                $q = $this->skill('Q'.uniqid());
                $this->linkedCourse($q);
                $jt->qualificationSkills()->attach($q->id);
            }
            $this->getJson(self::URL.'?per_page=50', $headers)->assertOk(); // warm up
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson(self::URL.'?per_page=50', $headers)->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $this->assertSame($count(2), $count(10));
    }

    // ---------------------------------------------------------- one, and writes

    public function test_create_sets_names_job_titles_and_learners_in_one_request(): void
    {
        $jt = JobTitle::query()->create(['name' => 'Operations Manager']);
        $learner = User::factory()->create(['name' => 'Amal Al-Khateeb']);
        ['model' => $admin, 'headers' => $headers] = $this->adminToken();

        $result = $this->postJson(self::URL, [
            'name' => ['en' => ' Forklift ', 'ar' => 'رافعة'],
            'job_title_ids' => [$jt->id],
            'learner_ids' => [$learner->id],
        ], $headers)->assertCreated()->json('result');

        $this->assertSame(['en' => 'Forklift', 'ar' => 'رافعة'], $result['name']);
        $this->assertSame([$jt->id], array_column($result['job_titles'], 'id'));
        $this->assertSame([$learner->id], array_column($result['learners'], 'id'));
        $this->assertSame($learner->machine_code, $result['learners'][0]['employee_id']);
        $this->assertTrue($result['learners_editable']);
        $this->assertDatabaseHas('user_qualification_skill', [
            'user_id' => $learner->id, 'qualification_skill_id' => $result['id'], 'assigned_by' => $admin->id,
        ]);
    }

    public function test_assignments_are_optional(): void
    {
        ['headers' => $headers] = $this->adminToken();

        $id = $this->postJson(self::URL, ['name' => ['en' => 'Standalone', 'ar' => 'مستقل']], $headers)
            ->assertCreated()->json('result.id');

        $this->assertSame(0, QualificationSkill::query()->findOrFail($id)->jobTitles()->count());
    }

    public function test_a_name_in_use_is_refused_in_either_language_ignoring_case(): void
    {
        $this->skill('Excel', 'إكسل');
        ['headers' => $headers] = $this->adminToken();

        $this->postJson(self::URL, ['name' => ['en' => 'EXCEL', 'ar' => 'جديد']], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('name.en', 'errors');
        $this->postJson(self::URL, ['name' => ['en' => 'New', 'ar' => 'إكسل']], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('name.ar', 'errors');
        $this->assertSame(1, QualificationSkill::query()->count());
    }

    public function test_invalid_input_writes_nothing(): void
    {
        ['headers' => $headers] = $this->adminToken();

        $this->postJson(self::URL, ['name' => ['en' => '', 'ar' => 'س']], $headers)->assertStatus(422);
        $this->postJson(self::URL, ['name' => ['en' => str_repeat('a', 256), 'ar' => 'س']], $headers)->assertStatus(422);
        $this->postJson(self::URL, ['name' => ['en' => 'A', 'ar' => 'س'], 'job_title_ids' => [999999]], $headers)->assertStatus(422);
        $this->postJson(self::URL, ['name' => ['en' => 'A', 'ar' => 'س'], 'learner_ids' => [999999]], $headers)->assertStatus(422);
        $this->postJson(self::URL, ['name' => ['en' => 'A', 'ar' => 'س'], 'learner_ids' => 'all'], $headers)->assertStatus(422);

        $this->assertSame(0, QualificationSkill::query()->count());
    }

    public function test_update_sets_the_assignments_to_exactly_what_was_sent(): void
    {
        $q  = $this->skill('Excel', 'إكسل');
        $keep = JobTitle::query()->create(['name' => 'Keep']);
        $drop = JobTitle::query()->create(['name' => 'Drop']);
        $q->jobTitles()->attach([$keep->id, $drop->id]);
        $stays = User::factory()->create();
        $goes  = User::factory()->create();
        $new   = User::factory()->create();
        $this->grant($stays, $q);
        $this->grant($goes, $q);
        ['headers' => $headers] = $this->adminToken();

        $this->putJson(self::URL.'/'.$q->id, [
            'name' => ['en' => 'Excel', 'ar' => 'إكسل'], // its own name is not "taken"
            'job_title_ids' => [$keep->id],
            'learner_ids' => [$stays->id, $new->id],
        ], $headers)->assertOk();

        $this->assertSame([$keep->id], $q->jobTitles()->pluck('job_titles.id')->all());
        $held = DB::table('user_qualification_skill')->where('qualification_skill_id', $q->id)->orderBy('user_id')->pluck('user_id')->all();
        $this->assertSame([$stays->id, $new->id], $held);
    }

    public function test_update_without_assignment_keys_leaves_them_alone(): void
    {
        $q  = $this->skill('Excel');
        $jt = JobTitle::query()->create(['name' => 'Analyst']);
        $q->jobTitles()->attach($jt->id);
        $u = User::factory()->create();
        $this->grant($u, $q);
        ['headers' => $headers] = $this->adminToken();

        $this->putJson(self::URL.'/'.$q->id, ['name' => ['en' => 'Excel 2', 'ar' => 'إكسل ٢']], $headers)->assertOk()
            ->assertJsonPath('result.name.en', 'Excel 2');

        $this->assertSame(1, $q->jobTitles()->count());
        $this->assertSame(1, DB::table('user_qualification_skill')->where('qualification_skill_id', $q->id)->count());
    }

    public function test_show_returns_the_assignments_with_the_counts_the_modal_shows(): void
    {
        $q  = $this->skill('Excel');
        $jt = JobTitle::query()->create(['name' => 'Analyst', 'name_en' => 'Analyst', 'name_ar' => 'محلل']);
        $q->jobTitles()->attach($jt->id);
        User::factory()->count(2)->create(['job_title_id' => $jt->id]);
        $u = User::factory()->create();
        $this->grant($u, $q);
        ['headers' => $headers] = $this->adminToken();

        $this->getJson(self::URL.'/'.$q->id, $headers)->assertOk()
            ->assertJsonPath('result.job_titles.0.name', 'Analyst')
            ->assertJsonPath('result.job_titles.0.employees', 2)
            ->assertJsonPath('result.learners.0.id', $u->id)
            ->assertJsonPath('result.learners_total', 1);
        $this->getJson(self::URL.'/999999', $headers)->assertNotFound();
    }

    public function test_the_modal_search_returns_job_titles_and_learners(): void
    {
        $jt = JobTitle::query()->create(['name' => 'Operations Manager', 'name_en' => 'Operations Manager', 'name_ar' => 'مدير العمليات']);
        User::factory()->count(3)->create(['job_title_id' => $jt->id]);
        JobTitle::query()->create(['name' => 'Driver']);
        $omar = User::factory()->create(['name' => 'Omar Al-Farsi', 'machine_code' => 'EMP77']);
        ['headers' => $headers] = $this->adminToken();

        $r = $this->getJson(self::URL.'/assignees?search=oper', $headers)->assertOk()->json('result');
        $this->assertSame([['id' => $jt->id, 'name' => 'Operations Manager', 'employees' => 3]], $r['job_titles']);

        $r = $this->getJson(self::URL.'/assignees?search=emp77&type=learners', $headers)->assertOk()->json('result');
        $this->assertSame([], $r['job_titles']);
        $this->assertSame([['id' => $omar->id, 'name' => 'Omar Al-Farsi', 'employee_id' => 'EMP77']], $r['learners']);

        $this->getJson(self::URL.'/assignees?type=everyone', $headers)->assertStatus(422);
    }

    public function test_delete_removes_the_qualification_and_its_links_only(): void
    {
        $q = $this->skill('Excel');
        $course = $this->linkedCourse($q);
        $u = User::factory()->create();
        $this->grant($u, $q);
        ['headers' => $headers] = $this->adminToken();

        $this->deleteJson(self::URL.'/'.$q->id, [], $headers)->assertOk();

        $this->assertModelMissing($q);
        $this->assertSame(0, DB::table('user_qualification_skill')->count());
        $this->assertSame(0, DB::table('course_qualification_skills')->count());
        $this->assertModelExists($course);
        $this->assertModelExists($u);
    }

    // ------------------------------------------------------------------ access

    public function test_an_admin_without_view_qualifications_is_refused_everywhere(): void
    {
        $q = $this->skill('Excel');
        $admin = Admin::factory()->create();
        $admin->assignRole(tap(Role::findOrCreate('no-quals', 'admin'))->givePermissionTo(Permission::findOrCreate('view-dashboard', 'admin')));
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        ['headers' => $headers] = $this->adminToken($admin);

        $this->getJson(self::URL, $headers)->assertForbidden();
        $this->getJson(self::URL.'/assignees', $headers)->assertForbidden();
        $this->getJson(self::URL.'/'.$q->id, $headers)->assertForbidden();
        $this->postJson(self::URL, ['name' => ['en' => 'X', 'ar' => 'س']], $headers)->assertForbidden();
        $this->putJson(self::URL.'/'.$q->id, ['name' => ['en' => 'X', 'ar' => 'س']], $headers)->assertForbidden();
        $this->deleteJson(self::URL.'/'.$q->id, [], $headers)->assertForbidden();
        $this->assertModelExists($q);
    }

    public function test_a_learner_and_a_guest_are_refused(): void
    {
        $q = $this->skill('Excel');
        ['headers' => $headers] = $this->userToken();

        $this->getJson(self::URL, $headers)->assertForbidden();
        $this->postJson(self::URL, ['name' => ['en' => 'X', 'ar' => 'س']], $headers)->assertForbidden();
        $this->deleteJson(self::URL.'/'.$q->id, [], $headers)->assertForbidden();
        $this->getJson(self::URL)->assertUnauthorized();
        $this->assertSame(1, QualificationSkill::query()->count());
    }

    public function test_the_retired_generic_write_routes_are_gone(): void
    {
        $q = $this->skill('Excel');
        ['headers' => $headers] = $this->adminToken();

        $this->postJson(self::BASE.'/qualification-skills', ['name' => ['en' => 'X', 'ar' => 'س']], $headers)->assertStatus(405);
        $this->putJson(self::BASE.'/qualification-skills/'.$q->id, ['name' => ['en' => 'X', 'ar' => 'س']], $headers)->assertStatus(405);
        $this->deleteJson(self::BASE.'/qualification-skills/'.$q->id, [], $headers)->assertStatus(405);
        // The readers stay: other screens use them.
        $this->getJson(self::BASE.'/qualification-skills/active')->assertOk();
    }
}
