<?php

namespace App\Services;

use App\Events\CourseCohortCreated;
use App\Events\InstructorAssignedToCourse;
use App\Http\Traits\HasFile;
use App\Models\Course;
use App\Models\CourseSection;
use App\Repositories\Contracts\CourseRepositoryInterface;
use App\Services\Admin\AdminEvaluationReportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class CourseService
{
    use HasFile;

    public function __construct(
        private readonly CourseRepositoryInterface $courseRepository,
        private readonly AdminEvaluationReportService $evaluations,
    ) {}

    public function list(
        int     $perPage    = 15,
        ?string $search     = null,
        ?int    $categoryId = null,
        ?bool   $active     = null,
        ?string $courseType = null,
        ?string $status     = null,
        array   $filters    = [],
    ): LengthAwarePaginator {
        $bands = $filters['evaluation'] ?? [];
        unset($filters['evaluation']);

        return $this->courseRepository->paginateWithFilters(
            $perPage, $search, $categoryId, $active, $courseType, $status, $filters,
            $bands !== [] ? fn ($q) => $this->evaluations->whereCourseScoreIn($q, $bands) : null,
        );
    }

    /**
     * @return array{all: int, active: int, inactive: int, pending: int, upcoming: int}
     */
    public function tabCounts(): array
    {
        return $this->courseRepository->tabCounts();
    }

    public function allActive(): Collection
    {
        return $this->courseRepository->allActive();
    }

    public function activePluckedTitles(): Collection
    {
        return $this->courseRepository->activePluckedTitles();
    }

    public function findOrFail(int $id): Course
    {
        return $this->courseRepository->findWithRelations($id);
    }

    /** Flags the modal does not show: false on a new course, untouched on edit. */
    private const FLAGS = ['active', 'outside_materials', 'is_evaluate', 'allow_attendances'];

    public function create(array $data, ?UploadedFile $image = null): Course
    {
        $data['image'] = $image ? $this->uploadImageFile('Course', $image) : null;
        foreach (self::FLAGS as $flag) {
            $data[$flag] = (bool) ($data[$flag] ?? false);
        }
        // Every course saved from the Add / Edit Course modal issues a
        // certificate (human, 2026-09-27); the rule decides how it is earned.
        $data['certificate'] = (bool) ($data['certificate'] ?? true);
        $data['description'] ??= ['en' => '', 'ar' => ''];
        $this->applyCertificateRule($data);

        $instructors           = array_map('intval', $data['instructors'] ?? []);
        $qualificationSkillIds = $data['qualification_skill_ids'] ?? [];
        // Pop the cohort window off the payload so it doesn't trip the
        // Course model's guarded write — it goes onto a CourseSection
        // instead (see syncFirstCohort below).
        $cohortStart = $data['cohort_start'] ?? null;
        $cohortEnd   = $data['cohort_end']   ?? null;
        unset(
            $data['instructors'], $data['qualification_skill_ids'],
            $data['cohort_start'], $data['cohort_end'],
        );

        try {
            $course = DB::transaction(function () use ($data, $instructors, $qualificationSkillIds, $cohortStart, $cohortEnd) {
                $course = $this->courseRepository->create($data);

                if ($instructors) {
                    $course->instructors()->attach($instructors);
                }
                if ($qualificationSkillIds) {
                    $course->qualificationSkills()->sync(array_values(array_unique($qualificationSkillIds)));
                }
                if ($cohortStart || $cohortEnd) {
                    $this->syncFirstCohort($course, $cohortStart, $cohortEnd);
                }

                return $course;
            });
        } catch (Throwable $e) {
            $this->discardImage($data['image']);
            throw $e;
        }

        // After commit, so a rolled-back course never notifies anyone.
        // Brand new course — every attached instructor is newly assigned.
        if ($instructors) {
            event(new InstructorAssignedToCourse($course, $instructors));
        }

        return $this->courseRepository->findWithBasicRelations($course->id);
    }

    public function update(Course $course, array $data, ?UploadedFile $image = null): Course
    {
        $previousImage = $course->image;
        if ($image) {
            $data['image'] = $this->uploadImageFile('Course', $image);
        }
        // B-118: these used to be forced to false whenever a caller left them
        // out, so saving the Edit dialog switched off attendance and the
        // evaluation-based certificate path. Only an explicit value changes them.
        foreach (self::FLAGS as $flag) {
            if (array_key_exists($flag, $data)) {
                $data[$flag] = (bool) $data[$flag];
            }
        }
        if (array_key_exists('certificate_rule', $data)) {
            $data['certificate'] = (bool) ($data['certificate'] ?? true);
            $this->applyCertificateRule($data);
        }

        $instructors           = isset($data['instructors']) ? array_map('intval', $data['instructors']) : null;
        $hasSkillsPayload      = array_key_exists('qualification_skill_ids', $data);
        $qualificationSkillIds = $data['qualification_skill_ids'] ?? null;
        $hasCohortStart        = array_key_exists('cohort_start', $data);
        $hasCohortEnd          = array_key_exists('cohort_end', $data);
        $cohortStart           = $data['cohort_start'] ?? null;
        $cohortEnd             = $data['cohort_end']   ?? null;
        unset(
            $data['instructors'], $data['qualification_skill_ids'],
            $data['cohort_start'], $data['cohort_end'],
        );

        try {
            [$course, $newlyAssigned] = DB::transaction(function () use (
                $course, $data, $instructors, $hasSkillsPayload, $qualificationSkillIds,
                $hasCohortStart, $hasCohortEnd, $cohortStart, $cohortEnd,
            ) {
                $previousInstructorIds = $course->instructors()->pluck('instructors.id')->map(fn ($id) => (int) $id)->all();

                $course = $this->courseRepository->update($course, $data);

                $newlyAssigned = [];
                if (!is_null($instructors)) {
                    $course->instructors()->sync($instructors);
                    $newlyAssigned = array_values(array_diff($instructors, $previousInstructorIds));
                }

                if ($hasSkillsPayload) {
                    $course->qualificationSkills()->sync(
                        array_values(array_unique((array) ($qualificationSkillIds ?? []))),
                    );
                }

                if ($hasCohortStart || $hasCohortEnd) {
                    $this->syncFirstCohort($course, $cohortStart, $cohortEnd);
                }

                return [$course, $newlyAssigned];
            });
        } catch (Throwable $e) {
            if ($image) {
                $this->discardImage($data['image']);
            }
            throw $e;
        }

        if ($newlyAssigned) {
            event(new InstructorAssignedToCourse($course, $newlyAssigned));
        }
        if ($image && $previousImage && $previousImage !== $data['image']) {
            $this->discardImage($previousImage);
        }

        return $this->courseRepository->findWithBasicRelations($course->id);
    }

    /**
     * Turn the modal's `certificate_rule` into the course columns (D-058):
     * 'general' follows Platform Config; any other value is the course's own
     * basis with the thresholds it requires. Thresholds a basis does not use
     * are cleared so they never linger as a hidden rule.
     *
     * @param  array<string, mixed>  $data
     */
    private function applyCertificateRule(array &$data): void
    {
        $rule = $data['certificate_rule'] ?? CertificatePolicy::RULE_GENERAL;
        unset($data['certificate_rule']);
        $attendance = $data['certificate_min_attendance'] ?? null;
        $score      = $data['certificate_min_score'] ?? null;
        unset($data['certificate_min_attendance'], $data['certificate_min_score']);

        if (!in_array($rule, CertificatePolicy::BASES, true)) {
            $data['certificate_custom_rule'] = false;
            return;
        }

        $data['certificate_custom_rule']          = true;
        $data['certificate_mode']                 = $rule;
        $data['certificate_attendance_threshold'] = $rule === CertificatePolicy::BASIS_SCORE ? null : (int) $attendance;
        $data['certificate_score_threshold']      = $rule === CertificatePolicy::BASIS_ATTENDANCE ? null : (int) $score;
    }

    private function discardImage(?string $path): void
    {
        if (!$path) {
            return;
        }
        $disk = config('filesystems.default') == 's3' ? Storage::disk() : Storage::disk('public');
        $disk->delete($path);
    }

    /**
     * Upsert the course's *first* cohort from the inline cohort window
     * captured on the Add / Edit Course dialogs. We treat the earliest
     * (lowest id) `course_sections` row as the canonical first cohort —
     * matching what `CourseDetailResource` surfaces for the form pre-fill.
     *
     * The cohort `status` is intentionally left to the calendar — once
     * the row exists with a date window, `Course::deriveCohortStatus`
     * + the daily `cohorts:sync-statuses` command keep it in sync.
     */
    private function syncFirstCohort(Course $course, ?string $start, ?string $end): void
    {
        $section = $course->sections()->orderBy('id')->first();

        $payload = [
            'start_date' => $start ? Carbon::parse($start)->toDateString() : null,
            'end_date'   => $end   ? Carbon::parse($end)->toDateString()   : null,
        ];

        if ($section === null) {
            // Seed the very first cohort with a sensible default name
            // ("Cohort 1") so the table on the Course Detail page has
            // something to render before the admin renames it. It inherits
            // the course's planned session count as its editable default.
            $newSection = CourseSection::create(array_merge($payload, [
                'course_id'          => $course->id,
                'name'               => ['en' => 'Cohort 1', 'ar' => 'الدفعة 1'],
                'status'             => 'scheduled',
                'number_of_sessions' => $course->number_of_sessions,
            ]));
            event(new CourseCohortCreated($newSection));
            return;
        }

        $section->fill($payload)->save();
    }

    public function delete(Course $course): bool
    {
        return $this->courseRepository->delete($course);
    }
}
