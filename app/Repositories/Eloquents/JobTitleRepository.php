<?php

namespace App\Repositories\Eloquents;

use App\Models\JobTitle;
use App\Models\User;
use App\Repositories\Contracts\JobTitleRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use App\Support\CourseCompletion;
use App\Support\QualificationHolding;
use Illuminate\Support\Facades\DB;

class JobTitleRepository extends BaseRepository implements JobTitleRepositoryInterface
{
    public function __construct(JobTitle $model)
    {
        parent::__construct($model);
    }


    /**
     * Learners holding this job title, with their progress toward its
     * required qualifications.
     *
     * Powers the job-title detail table (Figma 2325:117118): learner + ID,
     * assigned qualifications, completion % and "N of M courses".
     *
     * "Completed" is App\Support\CourseCompletion (a passing exam, B-104).
     * Only courses that grant one of THIS job title's required qualifications
     * are counted, so an unrelated enrolment does not dilute the percentage,
     * and all of them are counted, enrolled or not (D-056).
     *
     * @param  string  $sort  one of name|completion|employee_id (allow-listed
     *                        by JobTitleLearnersRequest, never raw input)
     * @param  string  $dir   asc|desc
     */
    public function paginateLearners(
        JobTitle $jobTitle,
        int $perPage,
        ?string $search = null,
        string $sort = 'name',
        string $dir = 'asc',
    ): LengthAwarePaginator {
        // Every course linked to one of this job title's qualifications - the
        // "M" in "N of M courses" (D-056). This used to count only the ones
        // the learner was enrolled in, so an unenrolled course simply vanished
        // from what they still had to do.
        $totalCourses = DB::table('course_qualification_skills as tc')
            ->selectRaw('COUNT(DISTINCT tc.course_id)')
            ->join('job_title_qualification_skill as tj', 'tc.qualification_skill_id', '=', 'tj.qualification_skill_id')
            ->where('tj.job_title_id', $jobTitle->id);

        $completedCourses = DB::table('course_qualification_skills as tc')
            ->selectRaw('COUNT(DISTINCT tc.course_id)')
            ->join('job_title_qualification_skill as tj', 'tc.qualification_skill_id', '=', 'tj.qualification_skill_id')
            ->where('tj.job_title_id', $jobTitle->id)
            // B-104: a passing exam, not "the row was touched".
            ->whereRaw(CourseCompletion::existsSql('users.id', 'tc.course_id'));

        $query = User::query()
            ->where('users.job_title_id', $jobTitle->id)
            ->when($search, fn ($q) => $q->where(function ($q2) use ($search) {
                $q2->where('users.name', 'like', "%{$search}%")
                    ->orWhere('users.machine_code', 'like', "%{$search}%");
            }))
            ->select('users.*')
            ->selectSub($totalCourses, 'courses_total')
            ->selectSub($completedCourses, 'courses_completed');

        $direction = $dir === 'desc' ? 'desc' : 'asc';

        match ($sort) {
            'completion'  => $query->orderByRaw(
                'CASE WHEN courses_total = 0 THEN 0 ELSE courses_completed / courses_total END '.$direction
            ),
            'employee_id' => $query->orderBy('users.machine_code', $direction),
            default       => $query->orderBy('users.name', $direction),
        };

        return $query->paginate($perPage);
    }

    public function list(int $perPage, ?string $search): LengthAwarePaginator
    {
        // Only count users who actually hold this job title (users.job_title_id = job_titles.id).
        // Without this filter, users from other job titles enrolled in the same courses
        // would inflate the count above the employees_count.
        $learnersSubQuery = DB::table('users_courses')
            ->selectRaw('COUNT(DISTINCT users_courses.user_id)')
            ->join('users', 'users_courses.user_id', '=', 'users.id')
            ->join('course_qualification_skills', 'users_courses.course_id', '=', 'course_qualification_skills.course_id')
            ->join('job_title_qualification_skill', 'course_qualification_skills.qualification_skill_id', '=', 'job_title_qualification_skill.qualification_skill_id')
            ->whereColumn('job_title_qualification_skill.job_title_id', 'job_titles.id')
            ->whereColumn('users.job_title_id', 'job_titles.id');

        /**
         * Held (employee, required-qualification) pairs, by the one project
         * rule (App\Support\QualificationHolding, D-056): granted directly,
         * or every linked course completed.
         *
         * This counted a pair as soon as the learner finished ANY one course
         * granting the qualification, and ignored direct grants entirely.
         *
         * Divided by (employees x required qualifications) in the resource,
         * this is the compliance percentage on every job-title card.
         */
        $completedQualsSubQuery = DB::table('users as hu')
            ->selectRaw('COUNT(*)')
            ->join('job_title_qualification_skill as hq', 'hq.job_title_id', '=', 'hu.job_title_id')
            ->whereColumn('hu.job_title_id', 'job_titles.id')
            ->whereRaw(QualificationHolding::holdsSql('hu.id', 'hq.qualification_skill_id'));

        return $this->model->newQuery()
            ->when($search, fn ($q) => $q->where(function ($q2) use ($search) {
                $q2->where('name',    'LIKE', "%{$search}%")
                   ->orWhere('name_en', 'LIKE', "%{$search}%")
                   ->orWhere('name_ar', 'LIKE', "%{$search}%");
            }))
            ->withCount(['qualificationSkills', 'users as employees_count'])
            ->addSelect([
                'job_titles.*',
                DB::raw("({$learnersSubQuery->toSql()}) as learners_count"),
                DB::raw("({$completedQualsSubQuery->toSql()}) as completed_qualifications_count"),
            ])
            ->mergeBindings($learnersSubQuery)
            ->mergeBindings($completedQualsSubQuery)
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function allForSelect(): Collection
    {
        // Select the bilingual columns too: JobTitleResource resolves the label
        // via JobTitle::getLocalizedName(), which reads name_en / name_ar. If
        // those columns aren't selected they load as null and the resource
        // silently falls back to the (Arabic) `name` for every locale.
        return $this->model->newQuery()
            ->select(['id', 'name', 'name_ar', 'name_en'])
            ->orderBy('name')
            ->get();
    }

    public function syncQualifications(JobTitle $jobTitle, array $qualIds): void
    {
        $jobTitle->qualificationSkills()->sync($qualIds);
    }
}
