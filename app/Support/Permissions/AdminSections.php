<?php

namespace App\Support\Permissions;

/**
 * The Dashboard's permission matrix (D-073): one row per section, one column
 * per action. Every admin permission is named `{action}-{section}`, so the
 * existing `view-*` keys stay valid and `create-` / `edit-` / `delete-` sit
 * beside them.
 *
 * This is the single source of truth. The seed migration, the Roles catalogue
 * (GET admin/roles/sections), AdminSectionMiddleware and AdminRouteGatingTest
 * all read it, so a section or action added here is enforced and editable
 * everywhere at once.
 *
 *  - `actions`: what the section supports. A route may only ask for one of
 *    these, and the Roles form shows only these columns.
 *  - `catalog`: false for the legacy sections that have no Dashboard nav entry
 *    (D-009). They are still enforced, but stay out of the Roles form until
 *    Q-010 decides whether they are retired.
 */
final class AdminSections
{
    public const VIEW = 'view';
    public const CREATE = 'create';
    public const EDIT = 'edit';
    public const DELETE = 'delete';

    /** Column order in the Roles form. */
    public const ACTIONS = [self::VIEW, self::CREATE, self::EDIT, self::DELETE];

    public const GROUPS = ['Main', 'Learning Operation', 'Manage Competency', 'System'];

    private const CRUD = [self::VIEW, self::CREATE, self::EDIT, self::DELETE];

    /**
     * @var array<string, array{group:string, en:string, ar:string, actions:list<string>, catalog:bool}>
     */
    public const SECTIONS = [
        // Main
        'dashboard'         => ['group' => 'Main', 'en' => 'Dashboard', 'ar' => 'لوحة التحكم', 'actions' => [self::VIEW], 'catalog' => true],
        'inbox'             => ['group' => 'Main', 'en' => 'Inbox', 'ar' => 'الوارد', 'actions' => [self::VIEW, self::CREATE], 'catalog' => true],

        // Learning Operation
        'courses'           => ['group' => 'Learning Operation', 'en' => 'Courses', 'ar' => 'الدورات', 'actions' => self::CRUD, 'catalog' => true],
        'assignments'       => ['group' => 'Learning Operation', 'en' => 'Assignments', 'ar' => 'الواجبات', 'actions' => self::CRUD, 'catalog' => true],
        'quizzes'           => ['group' => 'Learning Operation', 'en' => 'Quizzes', 'ar' => 'الاختبارات', 'actions' => self::CRUD, 'catalog' => true],
        'evaluations'       => ['group' => 'Learning Operation', 'en' => 'Evaluation', 'ar' => 'التقييم', 'actions' => [self::VIEW, self::CREATE, self::EDIT], 'catalog' => true],
        'learners'          => ['group' => 'Learning Operation', 'en' => 'Learners', 'ar' => 'المتعلمون', 'actions' => [self::VIEW], 'catalog' => true],
        'external-training' => ['group' => 'Learning Operation', 'en' => 'External Courses', 'ar' => 'الدورات الخارجية', 'actions' => [self::VIEW, self::EDIT], 'catalog' => true],
        'resources'         => ['group' => 'Learning Operation', 'en' => 'Blogs', 'ar' => 'المدونات', 'actions' => self::CRUD, 'catalog' => true],

        // Manage Competency
        'job-titles'        => ['group' => 'Manage Competency', 'en' => 'Job Titles', 'ar' => 'المسميات الوظيفية', 'actions' => [self::VIEW, self::EDIT], 'catalog' => true],
        'qualifications'    => ['group' => 'Manage Competency', 'en' => 'Qualifications', 'ar' => 'المؤهلات', 'actions' => self::CRUD, 'catalog' => true],
        'certificates'      => ['group' => 'Manage Competency', 'en' => 'Certificates', 'ar' => 'الشهادات', 'actions' => [self::VIEW, self::EDIT, self::DELETE], 'catalog' => true],
        'categories'        => ['group' => 'Manage Competency', 'en' => 'Categories', 'ar' => 'الفئات', 'actions' => self::CRUD, 'catalog' => true],
        'reports'           => ['group' => 'Manage Competency', 'en' => 'Reports', 'ar' => 'التقارير', 'actions' => [self::VIEW], 'catalog' => true],

        // System
        'users'             => ['group' => 'System', 'en' => 'Users', 'ar' => 'المستخدمون', 'actions' => self::CRUD, 'catalog' => true],
        'roles'             => ['group' => 'System', 'en' => 'Roles', 'ar' => 'الأدوار', 'actions' => self::CRUD, 'catalog' => true],
        'controllers'       => ['group' => 'System', 'en' => 'Controllers', 'ar' => 'المشرفون', 'actions' => self::CRUD, 'catalog' => true],
        'platform-config'   => ['group' => 'System', 'en' => 'Platform Config', 'ar' => 'إعدادات المنصة', 'actions' => [self::VIEW, self::EDIT], 'catalog' => true],
        'audit-log'         => ['group' => 'System', 'en' => 'Audit Log', 'ar' => 'سجل التدقيق', 'actions' => [self::VIEW], 'catalog' => true],

        // Legacy sections without a Dashboard nav entry (D-009, Q-010).
        'content'           => ['group' => 'System', 'en' => 'Website content', 'ar' => 'محتوى الموقع', 'actions' => self::CRUD, 'catalog' => false],
        'attendance'        => ['group' => 'Learning Operation', 'en' => 'Attendance', 'ar' => 'الحضور', 'actions' => [self::VIEW, self::CREATE], 'catalog' => false],
        'instructors'       => ['group' => 'System', 'en' => 'Instructors', 'ar' => 'المدربون', 'actions' => self::CRUD, 'catalog' => false],
        'forms'             => ['group' => 'System', 'en' => 'Forms', 'ar' => 'النماذج', 'actions' => self::CRUD, 'catalog' => false],
        'notifications'     => ['group' => 'System', 'en' => 'Notifications', 'ar' => 'الإشعارات', 'actions' => self::CRUD, 'catalog' => false],
    ];

