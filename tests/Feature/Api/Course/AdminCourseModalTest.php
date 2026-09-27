<?php

namespace Tests\Feature\Api\Course;

use App\Models\Admin;
use App\Models\Category;
use App\Models\Course;
use App\Models\Instructor;
use App\Models\QualificationSkill;
use App\Services\CertificatePolicy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\ConfiguresCertificateRule;
use Tests\Feature\Api\ApiTestCase;

/**
 * D6 - the Add / Edit Course modal (Figma 2401:126596 / 2401:126340) on
 * POST / PUT /courses: both titles, level (kept, human 2026-09-27), image
 * required on create, and the certificate rule - general or the course's
 * own (D-058).
 */
class AdminCourseModalTest extends ApiTestCase
{
    use ConfiguresCertificateRule;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function instructor(): Instructor
    {
        return Instructor::query()->create([
            'name'  => ['en' => 'Teacher', 'ar' => 'معلم'],
            'email' => 'teacher'.uniqid().'@example.test',
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'title'            => ['en' => 'Workplace Safety Essentials', 'ar' => 'أساسيات السلامة في العمل'],
            'course_type'      => 'hybrid',
            'level'            => 'beginner',
            'instructors'      => [$this->instructor()->id],
            'certificate_rule' => 'general',
            'image'            => UploadedFile::fake()->image('course.png', 800, 400),
        ], $overrides);
    }

    private function create(array $payload, ?array $headers = null)
    {
        $headers ??= $this->adminToken()['headers'];

        return $this->withHeaders($headers)->post(self::BASE.'/courses', $payload, ['Accept' => 'application/json']);
    }

    private function edit(Course $course, array $payload, ?array $headers = null)
    {
        $headers ??= $this->adminToken()['headers'];

        return $this->withHeaders($headers)->post(
            self::BASE.'/courses/'.$course->id,
            ['_method' => 'PUT'] + $payload,
            ['Accept' => 'application/json'],
        );
    }

    // ── Create ─────────────────────────────────────────────────────────────

    public function test_admin_creates_a_course_on_the_general_rule(): void
    {
        $category = Category::factory()->create();
        $qual     = QualificationSkill::query()->create(['name' => ['en' => 'First Aid', 'ar' => 'الإسعافات الأولية']]);

        $res = $this->create($this->payload([
            'category_id'             => $category->id,
            'qualification_skill_ids' => [$qual->id],
            'description'             => ['en' => 'Overview', 'ar' => 'نظرة عامة'],
            'what_students_will_learn' => json_encode(['en' => ['Spot hazards'], 'ar' => ['تحديد المخاطر']]),
        ]));

        $this->assertCreated($res);
        $course = Course::query()->latest('id')->firstOrFail();
        $this->assertSame('Workplace Safety Essentials', $course->getTranslation('title', 'en'));
        $this->assertSame('أساسيات السلامة في العمل', $course->getTranslation('title', 'ar'));
        $this->assertSame('hybrid', $course->course_type);
        $this->assertSame('beginner', $course->level);
        $this->assertTrue((bool) $course->certificate, 'every course from the modal issues a certificate');
        $this->assertFalse($course->certificate_custom_rule);
        $this->assertNull($course->number_of_sessions);
        $this->assertSame(['Spot hazards'], $course->what_students_will_learn['en']);
        $this->assertSame(0, $course->sections()->count(), 'cohorts are created on Course Details now');
        $this->assertSame([$qual->id], $course->qualificationSkills()->pluck('qualification_skills.id')->all());
        $this->assertSame(1, $course->instructors()->count());
        Storage::disk('public')->assertExists($course->image);
    }

    public function test_description_is_optional(): void
    {
        $this->assertCreated($this->create($this->payload()));

        $course = Course::query()->latest('id')->firstOrFail();
        $this->assertSame('', (string) $course->getTranslation('description', 'en'));
    }

    public function test_a_custom_rule_stores_its_basis_and_thresholds(): void
    {
        $this->assertCreated($this->create($this->payload([
            'certificate_rule'           => 'both',
            'certificate_min_attendance' => 80,
            'certificate_min_score'      => 65,
        ])));

        $course = Course::query()->latest('id')->firstOrFail();
        $this->assertTrue($course->certificate_custom_rule);
        $this->assertSame('both', $course->certificate_mode);
        $this->assertSame(80, $course->certificate_attendance_threshold);
        $this->assertSame(65, $course->certificate_score_threshold);

        $show = $this->withHeaders($this->adminToken()['headers'])->getJson(self::BASE.'/courses/'.$course->id);
        $show->assertOk()
            ->assertJsonPath('result.certificate_rule', 'both')
            ->assertJsonPath('result.certificate_min_attendance', 80)
            ->assertJsonPath('result.certificate_min_score', 65)
            ->assertJsonPath('result.certificate_pass_percent', 65);
    }

