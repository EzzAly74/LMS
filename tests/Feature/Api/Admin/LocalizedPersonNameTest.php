<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Admin;
use App\Models\Course;
use App\Models\Evaluation;
use App\Models\EvaluationCategory;
use App\Models\User;
use App\Models\UserCertificate;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\ApiTestCase;

/**
 * People are named in the reader's language. users.name is HR's (usually
 * Arabic) name; name_en / name_ar are the per-language ones. Admins carry
 * name (English / main) and name_ar. Raw queries used to select the HR name,
 * so the English Dashboard showed Arabic learner names.
 */
class LocalizedPersonNameTest extends ApiTestCase
{
    private const HR_NAME = 'عز الدين على عبد الله السيد';
    private const EN_NAME = 'Ezz El-Din Ali Abdullah Al-Sayed';
    private const AR_NAME = 'عز الدين علي';

    private function learner(): User
    {
        return User::factory()->create([
            'name'    => self::HR_NAME,
            'name_en' => self::EN_NAME,
            'name_ar' => self::AR_NAME,
        ]);
    }

    /** One scaled evaluation answer by `$user`, stored as UserCourseEvaluationService writes it. */
    private function evaluated(User $user): Course
    {
        $course   = Course::factory()->create(['title' => ['en' => 'Leadership', 'ar' => 'القيادة']]);
        $template = EvaluationCategory::query()->create(['name' => ['en' => 'Feedback', 'ar' => 'تقييم']]);
        $question = Evaluation::query()->create([
            'evaluation_category_id' => $template->id,
            'type'                   => 'five',
            'title'                  => 'Q',
            'is_required'            => true,
        ]);

        DB::table('user_course_evaluations')->insert([
            'user_id'                  => $user->id,
            'user_machine_code'        => 'EMP-'.$user->id,
            'user_department'          => 'Ops',
            'course_id'                => $course->id,
            'course_name'              => 'Leadership',
            'instructor_id'            => 1,
            'instructor_name'          => 'Snapshot',
            'evaluation_category_id'   => $template->id,
            'evaluation_category_name' => 'Feedback',
            'evaluation_id'            => $question->id,
            'evaluation_title'         => 'Q',
            'evaluation_type'          => 5,
            'answer'                   => '4',
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);

        return $course;
    }

    private function fetch(string $path, string $locale, ?array $headers = null): array
    {
        $headers ??= $this->adminToken()['headers'];

        return $this->getJson(self::BASE.$path, $headers + ['Accept-Language' => $locale])->assertOk()->json('result');
    }

    public function test_evaluation_scores_and_submission_name_the_learner_in_the_request_language(): void
    {
        $user   = $this->learner();
        $course = $this->evaluated($user);
        ['headers' => $h] = $this->adminToken();

        $en = collect($this->fetch('/admin/evaluations/scores', 'en', $h))->firstWhere('learner.id', $user->id);
        $ar = collect($this->fetch('/admin/evaluations/scores', 'ar', $h))->firstWhere('learner.id', $user->id);
        $this->assertSame(self::EN_NAME, $en['learner']['name']);
        $this->assertSame(self::AR_NAME, $ar['learner']['name']);

        // The learner page (breadcrumb + title).
        $this->assertSame(self::EN_NAME, $this->fetch("/admin/evaluations/scores/{$user->id}/{$course->id}", 'en', $h)['learner']['name']);
        $this->assertSame(self::AR_NAME, $this->fetch("/admin/evaluations/scores/{$user->id}/{$course->id}", 'ar', $h)['learner']['name']);
    }

    public function test_a_learner_without_language_names_falls_back_to_the_hr_name(): void
    {
        $user = User::factory()->create(['name' => self::HR_NAME, 'name_en' => null, 'name_ar' => '']);
        $this->evaluated($user);

        $row = collect($this->fetch('/admin/evaluations/scores', 'en'))->firstWhere('learner.id', $user->id);
        $this->assertSame(self::HR_NAME, $row['learner']['name']);
    }

    public function test_name_searches_match_the_english_name(): void
    {
        $user  = $this->learner();
        $other = User::factory()->create(['name' => 'Someone Else', 'name_en' => 'Someone Else']);
        $this->evaluated($user);
        $this->evaluated($other);
        ['headers' => $h] = $this->adminToken();

        $rows = $this->fetch('/admin/evaluations/scores?search=Abdullah', 'en', $h);
        $this->assertSame([$user->id], array_column(array_column($rows, 'learner'), 'id'));

        $options = $this->fetch('/admin/evaluations/learner-options?search=Abdullah', 'en', $h);
        $this->assertSame([$user->id], array_column($options, 'id'));
        $this->assertSame(self::EN_NAME, $options[0]['name']);
        $this->assertSame(self::AR_NAME, $this->fetch('/admin/evaluations/learner-options?search=Abdullah', 'ar', $h)[0]['name']);

        // A LIKE wildcard is a literal, not "everyone".
        $this->assertSame([], $this->fetch('/admin/evaluations/scores?search=%25', 'en', $h));
    }

    public function test_report_exports_name_the_learner_in_the_request_language(): void
    {
        $user   = $this->learner();
        $course = Course::factory()->create(['certificate' => true]);
        UserCertificate::factory()->create(['user_id' => $user->id, 'course_id' => $course->id]);
        ['headers' => $h] = $this->adminToken();

        $learners = function (string $locale) use ($h): array {
            $csv  = $this->get(self::BASE.'/admin/reports/certificate-status/export?format=csv', $h + ['Accept-Language' => $locale])
                ->assertOk()->streamedContent();
            $rows = array_map('str_getcsv', array_values(array_filter(preg_split('/\r?\n/', preg_replace('/^\xEF\xBB\xBF/', '', $csv)), fn ($l) => $l !== '')));
            array_shift($rows);

            return array_column($rows, 0);
        };

        $this->assertSame([self::EN_NAME], $learners('en'));
        $this->assertSame([self::AR_NAME], $learners('ar'));
    }

    public function test_the_certificates_list_loads_the_language_names(): void
    {
        $user = $this->learner();
        UserCertificate::factory()->create(['user_id' => $user->id]);
        ['headers' => $h] = $this->adminToken();

        $this->assertSame(self::EN_NAME, $this->fetch('/admin/certificates', 'en', $h)['data'][0]['learner_name']);
        $this->assertSame(self::AR_NAME, $this->fetch('/admin/certificates', 'ar', $h)['data'][0]['learner_name']);
    }

    public function test_the_signed_in_admin_has_a_display_name_per_language(): void
    {
        $admin = Admin::factory()->create(['name' => 'Mohamed Saeed', 'name_ar' => 'محمد سعيد']);
        ['headers' => $h] = $this->adminToken($admin);

        $en = $this->fetch('/auth/admin/me', 'en', $h);
        $ar = $this->fetch('/auth/admin/me', 'ar', $h);

        // `name` stays the stored value the account forms edit.
        $this->assertSame('Mohamed Saeed', $en['name']);
        $this->assertSame('Mohamed Saeed', $ar['name']);
        $this->assertSame('Mohamed Saeed', $en['display_name']);
        $this->assertSame('محمد سعيد', $ar['display_name']);

        // Without an Arabic name the main one is shown in both languages.
        $plain = Admin::factory()->create(['name' => 'Plain Admin', 'name_ar' => null]);
        $this->assertSame('Plain Admin', $this->fetch('/auth/admin/me', 'ar', $this->adminToken($plain)['headers'])['display_name']);
    }

    public function test_the_signed_in_admin_carries_an_absolute_photo_url_or_null(): void
    {
        $withPhoto = Admin::factory()->create(['image' => 'admins/photo.png']);
        $image = $this->fetch('/auth/admin/me', 'en', $this->adminToken($withPhoto)['headers'])['image'];
        $this->assertMatchesRegularExpression('#^https?://.+/admins/photo\.png$#', $image);

        $without = Admin::factory()->create(['image' => null]);
        $this->assertNull($this->fetch('/auth/admin/me', 'en', $this->adminToken($without)['headers'])['image']);
    }

    public function test_the_audit_log_names_the_actor_in_the_request_language(): void
    {
        $admin = Admin::factory()->create(['name' => 'Mohamed Saeed', 'name_ar' => 'محمد سعيد']);
        DB::table('audit_logs')->insert([
            'user_type'   => 'admin',
            'user_id'     => $admin->id,
            'user_name'   => 'Snapshot Name',
            'action'      => 'updated',
            'model_type'  => 'App\\Models\\Course',
            'model_id'    => 1,
            'description' => 'Course',
            'ip_address'  => '127.0.0.1',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
        ['headers' => $h] = $this->adminToken();

        $actor = fn (string $locale) => collect($this->fetch('/admin/audit-log', $locale, $h))
            ->firstWhere('actor_id', $admin->id)['actor_name'];

        $this->assertSame('Mohamed Saeed', $actor('en'));
        $this->assertSame('محمد سعيد', $actor('ar'));
    }
}
