<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Blog;
use App\Models\Category;
use App\Models\Instructor;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Role;
use Tests\Feature\Api\ApiTestCase;

/**
 * What the audit log records: blogs are covered (NEW2B-6108), an edit is
 * "updated" even when the form re-sends the active flag (NEW2B-6105), and an
 * instructor's account is logged as an instructor (NEW2B-6096).
 */
class AuditLogEventsTest extends ApiTestCase
{
    private function lastLog(string $modelClass, int $id): ?AuditLog
    {
        return AuditLog::query()->where('model_type', $modelClass)->where('model_id', $id)->latest('id')->first();
    }

    public function test_blog_create_edit_and_delete_are_logged(): void
    {
        Auth::setUser(Admin::factory()->create());

        $blog = Blog::query()->create(['title' => ['en' => 'Tips', 'ar' => 'نصائح'], 'slug' => 'tips-'.uniqid()]);
        $this->assertSame('created', $this->lastLog(Blog::class, $blog->id)?->action);

        $blog->update(['title' => ['en' => 'More tips', 'ar' => 'نصائح أكثر']]);
        $this->assertSame('updated', $this->lastLog(Blog::class, $blog->id)?->action);

        $id = $blog->id;
        $blog->delete();
        $this->assertSame('deleted', $this->lastLog(Blog::class, $id)?->action);
    }

    public function test_editing_a_category_name_is_updated_not_activated(): void
    {
        ['headers' => $h] = $this->adminToken();
        $category = Category::factory()->create(['active' => true]);

        $this->putJson(self::BASE.'/categories/'.$category->id, [
            'name' => ['en' => 'Renamed', 'ar' => 'معدل'], 'active' => true,
        ], $h)->assertOk();

        $this->assertSame('updated', $this->lastLog(Category::class, $category->id)?->action);
    }

    public function test_turning_a_category_on_is_activated(): void
    {
        ['headers' => $h] = $this->adminToken();
        $category = Category::factory()->create(['name' => ['en' => 'Data', 'ar' => 'بيانات'], 'active' => false]);

        // Same names, only the flag flips.
        $this->putJson(self::BASE.'/categories/'.$category->id, ['name' => ['en' => 'Data', 'ar' => 'بيانات'], 'active' => true], $h)->assertOk();

        $this->assertSame('activated', $this->lastLog(Category::class, $category->id)?->action);
    }

    public function test_an_instructor_account_is_logged_as_an_instructor(): void
    {
        $instructor = Instructor::query()->create(['name' => ['en' => 'Karim', 'ar' => 'كريم'], 'email' => 'k@academy.test']);
        $account = Admin::factory()->create();
        $account->forceFill(['instructor_id' => $instructor->id])->save();
        $account->assignRole(Role::findOrCreate('instructor', 'admin'));
        Auth::setUser($account);

        $category = Category::factory()->create();

        $this->assertSame('instructor', $this->lastLog(Category::class, $category->id)?->actor_role);
    }

    public function test_an_admin_account_is_logged_as_an_admin(): void
    {
        Auth::setUser(Admin::factory()->create());

        $category = Category::factory()->create();

        $this->assertSame('admin', $this->lastLog(Category::class, $category->id)?->actor_role);
    }
}