    public function test_a_score_rule_drops_the_attendance_threshold(): void
    {
        $this->assertCreated($this->create($this->payload([
            'certificate_rule'           => 'score',
            'certificate_min_score'      => 70,
            'certificate_min_attendance' => 90,
        ])));

        $course = Course::query()->latest('id')->firstOrFail();
        $this->assertSame('score', $course->certificate_mode);
        $this->assertNull($course->certificate_attendance_threshold);
        $this->assertSame(70, $course->certificate_score_threshold);
    }

    public function test_the_general_rule_reports_no_course_thresholds(): void
    {
        $this->configureCertificateRule(CertificatePolicy::BASIS_SCORE, 70, 40);
        $this->assertCreated($this->create($this->payload()));
        $course = Course::query()->latest('id')->firstOrFail();

        $this->withHeaders($this->adminToken()['headers'])->getJson(self::BASE.'/courses/'.$course->id)
            ->assertOk()
            ->assertJsonPath('result.certificate_rule', 'general')
            ->assertJsonPath('result.certificate_min_attendance', null)
            ->assertJsonPath('result.certificate_min_score', null)
            ->assertJsonPath('result.certificate_pass_percent', 40);
    }

    // ── Validation ─────────────────────────────────────────────────────────

    public function test_both_titles_type_level_instructor_image_and_rule_are_required(): void
    {
        $res = $this->create(['title' => ['en' => 'Only English']]);

        $res->assertStatus(422)->assertJsonValidationErrors([
            'title.ar', 'course_type', 'level', 'instructors', 'image', 'certificate_rule',
        ]);
    }

    public function test_a_custom_rule_needs_the_thresholds_it_uses(): void
    {
        $this->create($this->payload(['certificate_rule' => 'attendance']))
            ->assertStatus(422)->assertJsonValidationErrors(['certificate_min_attendance']);

        $this->create($this->payload(['certificate_rule' => 'both', 'certificate_min_attendance' => 70]))
            ->assertStatus(422)->assertJsonValidationErrors(['certificate_min_score']);

        $this->create($this->payload(['certificate_rule' => 'score', 'certificate_min_score' => 0]))
            ->assertStatus(422)->assertJsonValidationErrors(['certificate_min_score']);

        $this->create($this->payload(['certificate_rule' => 'score', 'certificate_min_score' => 101]))
            ->assertStatus(422)->assertJsonValidationErrors(['certificate_min_score']);

        $this->create($this->payload(['certificate_rule' => 'sometimes']))
            ->assertStatus(422)->assertJsonValidationErrors(['certificate_rule']);

        $this->assertSame(0, Course::query()->count());
    }

    public function test_svg_is_rejected_by_content_even_named_png(): void
    {
        $svg = tempnam(sys_get_temp_dir(), 'svg');
        file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $file = new UploadedFile($svg, 'logo.png', 'image/png', null, true);

        $this->create($this->payload(['image' => $file]))
            ->assertStatus(422)->assertJsonValidationErrors(['image']);
        $this->assertSame(0, Course::query()->count());
    }

    public function test_images_over_3_mb_are_rejected_and_3_mb_passes(): void
    {
        $this->create($this->payload(['image' => UploadedFile::fake()->image('big.jpg')->size(3073)]))
            ->assertStatus(422)->assertJsonValidationErrors(['image']);

        $this->assertCreated($this->create($this->payload(['image' => UploadedFile::fake()->image('ok.jpg')->size(3072)])));
    }

    public function test_unknown_instructor_or_qualification_is_rejected(): void
    {
        $this->create($this->payload(['instructors' => [999999]]))
            ->assertStatus(422)->assertJsonValidationErrors(['instructors.0']);

        $this->create($this->payload(['qualification_skill_ids' => [999999]]))
            ->assertStatus(422)->assertJsonValidationErrors(['qualification_skill_ids.0']);
    }

    // ── Access ─────────────────────────────────────────────────────────────

    public function test_learners_guests_and_admins_without_the_permission_cannot_create(): void
    {
        $this->create($this->payload(), [])->assertStatus(401);

        $this->create($this->payload(), $this->userToken()['headers'])->assertStatus(403);

        $admin = Admin::factory()->create();
        $role  = Role::findOrCreate('no-courses-'.uniqid(), 'admin');
        $role->givePermissionTo(Permission::findOrCreate('view-learners', 'admin'));
        $admin->assignRole($role);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->create($this->payload(), $this->adminToken($admin)['headers'])->assertStatus(403);

        $this->assertSame(0, Course::query()->count());
    }

    // ── Edit ───────────────────────────────────────────────────────────────

