<?php

namespace Tests\Feature\Api;

use App\Mail\ContactAutoReply;
use App\Mail\ContactGuestInvite;
use App\Mail\ContactNotification;
use App\Models\ContactRequest;
use Illuminate\Support\Facades\Mail;

/**
 * Public Book a Demo (POST contact).
 * NEW2B-5898: every guest the requester adds gets an invite.
 * NEW2B-5867: addresses that cannot be mailed are field errors.
 */
class ContactRequestTest extends ApiTestCase
{
    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Hesham Adly', 'email' => 'hesham@company.test', 'phone' => '0100',
            'job_title' => 'L&D Manager', 'company_name' => 'Acme', ...$overrides,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['contact.email' => 'sales@nas.test']);
    }

    /** The visible text of an email: tags and the Unicode isolate marks removed, spaces collapsed. */
    private function text(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', str_replace(["\u{2068}", "\u{2069}"], '', $text)));
    }

    /**
     * NEW2B-5899: the Arabic emails read right to left in Gmail, which drops
     * dir on <html> / <body>: every cell carries dir="rtl" and right
     * alignment, and each typed value is isolated in its own direction.
     */
    public function test_the_arabic_emails_read_right_to_left_with_typed_values_isolated(): void
    {
        $contact = ContactRequest::query()->create($this->payload(['company_name' => '2B', 'job_title' => 'Software Engineer', 'guests' => ['mona@company.test']]));

        foreach ([new ContactAutoReply($contact, 'ar'), new ContactGuestInvite($contact, 'ar'), new ContactNotification($contact, 'ar')] as $mail) {
            $html = $mail->render();
            $this->assertMatchesRegularExpression('/<td dir="rtl" style="[^"]*text-align:right;/', $html, $mail::class);
            $this->assertStringNotContainsString('text-align:left', $html, $mail::class);
            // "2B" and the email stay whole, isolated left to right inside the Arabic text.
            $this->assertStringContainsString('<span dir="ltr">'."\u{2068}".'hesham@company.test'."\u{2069}".'</span>', $html, $mail::class);
            $this->assertStringContainsString('<span dir="ltr">'."\u{2068}".'2B'."\u{2069}".'</span>', $html, $mail::class);
        }

        $reply = $this->text((new ContactAutoReply($contact, 'ar'))->render());
        $this->assertStringContainsString('مرحبًا Hesham Adly،', $reply);
        $this->assertStringContainsString('الشركة: 2B', $reply);
        $this->assertStringContainsString('مع تحيات فريق NAS LMS', $reply);
    }

    public function test_an_arabic_name_is_isolated_right_to_left_and_english_stays_left_aligned(): void
    {
        $contact = ContactRequest::query()->create($this->payload(['name' => 'هشام عدلي']));

        $this->assertStringContainsString('<span dir="rtl">'."\u{2068}".'هشام عدلي'."\u{2069}".'</span>', (new ContactAutoReply($contact, 'ar'))->render());

        $en = (new ContactAutoReply($contact, 'en'))->render();
        $this->assertStringContainsString('text-align:left', $en);
        $this->assertStringNotContainsString('dir="rtl"', str_replace('<span dir="rtl">', '', $en));
    }

    public function test_each_guest_gets_one_invite_and_the_requester_and_company_are_mailed(): void
    {
        Mail::fake();

        $this->postJson(self::BASE.'/contact', $this->payload([
            'guests' => [' Mona@Company.test ', 'mona@company.test', 'omar@company.test', 'hesham@company.test', ''],
        ]), ['Accept-Language' => 'ar'])->assertCreated();

        $this->assertSame(['mona@company.test', 'omar@company.test', 'hesham@company.test'], ContactRequest::query()->sole()->guests);

        Mail::assertQueued(ContactGuestInvite::class, 2);
        Mail::assertQueued(ContactGuestInvite::class, fn ($m) => $m->hasTo('mona@company.test') && $m->lang === 'ar');
        Mail::assertQueued(ContactGuestInvite::class, fn ($m) => $m->hasTo('omar@company.test'));
        // The requester is not invited as their own guest; they get the auto-reply.
        Mail::assertNotQueued(ContactGuestInvite::class, fn ($m) => $m->hasTo('hesham@company.test'));
        Mail::assertQueued(ContactAutoReply::class, fn ($m) => $m->hasTo('hesham@company.test'));
        Mail::assertQueued(ContactNotification::class, fn ($m) => $m->hasTo('sales@nas.test'));
    }

    public function test_the_guest_invite_names_who_invited_them_in_their_language(): void
    {
        $contact = ContactRequest::query()->create($this->payload(['guests' => ['mona@company.test'], 'locale' => 'en']));

        $html = (new ContactGuestInvite($contact, 'en'))->render();
        $this->assertStringContainsString('Hesham Adly from Acme added you as a guest', $this->text($html));
        $this->assertStringContainsString('hesham@company.test', $html);

        $ar = new ContactGuestInvite($contact, 'ar');
        $this->assertStringContainsString('dir="rtl"', $ar->render());
        $ar->assertHasReplyTo('hesham@company.test');
    }

    public function test_the_invite_escapes_what_the_requester_typed(): void
    {
        $contact = ContactRequest::query()->create($this->payload(['name' => '<a href="https://evil.test">Win</a>']));

        $html = (new ContactGuestInvite($contact, 'en'))->render();
        $this->assertStringNotContainsString('<a href="https://evil.test">', $html);
        $this->assertStringContainsString('&lt;a href=', $html);
    }

    public function test_addresses_that_cannot_be_mailed_are_field_errors(): void
    {
        Mail::fake();

        $this->postJson(self::BASE.'/contact', $this->payload(['email' => 'hesham@company']))
            ->assertUnprocessable()->assertJsonValidationErrors(['email']);
        $this->postJson(self::BASE.'/contact', $this->payload(['email' => 'not an email']))
            ->assertUnprocessable()->assertJsonValidationErrors(['email']);
        $this->postJson(self::BASE.'/contact', $this->payload(['guests' => ['ok@company.test', 'mona@']]))
            ->assertUnprocessable()->assertJsonValidationErrors(['guests.1']);
        // At most five guests (human, 2026-10-07).
        $this->postJson(self::BASE.'/contact', $this->payload(['guests' => array_map(fn ($i) => "g{$i}@company.test", range(1, 6))]))
            ->assertUnprocessable()->assertJsonValidationErrors(['guests']);
        $this->postJson(self::BASE.'/contact', $this->payload(['name' => '']))
            ->assertUnprocessable()->assertJsonValidationErrors(['name']);

        $this->assertSame(0, ContactRequest::query()->count());
        Mail::assertNothingQueued();
    }

    public function test_a_failing_mail_does_not_stop_the_others_or_the_request(): void
    {
        Mail::shouldReceive('to')->andReturnUsing(function (string $to) {
            $pending = \Mockery::mock();
            $pending->shouldReceive('queue')->andReturnUsing(function () use ($to) {
                if ($to === 'mona@company.test') {
                    throw new \RuntimeException('smtp down');
                }
                $GLOBALS['contact_test_sent'][] = $to;
            });

            return $pending;
        });
        $GLOBALS['contact_test_sent'] = [];

        $this->postJson(self::BASE.'/contact', $this->payload(['guests' => ['mona@company.test', 'omar@company.test']]))
            ->assertCreated();

        $this->assertSame(['hesham@company.test', 'omar@company.test', 'sales@nas.test'], $GLOBALS['contact_test_sent']);
        $this->assertSame(1, ContactRequest::query()->count());
    }

    public function test_submissions_are_rate_limited_per_address(): void
    {
        Mail::fake();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson(self::BASE.'/contact', $this->payload())->assertCreated();
        }
        $this->postJson(self::BASE.'/contact', $this->payload())->assertStatus(429);
    }
}
