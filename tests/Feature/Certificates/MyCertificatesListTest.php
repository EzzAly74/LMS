<?php

namespace Tests\Feature\Certificates;

use App\Models\Course;
use App\Models\User;
use App\Models\UserCertificate;
use App\Services\CertificateService;
use Tests\Feature\Api\ApiTestCase;

/**
 * B-178: GET my/certificates and the dashboard count list the learner's
 * ACTIVE issued certificates, not exam passes or evaluation rows.
 */
class MyCertificatesListTest extends ApiTestCase
{
    public function test_only_active_issued_certificates_are_listed(): void
    {
        $user = User::factory()->create();
        $kept = Course::factory()->create(['certificate' => true]);
        $revoked = Course::factory()->create(['certificate' => true]);
        UserCertificate::factory()->create(['user_id' => $user->id, 'course_id' => $kept->id]);
        $gone = UserCertificate::factory()->create(['user_id' => $user->id, 'course_id' => $revoked->id]);
        app(CertificateService::class)->revoke($gone);
        // Another learner's certificate never shows.
        UserCertificate::factory()->create(['course_id' => $kept->id]);
        ['headers' => $headers] = $this->userToken($user);

        $rows = $this->getJson(self::BASE.'/my/certificates', $headers)->assertOk()->json('result');

        $this->assertSame([$kept->id], array_column($rows, 'course_id'));
    }
}
