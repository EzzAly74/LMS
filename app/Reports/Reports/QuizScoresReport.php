<?php

namespace App\Reports\Reports;

use App\Reports\Column;
use App\Reports\Filter;
use App\Reports\QueryReport;
use App\Reports\ReportContext;
use App\Reports\Support\Employee;
use App\Reports\Support\Scope;
use App\Services\Admin\AdminQuizService;
use App\Support\CourseCompletion;
use App\Support\LocalizedJson;
use App\Support\QuizVerdict;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 5. Quiz Scores: one row per learner x quiz attempt (D-112).
 *
 * A learner has exactly one attempt per quiz (unique user_exams row, resumed
 * rather than repeated: LearnerQuizService::startOrResume), so Attempt No.
 * is 1 and Best Attempt is Yes from the data, and a "best attempt only"
 * filter would change nothing. Only sent attempts are listed
 * (CourseCompletion::submittedExamSql). Score and Max as the Dashboard reads
 * them (AdminQuizService), Pass / Fail by QuizVerdict (D-087), Pending
 * Review while a person still scores an answer. Passing Score is the quiz's
 * pass score as a % of its full mark (half the mark when it has none).
 * Module / Lesson is the session(s) the quiz is placed in; Time Taken the
 * minutes from opening to sending (quizzes of the old flow store no start).
 */
final class QuizScoresReport extends QueryReport
{
    public const RESULTS = [
        'pass' => 'reports.values.result.pass',
        'fail' => 'reports.values.result.fail',
        'pending' => 'reports.values.result.pending_review',
    ];

    public function key(): string
    {
        return 'quiz-scores';
    }

    public function columns(ReportContext $context): array
    {
        return [
            ...Employee::columns(),
            new Column('course_name', 'course'),
            new Column('module_lesson', 'course'),
            new Column('quiz_name', 'quiz'),
            new Column('attempt_no', 'attempt', Column::NUMBER),
            new Column('attempt_date', 'attempt', Column::DATETIME),
            new Column('score', 'score', Column::NUMBER),
            new Column('max_score', 'score', Column::NUMBER),
            new Column('score_pct', 'score', Column::PERCENT),
            new Column('passing_score', 'score', Column::PERCENT),
            new Column('result', 'score'),
            new Column('time_taken', 'attempt', Column::NUMBER),
            new Column('best_attempt', 'attempt', Column::YES_NO),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::multi('course_ids', 'courses'),
            Filter::multi('quiz_ids', 'quizzes', dependsOn: 'course_ids'),
            Filter::multi('cohort_ids', 'cohorts', dependsOn: 'course_ids'),
            Filter::dateRange('attempted'),
            Filter::status('results', self::RESULTS),
        ];
    }

