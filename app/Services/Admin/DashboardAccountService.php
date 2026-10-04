<?php

namespace App\Services\Admin;

use App\Http\Traits\HasFile;
use App\Models\Admin;
use App\Models\Instructor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

/**
 * The Users screen (D-075): the people who sign in to the Dashboard, one
 * `admins` row each, with their role, status and (for instructors) the
 * instructor record they teach as. Website learners are not here: they sign
 * in through HR and are managed under Learning > Learners.
 *
 * Every change runs through RoleAuthority: nobody assigns a role with more
 * access than they hold, touches a super-admin account unless they are one,
 * or changes their own role or status. Deactivating an account revokes its
 * tokens at once.
 *
 * Rows keep the AdminUserListResource shape (`source` is always `admin`), so
 * the list, drawer and form read them as before.
 */
class DashboardAccountService
{
    use HasFile;

    public function __construct(private readonly RoleAuthority $authority) {}

    /* ------------------------------------------------------------------ *
     |  READ                                                              |
     * ------------------------------------------------------------------ */

    public function paginate(?string $role, ?string $status, ?string $search, int $perPage): LengthAwarePaginator
    {
        $query = Admin::query()->with(['roles:id,name,name_en,name_ar,color,course_scope', 'instructor']);

        if ($role) {
            $query->whereHas('roles', fn ($q) => $q->where('name', $role));
        }
        if ($status) {
            $status === 'active'
                ? $query->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'active'))
                : $query->where('status', $status);
        }
        if ($search) {
            $needle = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search).'%';
            $query->where(fn ($q) => $q->where('name', 'like', $needle)
                ->orWhere('name_ar', 'like', $needle)
                ->orWhere('email', 'like', $needle));
        }

        $page = $query->orderBy('name')->orderBy('id')->paginate($perPage);
        $page->setCollection($page->getCollection()->map(fn (Admin $a) => $this->row($a)));

        return $page;
    }

    public function show(int $id): object
    {
        return $this->row(Admin::query()->with(['roles', 'instructor'])->findOrFail($id));
    }

    /** @return array{total_users:int,instructors:int,admins:int,inactive:int} */
    public function summary(): array
    {
        $total = Admin::query()->count();
        $instructors = Admin::query()->where(fn ($q) => $q->whereNotNull('instructor_id')
            ->orWhereHas('roles', fn ($r) => $r->where('name', 'instructor')))->count();
        $inactive = Admin::query()->whereIn('status', ['inactive', 'deactivated'])->count();

        return [
            'total_users' => $total,
            'instructors' => $instructors,
            'admins'      => $total - $instructors,
            'inactive'    => $inactive,
        ];
    }

    /**
     * The role pills and the form's role list: Dashboard roles only (the
     * `learner` role is a website concept and is not offered).
     *
     * @return array{roles:list<array<string,mixed>>}
     */
    public function filterOptions(Admin $actor): array
    {
        $locale = app()->getLocale();
        $counts = DB::table('model_has_roles')
            ->where('model_type', (new Admin())->getMorphClass())
            ->select('role_id', DB::raw('COUNT(*) AS c'))->groupBy('role_id')
            ->pluck('c', 'role_id');

        $roles = DB::table('roles')->where('guard_name', 'admin')->where('name', '!=', 'learner')
            ->orderByDesc('is_system')->orderBy('name_en')
            ->get(['id', 'name', 'name_en', 'name_ar', 'color', 'course_scope'])
            ->map(function ($r) use ($locale, $counts, $actor) {
                $assignable = true;
                try {
                    $this->authority->assertCanAssignRole($actor, (string) $r->name);
                } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
                    $assignable = false;
                }

                return [
                    'key'          => (string) $r->name,
                    'label'        => $this->roleLabel($r, $locale),
                    'color'        => (string) ($r->color ?: 'teal'),
                    'count'        => (int) ($counts[$r->id] ?? 0),
                    'course_scope' => (string) ($r->course_scope ?? 'all'),
                    'assignable'   => $assignable,
                ];
            })->values()->all();

        return ['roles' => $roles];
    }

    /* ------------------------------------------------------------------ *
     |  WRITE                                                             |
     * ------------------------------------------------------------------ */

    /** @param array<string,mixed> $data validated DashboardAccountStoreRequest */
    public function create(Admin $actor, array $data): object
    {
        $role = (string) $data['role'];
        $this->authority->assertCanAssignRole($actor, $role);
        $image = $data['image'] ?? null;

        $id = DB::transaction(function () use ($data, $role, $image) {
            $admin = new Admin();
            $admin->name = trim((string) $data['name_en']);
            $admin->name_ar = trim((string) $data['name_ar']);
            $admin->email = strtolower(trim((string) $data['email']));
            $admin->password = Hash::make((string) $data['password']);
            $admin->status = 'active';
            if ($image instanceof UploadedFile) {
                $admin->image = $this->uploadImageFile('admins', $image);
            }
            $admin->save();

            $admin->assignRole($role);
            $this->linkInstructorIfTeaching($admin, $data);

            return (int) $admin->id;
        });

        $this->flush();

        return $this->show($id);
    }

    /** @param array<string,mixed> $data validated DashboardAccountUpdateRequest */
    public function update(Admin $actor, int $id, array $data): object
    {
        $admin = Admin::query()->with('roles')->findOrFail($id);
        $role = isset($data['role']) && $data['role'] !== '' ? (string) $data['role'] : null;
        $roleChanges = $role !== null && ($admin->roles->count() !== 1 || $admin->roles->first()->name !== $role);
        $statusChanges = array_key_exists('status', $data) && $data['status'] !== null
            && $data['status'] !== ($admin->status ?? 'active');

        $this->authority->assertCanManageAccount($actor, $admin, $roleChanges || $statusChanges);
        if ($roleChanges) {
            $this->authority->assertCanAssignRole($actor, $role);
        }

        DB::transaction(function () use ($admin, $data, $role, $roleChanges) {
            if (! empty($data['name_en'])) {
                $admin->name = trim((string) $data['name_en']);
            }
            if (array_key_exists('name_ar', $data) && $data['name_ar'] !== null) {
                $admin->name_ar = trim((string) $data['name_ar']);
            }
            if (! empty($data['email'])) {
                $admin->email = strtolower(trim((string) $data['email']));
            }
            if (! empty($data['password'])) {
                $admin->password = Hash::make((string) $data['password']);
            }
            if (array_key_exists('status', $data) && $data['status'] !== null) {
                $admin->status = (string) $data['status'];
            }
            if (($data['image'] ?? null) instanceof UploadedFile) {
                $admin->image = $this->uploadImageFile('admins', $data['image']);
            }
            $passwordChanged = $admin->isDirty('password');
            $admin->save();

            if ($roleChanges) {
                $admin->syncRoles([$role]);
            }
            $this->linkInstructorIfTeaching($admin->fresh(['roles']), $data);

            // A new password or a lost status signs the account out everywhere.
            if ($passwordChanged || ! $admin->isActive()) {
                $admin->tokens()->delete();
            }
        });

        $this->flush();

        return $this->show($id);
    }

    public function deactivate(Admin $actor, int $id): object
    {
        $admin = Admin::query()->findOrFail($id);
        $this->authority->assertCanManageAccount($actor, $admin, true);

        $admin->forceFill(['status' => 'deactivated'])->save();
        $admin->tokens()->delete();

        return $this->show($id);
    }

    public function reactivate(Admin $actor, int $id): object
    {
        $admin = Admin::query()->findOrFail($id);
        $this->authority->assertCanManageAccount($actor, $admin, true);

        $admin->forceFill(['status' => 'active'])->save();

        return $this->show($id);
    }

    /* ------------------------------------------------------------------ *
     |  INTERNALS                                                         |
     * ------------------------------------------------------------------ */

    /**
     * An account whose role is limited to the courses it teaches (or the
     * instructor role) needs an instructor record to teach as (D-074). It is
     * linked to the instructor with the same email when that one is free, or
     * a new one is created; the brief and photo are kept on it.
     *
     * @param array<string,mixed> $data
     */
    private function linkInstructorIfTeaching(Admin $admin, array $data): void
    {
        $teaches = $admin->roles->contains(fn ($r) => $r->name === 'instructor' || ($r->course_scope ?? 'all') === 'assigned');

        $instructor = $admin->instructor_id ? Instructor::query()->find($admin->instructor_id) : null;

        if ($instructor === null && $teaches) {
            $instructor = Instructor::query()
                ->whereRaw('LOWER(TRIM(email)) = ?', [strtolower(trim((string) $admin->email))])
                ->whereNotIn('id', Admin::query()->whereNotNull('instructor_id')->select('instructor_id'))
                ->first();

            if ($instructor === null) {
                $instructor = new Instructor();
                $instructor->email = $admin->email;
                $instructor->status = 'active';
            }
        }

        if ($instructor === null) {
            return;
        }

        $instructor->setTranslation('name', 'en', $admin->name);
        $instructor->setTranslation('name', 'ar', $admin->name_ar ?: $admin->name);
        foreach (['en', 'ar'] as $lang) {
            if (array_key_exists("title_{$lang}", $data)) {
                $instructor->setTranslation('title', $lang, trim((string) ($data["title_{$lang}"] ?? '')));
            }
        }
        if (array_key_exists('brief_en', $data)) {
            $instructor->setTranslation('bio', 'en', (string) ($data['brief_en'] ?? ''));
        }
        if (array_key_exists('brief_ar', $data)) {
            $instructor->setTranslation('bio', 'ar', (string) ($data['brief_ar'] ?? ''));
        }
        if ($admin->image) {
            $instructor->image = $admin->image;
        }
        $instructor->save();

        if ((int) $admin->instructor_id !== (int) $instructor->id) {
            $admin->forceFill(['instructor_id' => $instructor->id])->save();
        }
    }

    /** The AdminUserListResource row for an account. */
    private function row(Admin $admin): object
    {
        $locale = app()->getLocale();
        $role = $admin->roles->first();
        $instructor = $admin->instructor;

        return (object) [
            'id'                     => (int) $admin->id,
            'source'                 => 'admin',
            'name_en'                => $admin->name,
            'name_ar'                => $admin->name_ar,
            'name_fallback'          => $admin->email,
            'title_en'               => $instructor?->getTranslation('title', 'en', false) ?: null,
            'title_ar'               => $instructor?->getTranslation('title', 'ar', false) ?: null,
            'bio_en'                 => $instructor?->getTranslation('bio', 'en', false) ?: null,
            'bio_ar'                 => $instructor?->getTranslation('bio', 'ar', false) ?: null,
            'email'                  => $admin->email,
            'phone'                  => null,
            'machine_code'           => null,
            'department_name'        => null,
            'image'                  => $admin->image,
            'role_label'             => $role ? $this->roleLabel($role, $locale) : null,
            'role_key'               => $role?->name ?? '',
            'role_machine'           => $role?->name ?? '',
            'role_color'             => $role?->color ?: 'teal',
            'status'                 => $admin->status ?: 'active',
            'last_active_at'         => $admin->last_active_at,
            'compliance_pct'         => null,
            'enrolled_courses_count' => 0,
            'courses_earned'         => 0,
            'last_certification_at'  => null,
            'job_title'              => null,
            'created_at'             => $admin->created_at,
            'instructor_id'          => $admin->instructor_id,
            'course_scope'           => app(CourseScope::class)->scopeOf($admin),
            'is_super_admin'         => $admin->isSuperAdmin(),
        ];
    }

    private function roleLabel(object $role, string $locale): string
    {
        $en = $role->name_en ?? null;
        $ar = $role->name_ar ?? null;

        return (string) ($locale === 'ar' ? ($ar ?: $en ?: $role->name) : ($en ?: $ar ?: $role->name));
    }

    private function flush(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
