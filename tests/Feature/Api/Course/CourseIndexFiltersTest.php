<?php

namespace Tests\Feature\Api\Course;

use App\Models\Category;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\EvaluationCategory;
use App\Models\Instructor;
use App\Models\User;
use App\Models\UserCertificate;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\ApiTestCase;

/**
 * The All Courses "Filter" modal (Figma 2430:135164 / 2430:134497): several
 * courses, categories, instructors, statuses and evaluation bands at once -
 * OR within a field, AND across fields - on GET /courses. Also the list's
 * Completion column.
 */
class CourseIndexFiltersTest extends ApiTestCase
{
    private function course(string $en, array $attrs = []): Course
    {
        return Course::factory()->create(['title' => ['en' => $en, 'ar' => $en]] + $attrs);
    }

    /** @return list<int> the ids the list returned for this query string */
    private function ids(string $query): array
    {
        ['headers' => $headers] = $this->adminToken();

        return collect($this->getJson(self::BASE.'/courses?per_page=100&'.$query, $headers)->assertOk()->json('result'))
            ->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    }

    private function scored(Course $course, int $answer): void
    {
        $template = EvaluationCategory::query()->create(['name' => ['en' => 'T', 'ar' => 'T']]);
        $question = $template->evaluations()->create(['type' => 'five', 'title' => ['en' => 'Q', 'ar' => 'Q'], 'is_required' => true]);
        DB::table('user_course_evaluations')->insert([
            'user_id' => User::factory()->create()->id, 'course_id' => $course->id, 'instructor_id' => 1,
            'evaluation_category_id' => $template->id, 'evaluation_id' => $question->id,
            'evaluation_type' => 5, 'answer' => (string) $answer, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function cohort(Course $course, string $from, string $to): void
    {
        CourseSection::factory()->create([
            'course_id' => $course->id, 'start_date' => $from, 'end_date' => $to, 'status' => null,
        ]);
    }

    public function test_several_ids_categories_and_instructors_each_match_any(): void
    {
        [$catA, $catB, $catC] = Category::factory()->count(3)->create()->all();
        $a = $this->course('A', ['category_id' => $catA->id]);
        $b = $this->course('B', ['category_id' => $catB->id]);
        $c = $this->course('C', ['category_id' => $catC->id]);

        $this->assertSame([$a->id, $c->id], $this->ids("ids[]={$a->id}&ids[]={$c->id}"));
        $this->assertSame([$a->id, $b->id], $this->ids("category_ids[]={$catA->id}&category_ids[]={$catB->id}"));

        $t1 = Instructor::query()->create(['name' => ['en' => 'T1', 'ar' => 'T1'], 'email' => 't1'.uniqid().'@example.test']);
        $t2 = Instructor::query()->create(['name' => ['en' => 'T2', 'ar' => 'T2'], 'email' => 't2'.uniqid().'@example.test']);
        $a->instructors()->attach($t1->id);
        $b->instructors()->attach($t2->id);
        $c->instructors()->attach([$t1->id, $t2->id]); // two instructors: listed once

        $this->assertSame([$a->id, $b->id, $c->id], $this->ids("instructor_ids[]={$t1->id}&instructor_ids[]={$t2->id}"));
        // AND across fields.
        $this->assertSame([$a->id], $this->ids("instructor_ids[]={$t1->id}&category_ids[]={$catA->id}"));
    }

    public function test_several_statuses_match_any_of_them(): void
    {
        $active   = $this->course('Active');
        $upcoming = $this->course('Upcoming');
        $inactive = $this->course('Inactive');
        $this->cohort($active, now()->subDay()->toDateString(), now()->addDay()->toDateString());
        $this->cohort($upcoming, now()->addWeek()->toDateString(), now()->addWeeks(2)->toDateString());
        $this->cohort($inactive, now()->subWeeks(2)->toDateString(), now()->subWeek()->toDateString());
        $scope = "ids[]={$active->id}&ids[]={$upcoming->id}&ids[]={$inactive->id}";

        $this->assertSame([$active->id, $upcoming->id], $this->ids("$scope&statuses[]=active&statuses[]=upcoming"));
        $this->assertSame([$inactive->id], $this->ids("$scope&statuses[]=inactive"));
    }

    public function test_evaluation_bands_follow_the_rounded_score(): void
    {
        $high  = $this->course('High');  // 4.5
        $four  = $this->course('Four');  // exactly 4.0 is high
        $mid   = $this->course('Mid');   // 3.0
        $low   = $this->course('Low');   // 2.0
        $never = $this->course('Never'); // not evaluated
        foreach ([[$high, 4], [$high, 5], [$four, 4], [$mid, 3], [$low, 2]] as [$course, $answer]) {
            $this->scored($course, $answer);
        }
        $scope = collect([$high, $four, $mid, $low, $never])->map(fn ($c) => "ids[]={$c->id}")->implode('&');

        $this->assertSame([$high->id, $four->id], $this->ids("$scope&evaluation[]=high"));
        $this->assertSame([$mid->id], $this->ids("$scope&evaluation[]=mid"));
        $this->assertSame([$low->id, $never->id], $this->ids("$scope&evaluation[]=low&evaluation[]=none"));

        // The joined score does not replace the list's own columns.
        ['headers' => $headers] = $this->adminToken();
        $row = $this->getJson(self::BASE."/courses?ids[]={$high->id}&evaluation[]=high", $headers)->json('result.0');
        $this->assertSame($high->id, (int) $row['id']);
        $this->assertEqualsWithDelta(4.5, $row['evaluation_score'], 0.001);
    }

    /** Completion = enrolled learners holding an active certificate (Figma annotation on the column). */
    public function test_completion_is_the_share_of_enrolled_learners_certified(): void
    {
        $course   = $this->course('Certified');
        $empty    = $this->course('Nobody Enrolled');
        $learners = User::factory()->count(4)->create();
        foreach ($learners as $u) {
            DB::table('users_courses')->insert(['user_id' => $u->id, 'course_id' => $course->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        UserCertificate::factory()->create(['user_id' => $learners[0]->id, 'course_id' => $course->id]);
        UserCertificate::factory()->create(['user_id' => $learners[1]->id, 'course_id' => $course->id, 'status' => UserCertificate::STATUS_REVOKED]);
        // A certificate for another course does not count here.
        UserCertificate::factory()->create(['user_id' => $learners[2]->id, 'course_id' => $empty->id]);

        ['headers' => $headers] = $this->adminToken();
        $rows = collect($this->getJson(self::BASE."/courses?ids[]={$course->id}&ids[]={$empty->id}", $headers)->assertOk()->json('result'))->keyBy('id');

        $this->assertSame(25, $rows[$course->id]['completion_percent']);
        $this->assertSame(4, $rows[$course->id]['users_count']);
        $this->assertSame(0, $rows[$empty->id]['completion_percent']);
    }

    public function test_invalid_filters_are_rejected(): void
    {
        ['headers' => $headers] = $this->adminToken();

        $this->getJson(self::BASE.'/courses?statuses[]=deleted', $headers)->assertStatus(422);
        $this->getJson(self::BASE.'/courses?evaluation[]=great', $headers)->assertStatus(422);
        $this->getJson(self::BASE.'/courses?ids[]=abc', $headers)->assertStatus(422);
        $this->getJson(self::BASE.'/courses?'.http_build_query(['ids' => range(1, 101)]), $headers)->assertStatus(422);
    }

    public function test_the_list_still_needs_a_signed_in_user(): void
    {
        $this->getJson(self::BASE.'/courses?statuses[]=active')->assertStatus(401);
    }
}
