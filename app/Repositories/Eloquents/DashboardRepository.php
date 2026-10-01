<?php

namespace App\Repositories\Eloquents;

use App\Models\Course;
use App\Repositories\Contracts\DashboardRepositoryInterface;
use Illuminate\Support\Collection;
use App\Support\CourseCompletion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardRepository implements DashboardRepositoryInterface
{
    /**
     * `$courseIds` (D-074): null for every course, or the ids a scoped
     * Dashboard account teaches. Then each figure counts only those courses
     * and their learners, and the platform-wide ones (instructors, articles)
     * are null, which the Dashboard hides.
     *
     * @param list<int>|null $courseIds
     */
    public function getStatistics(?array $courseIds = null): array
    {
        $hasLearnerType = Schema::hasColumn('users', 'learner_type');
        $scoped = $courseIds !== null;
        // Integers only, so the list is safe to inline.
        $in = $scoped ? implode(',', array_map('intval', $courseIds ?: [0])) : '';
        $courseWhere = $scoped ? " AND courses.id IN ({$in})" : '';
        $byCourse = $scoped ? " AND course_id IN ({$in})" : '';
        $learners = $scoped ? " AND users.id IN (SELECT user_id FROM users_courses WHERE course_id IN ({$in}))" : '';

        $onlineLearnersSql = $hasLearnerType
            ? "(SELECT COUNT(*) FROM users WHERE learner_type = 'online'{$learners})"
            : "(SELECT COUNT(*) FROM users WHERE 1 = 1{$learners})";

        $offlineLearnersSql = $hasLearnerType
            ? "(SELECT COUNT(*) FROM users WHERE learner_type = 'offline'{$learners})"
            : '0';

        // "Active courses" must mean the same thing here as it does on the
        // All Courses list: a course with a cohort whose date window
        // contains today (NOT the raw stored `courses.active` publish
        // flag). We reuse the exact predicate from
        // CourseRepository::tabCounts() so the dashboard card and the
        // list's "Active" tab can never drift. `$today` is a controlled
        // server-clock value (no user input) so it's embedded directly,
        // matching the tabCounts convention.
        $today = now()->toDateString();

        $hasActiveCohortSql = "(SELECT COUNT(*) FROM courses WHERE 1 = 1{$courseWhere} AND EXISTS (
            SELECT 1 FROM course_sections cs
            WHERE cs.course_id = courses.id
              AND (cs.status IS NULL OR cs.status <> 'inactive')
              AND cs.start_date IS NOT NULL AND cs.end_date IS NOT NULL
              AND cs.start_date <= '{$today}' AND cs.end_date >= '{$today}'
        ))";

        $assignmentsSql = $scoped
            ? "(SELECT COUNT(*) FROM user_course_assignments WHERE course_assignment_id IN (SELECT id FROM course_assignments WHERE course_id IN ({$in})))"
            : '(SELECT COUNT(*) FROM user_course_assignments)';

        $row = (array) DB::selectOne("
            SELECT
                {$hasActiveCohortSql}                                                              AS active_courses,
                (SELECT COUNT(*) FROM courses WHERE active = 0{$courseWhere})                     AS awaiting_publish,
                (SELECT COUNT(*) FROM courses WHERE 1 = 1{$courseWhere})                          AS courses,
                (SELECT COUNT(*) FROM users WHERE 1 = 1{$learners})                               AS users,
                (SELECT COUNT(*) FROM users WHERE 1 = 1{$learners})                               AS active_learners,
                {$onlineLearnersSql}                                                               AS active_learners_online,
                {$offlineLearnersSql}                                                              AS active_learners_offline,
                (SELECT COUNT(*) FROM instructors)                                                 AS instructors,
                (SELECT COUNT(*) FROM articles WHERE active = 1)                                  AS articles,
                (SELECT COUNT(*) FROM course_lecture_questions WHERE 1 = 1{$byCourse})            AS lecture_questions,
                (SELECT COUNT(*) FROM course_lecture_questions WHERE answer IS NULL{$byCourse})   AS unanswered_questions,
                {$assignmentsSql}                                                                  AS user_assignments
        ");

        if ($scoped) {
            $row['instructors'] = null;
            $row['articles'] = null;
        }

        return $row;
    }

    /** Course::query() carries the course-scope global scope (D-074). */
    public function getTopCourses(int $limit): Collection
    {
        return Course::query()
            ->select('id', 'title', 'active')
            ->with([
                'instructors:id,name',
                // Sections power `Course::effectiveStatus()` — eager-load
                // them with just the columns we need so the dashboard
                // top-courses widget stays a single-shot query (no N+1).
                'sections:id,course_id,start_date,end_date,status',
            ])
            ->selectRaw('
                (SELECT COUNT(*) FROM users_courses uc WHERE uc.course_id = courses.id) AS users_count,
                (SELECT COUNT(DISTINCT uc.user_id) FROM users_courses uc
                   WHERE uc.course_id = courses.id AND '.CourseCompletion::existsSql('uc.user_id', 'uc.course_id').')
                    AS completed_count
            ')
            ->orderByDesc('users_count')
            ->limit($limit)
            ->get();
    }

    /** @param list<int>|null $courseIds see getStatistics() */
    public function getEnrollmentTrend(int $days = 30, ?array $courseIds = null): array
    {
        $in = $courseIds === null ? null : implode(',', array_map('intval', $courseIds ?: [0]));
        $enrolWhere = $in === null ? '' : " AND course_id IN ({$in})";

        $rows = DB::select("
            SELECT
                d.gen_date AS date,
                COALESCE(e.enrollments, 0) AS enrollments,
                COALESCE(c.completions, 0) AS completions
            FROM (
                SELECT DATE_SUB(CURDATE(), INTERVAL n DAY) AS gen_date
                FROM (
                    SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4
                    UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9
                    UNION SELECT 10 UNION SELECT 11 UNION SELECT 12 UNION SELECT 13 UNION SELECT 14
                    UNION SELECT 15 UNION SELECT 16 UNION SELECT 17 UNION SELECT 18 UNION SELECT 19
                    UNION SELECT 20 UNION SELECT 21 UNION SELECT 22 UNION SELECT 23 UNION SELECT 24
                    UNION SELECT 25 UNION SELECT 26 UNION SELECT 27 UNION SELECT 28 UNION SELECT 29
                ) nums
                WHERE n < ?
            ) d
            LEFT JOIN (
                SELECT DATE(created_at) AS dt, COUNT(*) AS enrollments
                FROM users_courses
                WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY){$enrolWhere}
                GROUP BY DATE(created_at)
            ) e ON e.dt = d.gen_date
            LEFT JOIN (
                -- B-104: a completion is a passing exam, dated by the FIRST
                -- passing submission so a retake cannot move it into another
                -- bucket. Previously this counted any enrolment row whose
                -- updated_at had moved, which is activity, not completion.
                SELECT DATE(cc.completed_at) AS dt, COUNT(*) AS completions
                FROM (
                    SELECT user_id, course_id, MIN(COALESCE(submitted_at, updated_at)) AS completed_at
                    FROM user_exams
                    WHERE LOWER(COALESCE(status, '')) IN ('passed', 'completed')
                      AND course_id IS NOT NULL{$enrolWhere}
                    GROUP BY user_id, course_id
                ) cc
                WHERE cc.completed_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                GROUP BY DATE(cc.completed_at)
            ) c ON c.dt = d.gen_date
            ORDER BY d.gen_date ASC
        ", [$days, $days, $days]);

        return array_map(static fn ($r) => [
            'date'        => $r->date,
            'enrollments' => (int) $r->enrollments,
            'completions' => (int) $r->completions,
        ], $rows);
    }

    /**
     * Range-aware enrollment trend (2026 dashboard).
     *
     *   - week     → 7 daily buckets
     *   - month    → 30 daily buckets
     *   - quarter  → 13 weekly buckets (last 90 days, ISO-week grouped)
     *   - year     → 12 monthly buckets
     *
     * Always returns the full bucket grid (zero-filled), so the chart
     * never has gaps even when no enrollment activity occurred in a
     * given window.
     *
     * @param  'week'|'month'|'quarter'|'year'  $range
     * @return array<int, array{date:string,label:string,enrollments:int,completions:int}>
     */
    public function getEnrollmentTrendByRange(string $range, ?array $courseIds = null): array
    {
        return match ($range) {
            'week'    => $this->buildDailyTrend(7, $courseIds),
            'quarter' => $this->buildWeeklyTrend(13, $courseIds),
            'year'    => $this->buildMonthlyTrend(12, $courseIds),
            default   => $this->buildDailyTrend(30, $courseIds),
        };
    }

    /**
     * @return array<int, array{date:string,label:string,enrollments:int,completions:int}>
     */
    private function buildDailyTrend(int $days, ?array $courseIds): array
    {
        $rows = $this->getEnrollmentTrend($days, $courseIds);

        return array_map(static function (array $r): array {
            $d = \Illuminate\Support\Carbon::parse($r['date']);
            return [
                'date'        => $d->format('Y-m-d'),
                'label'       => $d->format('d M'),
                'enrollments' => (int) $r['enrollments'],
                'completions' => (int) $r['completions'],
            ];
        }, $rows);
    }

    /**
     * @return array<int, array{date:string,label:string,enrollments:int,completions:int}>
     */
    private function buildWeeklyTrend(int $weeks, ?array $courseIds): array
    {
        $ids = $courseIds === null ? null : ($courseIds ?: [0]);
        $days = $weeks * 7;
        $start = \Illuminate\Support\Carbon::today()->subDays($days - 1)->startOfDay();

        $enrollments = DB::table('users_courses')
            ->selectRaw('YEARWEEK(created_at, 3) AS yw, COUNT(*) AS total')
            ->where('created_at', '>=', $start)
            ->when($ids !== null, fn ($q) => $q->whereIn('course_id', $ids))
            ->groupBy('yw')
            ->pluck('total', 'yw');

        $completions = DB::query()
            ->fromSub(CourseCompletion::query(), 'cc')
            ->selectRaw('YEARWEEK(cc.completed_at, 3) AS yw, COUNT(*) AS total')
            ->where('cc.completed_at', '>=', $start)
            ->when($ids !== null, fn ($q) => $q->whereIn('cc.course_id', $ids))
            ->groupBy('yw')
            ->pluck('total', 'yw');

        $buckets = [];
        for ($i = $weeks - 1; $i >= 0; $i--) {
            $weekStart = \Illuminate\Support\Carbon::today()->subWeeks($i)->startOfWeek();
            $key = (int) $weekStart->format('oW');
            $buckets[] = [
                'date'        => $weekStart->format('Y-m-d'),
                'label'       => 'W' . $weekStart->isoWeek(),
                'enrollments' => (int) ($enrollments[$key] ?? 0),
                'completions' => (int) ($completions[$key] ?? 0),
            ];
        }

        return $buckets;
    }

    /**
     * @return array<int, array{date:string,label:string,enrollments:int,completions:int}>
     */
    private function buildMonthlyTrend(int $months, ?array $courseIds): array
    {
        $ids = $courseIds === null ? null : ($courseIds ?: [0]);
        $start = \Illuminate\Support\Carbon::today()->subMonthsNoOverflow($months - 1)->startOfMonth();

        $enrollments = DB::table('users_courses')
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS total")
            ->where('created_at', '>=', $start)
            ->when($ids !== null, fn ($q) => $q->whereIn('course_id', $ids))
            ->groupBy('ym')
            ->pluck('total', 'ym');

        $completions = DB::query()
            ->fromSub(CourseCompletion::query(), 'cc')
            ->selectRaw("DATE_FORMAT(cc.completed_at, '%Y-%m') AS ym, COUNT(*) AS total")
            ->where('cc.completed_at', '>=', $start)
            ->when($ids !== null, fn ($q) => $q->whereIn('cc.course_id', $ids))
            ->groupBy('ym')
            ->pluck('total', 'ym');

        $buckets = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $d   = \Illuminate\Support\Carbon::today()->subMonthsNoOverflow($i)->startOfMonth();
            $key = $d->format('Y-m');
            $buckets[] = [
                'date'        => $d->format('Y-m-d'),
                'label'       => $d->format('M'),
                'enrollments' => (int) ($enrollments[$key] ?? 0),
                'completions' => (int) ($completions[$key] ?? 0),
            ];
        }

        return $buckets;
    }
}