    public function test_edit_switches_between_the_general_and_a_custom_rule(): void
    {
        $this->assertCreated($this->create($this->payload()));
        $course = Course::query()->latest('id')->firstOrFail();
        $edit   = ['title' => ['en' => 'Renamed', 'ar' => 'اسم جديد'], 'instructors' => [$this->instructor()->id]];

        $this->assertSuccess($this->edit($course, $edit + ['certificate_rule' => 'attendance', 'certificate_min_attendance' => 75]));
        $course->refresh();
        $this->assertTrue($course->certificate_custom_rule);
        $this->assertSame('attendance', $course->certificate_mode);
        $this->assertSame(75, $course->certificate_attendance_threshold);
        $this->assertNull($course->certificate_score_threshold);
        $this->assertSame('Renamed', $course->getTranslation('title', 'en'));

        $this->assertSuccess($this->edit($course, $edit + ['certificate_rule' => 'general']));
        $this->assertFalse($course->refresh()->certificate_custom_rule);
    }

    public function test_edit_keeps_flags_it_does_not_send(): void
    {
        // B-118: saving the Edit dialog used to switch these off.
        $course = Course::factory()->create(['is_evaluate' => true, 'allow_attendances' => true, 'outside_materials' => true]);

        $this->assertSuccess($this->edit($course, [
            'title'            => ['en' => 'Kept', 'ar' => 'محفوظ'],
            'instructors'      => [$this->instructor()->id],
            'certificate_rule' => 'general',
        ]));

        $course->refresh();
        $this->assertTrue((bool) $course->is_evaluate);
        $this->assertTrue((bool) $course->allow_attendances);
        $this->assertTrue((bool) $course->outside_materials);
    }

    public function test_edit_keeps_the_image_unless_replaced_and_removes_a_replaced_one(): void
    {
        $this->assertCreated($this->create($this->payload()));
        $course = Course::query()->latest('id')->firstOrFail();
        $first  = $course->image;
        $edit   = ['title' => ['en' => 'T', 'ar' => 'ت'], 'instructors' => [$this->instructor()->id]];

        $this->assertSuccess($this->edit($course, $edit));
        $this->assertSame($first, $course->refresh()->image);

        $this->assertSuccess($this->edit($course, $edit + ['image' => UploadedFile::fake()->image('new.png', 600, 300)]));
        $course->refresh();
        $this->assertNotSame($first, $course->image);
        Storage::disk('public')->assertExists($course->image);
        Storage::disk('public')->assertMissing($first);
    }

    // ── The rule is enforced where certificates are judged ─────────────────

    public function test_a_course_rule_overrides_the_general_rule_when_judging(): void
    {
        $this->configureCertificateRule(CertificatePolicy::BASIS_ATTENDANCE, 70, 30);
        $policy = app(CertificatePolicy::class);

        $general = Course::factory()->create();
        $own     = Course::factory()->create([
            'certificate_custom_rule'          => true,
            'certificate_mode'                 => 'score',
            'certificate_score_threshold'      => 80,
            'certificate_attendance_threshold' => null,
        ]);
        // A legacy row: mode columns set, flag off - it must keep the general rule.
        $legacy = Course::factory()->create(['certificate_mode' => 'score', 'certificate_score_threshold' => 99]);

        $this->assertSame('attendance', $policy->forCourse($general)->basis);
        $this->assertSame('attendance', $policy->forCourse($legacy)->basis);

        $rule = $policy->forCourse($own);
        $this->assertSame(['score'], $rule->requiredMetrics());
        $this->assertFalse($rule->isSatisfiedBy(100, 79));
        $this->assertTrue($rule->isSatisfiedBy(0, 80));
        $this->assertTrue($policy->forCourse($general)->isSatisfiedBy(70, 0));
    }

    // ── Cohorts on a course with no session plan ───────────────────────────

    public function test_a_cohort_of_a_course_without_a_session_plan_must_state_one(): void
    {
        $this->assertCreated($this->create($this->payload()));
        $course  = Course::query()->latest('id')->firstOrFail();
        $headers = $this->adminToken()['headers'];
        $cohort  = ['name' => ['en' => 'Cohort 1', 'ar' => 'الدفعة 1'], 'start_date' => '2026-10-01', 'end_date' => '2026-10-30'];

        $this->withHeaders($headers)->postJson(self::BASE.'/courses/'.$course->id.'/sections', $cohort)
            ->assertStatus(422)->assertJsonValidationErrors(['number_of_sessions']);

        $this->withHeaders($headers)->postJson(self::BASE.'/courses/'.$course->id.'/sections', $cohort + ['number_of_sessions' => 6])
            ->assertStatus(201);
        $this->assertSame(6, (int) $course->sections()->value('number_of_sessions'));
    }
}