    protected function query(ReportContext $context): Builder
    {
        $attemptAt = 'COALESCE(user_exams.submitted_at, user_exams.created_at)';
        $query = DB::table('user_exams')
            ->join('course_exams', 'course_exams.id', '=', 'user_exams.exam_id')
            ->join('users as u', 'u.id', '=', 'user_exams.user_id')
            ->leftJoin('courses as c', 'c.id', '=', 'user_exams.course_id')
            ->whereRaw(CourseCompletion::submittedExamSql('user_exams'))
            ->select([
                'user_exams.id as attempt_id', 'user_exams.exam_id', 'user_exams.created_at as opened_at',
                'user_exams.submitted_at', 'user_exams.submission_status',
                'course_exams.title as quiz_title', 'course_exams.pass_score', 'c.title as course_title',
            ])
            ->selectRaw(AdminQuizService::SCORE_SQL.' AS score')
            ->selectRaw(AdminQuizService::MAX_SQL.' AS max_score')
            ->selectRaw("{$attemptAt} AS attempted_at")
            ->tap(fn ($q) => Employee::select($q))
            ->tap(fn ($q) => Employee::filter($q, $context))
            ->tap(fn ($q) => Scope::courses($q, $context, 'user_exams.course_id'))
            ->when($context->ids('course_ids'), fn ($q, $v) => $q->whereIn('user_exams.course_id', $v))
            ->when($context->ids('quiz_ids'), fn ($q, $v) => $q->whereIn('user_exams.exam_id', $v))
            ->when($context->ids('cohort_ids'), fn ($q, $v) => $q->whereExists(fn ($e) => $e->selectRaw('1')->from('users_courses as qc')
                ->whereColumn('qc.user_id', 'user_exams.user_id')->whereColumn('qc.course_id', 'user_exams.course_id')
                ->whereIn('qc.group_id', $v)))
            ->when($context->from('attempted'), fn ($q, $d) => $q->whereRaw("{$attemptAt} >= ?", [$d]))
            ->when($context->to('attempted'), fn ($q, $d) => $q->whereRaw("{$attemptAt} <= ?", [$d]));

        $results = $context->strings('results');
        if ($results !== [] && count($results) < count(self::RESULTS)) {
            $score = AdminQuizService::SCORE_SQL;
            $max = AdminQuizService::MAX_SQL;
            $passes = "(CASE WHEN course_exams.pass_score IS NOT NULL THEN {$score} >= course_exams.pass_score ELSE ({$max}) > 0 AND {$score} * 2 >= ({$max}) END)";
            $query->where(function (Builder $w) use ($results, $score, $passes) {
                foreach ($results as $result) {
                    match ($result) {
                        'pending' => $w->orWhereRaw("{$score} IS NULL"),
                        'pass' => $w->orWhereRaw("({$score} IS NOT NULL AND {$passes})"),
                        'fail' => $w->orWhereRaw("({$score} IS NOT NULL AND NOT {$passes})"),
                    };
                }
            });
        }

        return $query->orderByRaw("{$attemptAt} DESC")->orderByDesc('user_exams.id');
    }

    protected function map(Collection $rows, ReportContext $context): array
    {
        $sessions = DB::table('course_session_exams as se')
            ->join('course_sessions as s', 's.id', '=', 'se.session_id')
            ->whereIn('se.exam_id', $rows->pluck('exam_id')->unique()->values()->all() ?: [0])
            ->orderBy('s.session_date')->orderBy('s.id')
            ->get(['se.exam_id', 's.title'])
            ->groupBy('exam_id')
            ->map(fn ($r) => $r->map(fn ($s) => LocalizedJson::pick($s->title, $context->locale))->filter()->unique()->implode(', '));
        $keys = $this->columnKeys($context);

        return $rows->map(function (object $row) use ($sessions, $context, $keys) {
            $max = (float) $row->max_score;
            $score = $row->score === null ? null : (float) $row->score;
            $passScore = $row->pass_score !== null ? (float) $row->pass_score : null;
            $result = $score === null ? 'pending' : (QuizVerdict::passes($score, $passScore, $max) ? 'pass' : 'fail');
            $minutes = $row->submission_status === 'submitted' && $row->submitted_at && $row->opened_at
                ? max(0, (int) round((strtotime($row->submitted_at) - strtotime($row->opened_at)) / 60))
                : null;

            return self::shape([
                ...Employee::values($row, $context),
                'course_name' => LocalizedJson::pick($row->course_title, $context->locale),
                'module_lesson' => ($sessions[$row->exam_id] ?? '') ?: null,
                'quiz_name' => LocalizedJson::pick($row->quiz_title, $context->locale),
                'attempt_no' => 1,
                'attempt_date' => Employee::dateTime($row->attempted_at),
                'score' => $score,
                'max_score' => $max > 0 ? $max : null,
                'score_pct' => $score === null ? null : self::percent($score, $max),
                'passing_score' => $max > 0 ? self::percent($passScore ?? $max / 2, $max) : null,
                'result' => __(self::RESULTS[$result]),
                'time_taken' => $minutes,
                'best_attempt' => true,
            ], $keys);
        })->values()->all();
    }
}
