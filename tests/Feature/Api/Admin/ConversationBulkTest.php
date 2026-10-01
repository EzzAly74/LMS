<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Course;
use App\Models\Instructor;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Api\ApiTestCase;

/**
 * The Dashboard broadcast (POST conversations/bulk): Dashboard accounts with
 * Inbox > Create only, an instructor reaches only their own learners (D-074),
 * and every send is in the audit log (NEW2B-6109).
 */
class ConversationBulkTest extends ApiTestCase
{
    private function payload(array $learnerIds): array
    {
        return [
            'subject' => 'Safety week', 'body' => 'Please read the new policy.',
            'recipients' => array_map(fn ($id) => ['type' => 'learner', 'id' => $id], $learnerIds),
        ];
    }

    private function messaged(int $learnerId): bool
    {
        return DB::table('conversation_participants')
            ->where('participant_type', User::class)->where('participant_id', $learnerId)->exists();
    }

    public function test_a_website_learner_cannot_broadcast(): void
    {
        $learner = User::factory()->create();
        $target = User::factory()->create();
        $headers = ['Authorization' => 'Bearer '.$learner->createToken('t')->plainTextToken];

        $this->postJson(self::BASE.'/conversations/bulk', $this->payload([$target->id]), $headers)->assertForbidden();
        $this->assertFalse($this->messaged($target->id));
    }

    public function test_a_dashboard_account_without_inbox_create_cannot_broadcast(): void
    {
        $role = Role::findOrCreate('inbox-viewer', 'admin');
        $role->givePermissionTo(Permission::findOrCreate('view-inbox', 'admin'));
        $admin = Admin::factory()->create();
        $admin->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $target = User::factory()->create();

        $this->postJson(self::BASE.'/conversations/bulk', $this->payload([$target->id]), $this->adminToken($admin)['headers'])
            ->assertForbidden();
    }

    public function test_an_instructor_reaches_only_their_own_learners_and_the_send_is_audited(): void
    {
        $instructor = Instructor::query()->create(['name' => ['en' => 'Mona', 'ar' => 'منى'], 'email' => 'mona@academy.test']);
        $mine = Course::factory()->create();
        $mine->instructors()->attach($instructor->id);
        $ownLearner = User::factory()->create();
        $otherLearner = User::factory()->create();
        DB::table('users_courses')->insert(['user_id' => $ownLearner->id, 'course_id' => $mine->id]);

        $role = Role::findOrCreate('bulk-instructor', 'admin');
        DB::table('roles')->where('id', $role->id)->update(['course_scope' => 'assigned']);
        $role->givePermissionTo([Permission::findOrCreate('view-inbox', 'admin'), Permission::findOrCreate('create-inbox', 'admin')]);
        $account = Admin::factory()->create();
        $account->forceFill(['instructor_id' => $instructor->id])->save();
        $account->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->postJson(self::BASE.'/conversations/bulk', $this->payload([$ownLearner->id, $otherLearner->id]), $this->adminToken($account)['headers'])
            ->assertOk()->assertJsonPath('result.count', 1);

        $this->assertTrue($this->messaged($ownLearner->id));
        $this->assertFalse($this->messaged($otherLearner->id));

        $row = AuditLog::query()->where('model_type', Conversation::class)->latest('id')->first();
        $this->assertSame('sent', $row?->action);
        $this->assertSame('instructor', $row->actor_role);
        $this->assertStringContainsString('Safety week', $row->description);
        $this->assertStringNotContainsString('new policy', $row->description);
    }
}
