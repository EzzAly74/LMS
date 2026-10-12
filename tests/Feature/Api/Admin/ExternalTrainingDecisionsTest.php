<?php

namespace Tests\Feature\Api\Admin;

use App\Models\ExternalTrainingRequest;
use App\Models\User;
use App\Services\Mobile\QualificationProgressService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\ApiTestCase;

/**
 * External training review: the list finds a learner by the name the
 * Dashboard shows (NEW2B-6236), and approving with a qualification adds it to
 * the learner's profile as held (NEW2B-6240).
 */
class ExternalTrainingDecisionsTest extends ApiTestCase
{
    private const ADMIN = self::BASE.'/admin/external-training';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
    }

    private function request(User $user, string $title = 'Workplace Safety Essentials'): ExternalTrainingRequest
    {
        $r = new ExternalTrainingRequest([
            'title' => $title, 'provider' => 'Udemy',
            'start_date' => '2026-05-01', 'end_date' => '2026-05-10', 'hours' => 20, 'cost' => null, 'currency' => 'EGP',
            'certificate_path' => 'external-training/x.pdf', 'certificate_name' => 'x.pdf', 'certificate_mime' => 'application/pdf', 'certificate_size' => 1,
        ]);
        $r->user()->associate($user);
        $r->status = ExternalTrainingRequest::PENDING;
        $r->save();

        return $r;
    }

    public function test_the_list_finds_a_learner_by_their_english_or_arabic_name(): void
    {
        $sara = User::factory()->create(['name' => 'سارة علي', 'name_ar' => 'سارة علي', 'name_en' => 'Sara Ali']);
        $other = User::factory()->create(['name' => 'Omar', 'name_en' => 'Omar', 'name_ar' => 'عمر']);
        $mine = $this->request($sara);
        $this->request($other);
        ['headers' => $h] = $this->adminToken();

        foreach (['sara', 'Ali', 'سارة'] as $term) {
            $ids = array_column($this->getJson(self::ADMIN.'?search='.urlencode($term), $h)->assertOk()->json('result'), 'id');
            $this->assertSame([$mine->id], $ids, $term);
        }
    }

    public function test_an_approved_qualification_shows_on_the_profile_as_held(): void
    {
        $job = DB::table('job_titles')->insertGetId(['name' => 'Analyst '.uniqid(), 'created_at' => now(), 'updated_at' => now()]);
        $required = DB::table('qualification_skills')->insertGetId(['name' => json_encode(['en' => 'Required', 'ar' => 'مطلوب']), 'created_at' => now(), 'updated_at' => now()]);
        $extra = DB::table('qualification_skills')->insertGetId(['name' => json_encode(['en' => 'First Aid', 'ar' => 'الإسعافات الأولية']), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('job_title_qualification_skill')->insert(['job_title_id' => $job, 'qualification_skill_id' => $required]);
        $learner = User::factory()->create(['job_title_id' => $job]);
        $r = $this->request($learner);
        ['headers' => $h] = $this->adminToken();

        $this->postJson(self::ADMIN.'/'.$r->id.'/approve', ['qualification_skill_id' => $extra], $h)->assertOk();

        ['headers' => $l] = $this->userToken($learner);
        $rows = collect($this->getJson(self::BASE.'/learner/profile/qualifications', $l + ['Accept-Language' => 'en'])->assertOk()->json('result'));
        $rows = $rows->has('qualifications') ? collect($rows['qualifications']) : $rows;
        $this->assertSame([$required, $extra], $rows->pluck('id')->map(fn ($v) => (int) $v)->all());
        $held = $rows->firstWhere('id', $extra);
        $this->assertSame(100, $held['percent']);
        $this->assertTrue($held['granted']);
        $this->assertFalse($rows->firstWhere('id', $required)['granted']);

        // The mobile profile shows the same.
        $mobile = app(QualificationProgressService::class)->forUser($learner->refresh(), 'en');
        $this->assertSame(100, $mobile->firstWhere('id', $extra)['percent']);
    }

    public function test_the_completed_list_says_when_the_certificate_is_a_photo(): void
    {
        // NEW2B-6241: the card's picture is the learner's own certificate photo.
        $learner = User::factory()->create();
        $photo = $this->request($learner, 'Photo');
        $photo->forceFill(['certificate_mime' => 'image/png', 'status' => ExternalTrainingRequest::APPROVED])->save();
        $this->request($learner, 'Pdf')->forceFill(['status' => ExternalTrainingRequest::APPROVED])->save();
        ['headers' => $l] = $this->userToken($learner);

        $rows = collect($this->getJson(self::BASE.'/learner/profile/completed', $l)->assertOk()->json('result'))->where('kind', 'external');
        $this->assertTrue($rows->firstWhere('title', 'Photo')['certificate_is_image']);
        $this->assertFalse($rows->firstWhere('title', 'Pdf')['certificate_is_image']);
    }
}