    /**
     * Sections whose data spans the whole organisation and cannot be cut down
     * to "the courses I teach" (D-074): job titles, qualifications, external
     * courses and the audit log hold learners and actions from every course.
     * An account limited to its own courses is refused them even when its role
     * holds the permission, and the Roles form greys them out for such roles.
     * The legacy sections (not in the catalogue) are refused as well.
     */
    public const ORG_WIDE = ['external-training', 'job-titles', 'qualifications', 'audit-log'];

    public static function availableToScoped(string $section): bool
    {
        return isset(self::SECTIONS[$section])
            && self::SECTIONS[$section]['catalog']
            && ! in_array($section, self::ORG_WIDE, true);
    }

    /** Roles that hold every permission and cannot be edited or assigned by anyone else. */
    public const SUPER_ADMIN_ROLES = ['superadmin', 'super-admin', 'super_admin'];

    public static function permission(string $section, string $action): string
    {
        return $action.'-'.$section;
    }

    public static function has(string $section): bool
    {
        return isset(self::SECTIONS[$section]);
    }

    public static function supports(string $section, string $action): bool
    {
        return in_array($action, self::SECTIONS[$section]['actions'] ?? [], true);
    }

    /**
     * Every permission name in the matrix.
     *
     * @return list<string>
     */
    public static function permissionNames(bool $catalogOnly = false): array
    {
        $names = [];
        foreach (self::SECTIONS as $key => $section) {
            if ($catalogOnly && ! $section['catalog']) {
                continue;
            }
            foreach ($section['actions'] as $action) {
                $names[] = self::permission($key, $action);
            }
        }

        return $names;
    }

    /**
     * Split a matrix permission name into [section, action], or null when the
     * name is not part of the matrix.
     *
     * @return array{0:string,1:string}|null
     */
    public static function parse(string $permission): ?array
    {
        foreach (self::ACTIONS as $action) {
            $prefix = $action.'-';
            if (str_starts_with($permission, $prefix)) {
                $section = substr($permission, strlen($prefix));

                return self::supports($section, $action) ? [$section, $action] : null;
            }
        }

        return null;
    }

    public static function label(string $section, string $locale): string
    {
        $row = self::SECTIONS[$section] ?? null;
        if ($row === null) {
            return $section;
        }

        return $locale === 'ar' ? $row['ar'] : $row['en'];
    }

    public static function actionLabel(string $action, string $locale): string
    {
        $labels = $locale === 'ar'
            ? [self::VIEW => 'عرض', self::CREATE => 'إنشاء', self::EDIT => 'تعديل', self::DELETE => 'حذف']
            : [self::VIEW => 'View', self::CREATE => 'Create', self::EDIT => 'Edit', self::DELETE => 'Delete'];

        return $labels[$action] ?? $action;
    }

    public static function groupLabel(string $group, string $locale): string
    {
        if ($locale !== 'ar') {
            return $group;
        }

        return match ($group) {
            'Main'               => 'الرئيسية',
            'Learning Operation' => 'العمليات التعليمية',
            'Manage Competency'  => 'إدارة الكفاءات',
            'System'             => 'النظام',
            default              => $group,
        };
    }

    public static function isSuperAdminRole(string $roleName): bool
    {
        return in_array(strtolower($roleName), self::SUPER_ADMIN_ROLES, true);
    }
}
