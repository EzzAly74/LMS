<?php

namespace Tests\Feature\Api;

use App\Events\MessageSent;
use App\Models\Admin;
use App\Models\Conversation;
use App\Models\Instructor;
use App\Models\User;
use App\Services\MessageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * The shared inbox list (GET conversations), its unread badge and the
 * Dashboard broadcast.
 *
 * NEW2B-5905: the list was cut to one page, and the tab / role filters ran
 * after the page was taken, so older messages could not be reached.
 * NEW2B-5904: a broadcast to many learners ran about 25 queries per learner.
 * NEW2B-5906: a learner's name came from the HR Arabic column in English.
 */
class ConversationInboxTest extends ApiTestCase
{
    private function service(): MessageService
    {
        return app(MessageService::class);
    }

    /** `$count` learners, each messaged by `$admin`; returns the conversations, oldest first. */
    private function conversationsWith(Admin $admin, int $count): array
    {
        $out = [];
        foreach (User::factory()->count($count)->create() as $learner) {
            $message = $this->service()->start($admin, User::class, $learner->id, null, 'Hello '.$learner->id);
            $out[] = ['learner' => $learner, 'conversation' => Conversation::find($message->conversation_id)];
        }

        return $out;
    }

    public function test_the_inbox_is_paginated_with_page_meta_and_newest_first(): void
    {
        $admin = $this->adminToken();
        $rows = $this->conversationsWith($admin['model'], 35);

        $first = $this->getJson(self::BASE.'/conversations?per_page=15', $admin['headers'])->assertOk();
        $first->assertJsonCount(15, 'result')
            ->assertJsonPath('meta.total', 35)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('result.0.id', $rows[34]['conversation']->id);

        $last = $this->getJson(self::BASE.'/conversations?per_page=15&page=3', $admin['headers'])->assertOk();
        $last->assertJsonCount(5, 'result')->assertJsonPath('result.4.id', $rows[0]['conversation']->id);
    }

    public function test_tabs_filter_before_the_page_is_taken(): void
    {
        $admin = $this->adminToken();
        $rows = $this->conversationsWith($admin['model'], 12);

        // Five learners answer: those threads are unread and received for the admin.
        foreach (array_slice($rows, 0, 5) as $row) {
            $this->service()->reply($row['learner'], $row['conversation'], 'Thanks');
        }

        $this->getJson(self::BASE.'/conversations?tab=unread&per_page=3', $admin['headers'])
            ->assertOk()->assertJsonCount(3, 'result')
            ->assertJsonPath('meta.total', 5)->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('result.0.unread_count', 1);

        $this->getJson(self::BASE.'/conversations?tab=received&per_page=50', $admin['headers'])
            ->assertOk()->assertJsonPath('meta.total', 5);

        $sent = $this->getJson(self::BASE.'/conversations?tab=sent&per_page=50', $admin['headers'])
            ->assertOk()->assertJsonPath('meta.total', 7);
        foreach ($sent->json('result') as $c) {
            $this->assertTrue($c['last_message']['mine']);
            $this->assertSame(0, $c['unread_count']);
        }

        $this->getJson(self::BASE.'/conversations/unread-count', $admin['headers'])
            ->assertOk()->assertJsonPath('result.count', 5);

        // Opening a thread marks it read: it leaves the Unread tab.
        $this->getJson(self::BASE.'/conversations/'.$rows[0]['conversation']->id, $admin['headers'])->assertOk();
        $this->getJson(self::BASE.'/conversations?tab=unread', $admin['headers'])->assertJsonPath('meta.total', 4);
        $this->getJson(self::BASE.'/conversations/unread-count', $admin['headers'])->assertJsonPath('result.count', 4);
    }

    public function test_the_website_role_filter_counts_only_that_role(): void
    {
        $learner = $this->userToken();
        $instructor = Instructor::query()->create(['name' => ['en' => 'Mona', 'ar' => 'منى'], 'email' => 'mona@academy.test']);
        $admin = Admin::factory()->create();

        $this->service()->start($learner['model'], Instructor::class, $instructor->id, null, 'Question');
        $this->service()->start($learner['model'], Admin::class, $admin->id, null, 'Question');

        $this->getJson(self::BASE.'/conversations?role=instructors', $learner['headers'])
            ->assertOk()->assertJsonCount(1, 'result')->assertJsonPath('meta.total', 1)
            ->assertJsonPath('result.0.counterpart.role', 'instructors')
            ->assertJsonPath('result.0.counterpart.name', 'Mona');

        $this->getJson(self::BASE.'/conversations?role=admins', $learner['headers'])
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('result.0.counterpart.role', 'admins');

        $this->getJson(self::BASE.'/conversations', $learner['headers'])->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_another_account_sees_none_of_my_conversations(): void
    {
        $admin = $this->adminToken();
        $rows = $this->conversationsWith($admin['model'], 2);
        $stranger = $this->userToken();

        $this->getJson(self::BASE.'/conversations', $stranger['headers'])->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson(self::BASE.'/conversations/'.$rows[0]['conversation']->id, $stranger['headers'])->assertForbidden();
        $this->getJson(self::BASE.'/conversations/unread-count', $stranger['headers'])->assertJsonPath('result.count', 0);
    }

    public function test_page_size_is_bounded_and_bad_input_is_refused(): void
    {
        $admin = $this->adminToken();

        $this->getJson(self::BASE.'/conversations', $admin['headers'])->assertOk()->assertJsonPath('meta.per_page', 30);
        $this->getJson(self::BASE.'/conversations?per_page=1000', $admin['headers'])->assertUnprocessable();
        $this->getJson(self::BASE.'/conversations?per_page=abc', $admin['headers'])->assertUnprocessable();
        $this->getJson(self::BASE.'/conversations?tab=archived', $admin['headers'])->assertUnprocessable();
        $this->getJson(self::BASE.'/conversations?role=robots', $admin['headers'])->assertUnprocessable();
        $this->getJson(self::BASE.'/conversations')->assertUnauthorized();
    }

    public function test_a_page_costs_the_same_queries_however_many_rows_it_holds(): void
    {
        $admin = $this->adminToken();
        $rows = $this->conversationsWith($admin['model'], 20);
        foreach (array_slice($rows, 0, 10) as $row) {
            $this->service()->reply($row['learner'], $row['conversation'], 'Thanks');
        }

        $count = function (int $perPage) use ($admin): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson(self::BASE.'/conversations?per_page='.$perPage, $admin['headers'])->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $count(1); // the first request also loads the permission cache
        $this->assertSame($count(2), $count(20));
    }

    public function test_a_broadcast_reuses_threads_skips_bad_rows_and_costs_a_bounded_number_of_queries(): void
    {
        Event::fake([MessageSent::class]);
        $admin = $this->adminToken();
        $learners = User::factory()->count(40)->create();
        $existing = $this->service()->start($admin['model'], User::class, $learners[0]->id, null, 'Earlier');

        $recipients = $learners->map(fn (User $u) => ['type' => 'learner', 'id' => $u->id])->all();
        $recipients[] = ['type' => 'learner', 'id' => $learners[1]->id]; // duplicate
        $recipients[] = ['type' => 'learner', 'id' => 999999];           // unknown
        $recipients[] = ['type' => 'admin', 'id' => $admin['model']->id]; // self

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->postJson(self::BASE.'/conversations/bulk', ['subject' => 'Safety week', 'body' => 'Read the policy.', 'recipients' => $recipients], $admin['headers'])
            ->assertOk()->assertJsonPath('result.count', 40);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // About 25 queries per learner before; now one insert per new thread plus a fixed few.
        $this->assertLessThan(40 + 40, $queries);

        $this->assertSame(40, Conversation::query()->count());
        $this->assertSame(2, DB::table('messages')->where('conversation_id', $existing->conversation_id)->count());
        $this->assertSame(41, DB::table('messages')->count());
        $this->assertSame(80, DB::table('conversation_participants')->count());

        // The sender's own Sent tab holds every thread, none unread for them.
        $this->getJson(self::BASE.'/conversations?tab=sent&per_page=100', $admin['headers'])->assertJsonPath('meta.total', 40);
        $this->getJson(self::BASE.'/conversations/unread-count', $admin['headers'])->assertJsonPath('result.count', 0);

        // Each learner sees the new thread in their own inbox, unread.
        $learner = $this->userToken($learners[5]);
        $this->getJson(self::BASE.'/conversations', $learner['headers'])
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('result.0.subject', 'Safety week')
            ->assertJsonPath('result.0.unread_count', 1)
            ->assertJsonPath('result.0.last_message.body', 'Read the policy.');

        // One live push per thread, to the conversation and both inboxes.
        Event::assertDispatchedTimes(MessageSent::class, 41);
        Event::assertDispatched(MessageSent::class, fn (MessageSent $e) => $e->conversationId === (int) $existing->conversation_id
            && in_array('identity.User.'.$learners[0]->id, $e->channels, true)
            && in_array('identity.Admin.'.$admin['model']->id, $e->channels, true)
            && $e->payload['body'] === 'Read the policy.');
    }

    public function test_a_learner_is_named_in_the_inbox_language(): void
    {
        $admin = $this->adminToken();
        $learner = User::factory()->create(['name' => 'هشام عادلي', 'name_ar' => 'هشام عادلي', 'name_en' => 'Hesham Adly']);
        $message = $this->service()->start($admin['model'], User::class, $learner->id, null, 'Hello');

        $this->getJson(self::BASE.'/conversations', $admin['headers'] + ['Accept-Language' => 'en'])
            ->assertJsonPath('result.0.counterpart.name', 'Hesham Adly');
        $this->getJson(self::BASE.'/conversations', $admin['headers'] + ['Accept-Language' => 'ar'])
            ->assertJsonPath('result.0.counterpart.name', 'هشام عادلي');

        $this->service()->reply($learner, Conversation::find($message->conversation_id), 'Thanks');
        $this->getJson(self::BASE.'/conversations/'.$message->conversation_id, $admin['headers'] + ['Accept-Language' => 'en'])
            ->assertOk()->assertJsonPath('result.messages.1.sender_name', 'Hesham Adly');
    }
}
