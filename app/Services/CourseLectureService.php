<?php

namespace App\Services;

use App\Models\Course;
use App\Models\CourseLecture;
use App\Models\CourseSection;
use App\Repositories\Contracts\CourseLectureRepositoryInterface;
use App\Support\Notifications\CourseContentNotifier;
use Illuminate\Database\Eloquent\Collection;

class CourseLectureService
{
    public function __construct(
        private readonly CourseLectureRepositoryInterface $repo
    ) {}

    public function listForCourse(Course $course): Collection
    {
        return $this->repo->sectionsWithLecturesForCourse($course);
    }

    /**
     * Flat list of every lecture (Module) belonging to a course, ordered by
     * creation. Powers the admin "Content" tab modules list which intentionally
     * presents content as a single numbered list rather than grouped sections.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, CourseLecture>
     */
    public function listFlatForCourse(Course $course): Collection
    {
        return $course->lectures()->orderBy('id')->get();
    }

    /**
     * The course "Content" tab (Figma 342:11813, D-109): modules, quizzes and
     * assignments in one numbered list, in the order they were added, each
     * with the code the schedule sheet takes. Drafts are included (admins
     * see them; learners never get this list).
     *
     * @return list<array{kind: string, id: int, code: int|string, title: string, content_type: ?string, duration_minutes: ?int, status: ?string, due_date: ?string, at: string}>
     */
    public function contentForCourse(Course $course): array
    {
        $locale = app()->getLocale();
        $items = [];

        foreach ($course->lectures()->orderBy('id')->get(['id', 'title', 'content_type', 'duration_minutes', 'created_at']) as $m) {
            $items[] = [
                'kind' => 'lecture', 'id' => (int) $m->id, 'code' => \App\Support\SessionContent::token('lecture', (int) $m->id),
                'title' => (string) $m->getTranslation('title', $locale),
                'content_type' => $m->content_type, 'duration_minutes' => $m->duration_minutes !== null ? (int) $m->duration_minutes : null,
                'status' => null, 'due_date' => null, 'at' => (string) $m->created_at,
            ];
        }
        foreach (\App\Models\CourseExam::query()->where('course_id', $course->id)->orderBy('id')
            ->get(['id', 'title', 'title_ar', 'duration', 'status', 'due_date', 'created_at']) as $q) {
            $title = (string) $q->getTranslation('title', $locale);
            $items[] = [
                'kind' => 'quiz', 'id' => (int) $q->id, 'code' => \App\Support\SessionContent::token('quiz', (int) $q->id),
                'title' => $locale === 'ar' && trim((string) $q->title_ar) !== '' ? (string) $q->title_ar : $title,
                'content_type' => null, 'duration_minutes' => $q->duration !== null ? (int) $q->duration : null,
                'status' => $q->status, 'due_date' => $q->due_date !== null ? substr((string) $q->due_date, 0, 10) : null,
                'at' => (string) $q->created_at,
            ];
        }
        foreach (\App\Models\CourseAssignment::query()->where('course_id', $course->id)->orderBy('id')
            ->get(['id', 'title', 'title_ar', 'status', 'due_date', 'created_at']) as $a) {
            $items[] = [
                'kind' => 'assignment', 'id' => (int) $a->id, 'code' => \App\Support\SessionContent::token('assignment', (int) $a->id),
                'title' => $a->localizedTitle($locale),
                'content_type' => null, 'duration_minutes' => null,
                'status' => $a->status, 'due_date' => $a->due_date !== null ? substr((string) $a->due_date, 0, 10) : null,
                'at' => (string) $a->created_at,
            ];
        }

        $rank = ['lecture' => 0, 'quiz' => 1, 'assignment' => 2];
        usort($items, fn ($x, $y) => [$x['at'], $rank[$x['kind']], $x['id']] <=> [$y['at'], $rank[$y['kind']], $y['id']]);

        return $items;
    }

    public function create(Course $course, array $data): CourseLecture
    {
        $data = $this->prepare($data, $course);
        $lecture = $this->repo->createForCourse($course, $data);

        // Enrolled learners hear about it (NEW2B-5803).
        app(CourseContentNotifier::class)->added($course, 'module', [
            'en' => (string) $lecture->getTranslation('title', 'en', false),
            'ar' => (string) $lecture->getTranslation('title', 'ar', false),
        ], (int) $lecture->id);

        return $lecture;
    }

    public function update(CourseLecture $lecture, array $data): CourseLecture
    {
        $data = $this->prepare($data, $lecture->course);
        /** @var CourseLecture */
        return $this->repo->update($lecture, $data);
    }

    public function delete(CourseLecture $lecture): void
    {
        $this->repo->delete($lecture);
    }

    /**
     * Normalize the validated payload before it hits the repository:
     *   - Ensure `section_id` always refers to a real section belonging to the
     *     course — creating a default section when none exists so the admin
     *     "+ Module" flow never has to ask the user about sections.
     *   - Derive `type` (url|file) from `content_type` when not explicit.
     */
    private function prepare(array $data, ?Course $course): array
    {
        if ($course) {
            $section = $this->resolveSection($course, $data['section_id'] ?? null);
            $data['section_id'] = $section->id;
        }

        $contentType = $data['content_type'] ?? 'video';

        if ($contentType === 'article') {
            // Article body belongs in `content`. Tolerate a legacy client that
            // still sends the HTML under `video` by folding it across.
            if (empty($data['content']) && ! empty($data['video'])) {
                $data['content'] = $data['video'];
            }
            $data['video'] = null;
            $data['type']  = 'article';
        } else {
            // url/file content types never carry a rich-text body.
            $data['content'] = null;
            $data['content_en'] = null;
            if (empty($data['type'])) {
                $data['type'] = $contentType === 'document' ? 'file' : 'url';
            }
        }
        // Only a document has a second (English) file.
        if ($contentType !== 'document') {
            $data['video_en'] = null;
            $data['file_name_en'] = null;
        }
        // An empty English editor is no English text.
        if (array_key_exists('content_en', $data) && trim(strip_tags((string) $data['content_en'])) === '') {
            $data['content_en'] = null;
        }

        return $data;
    }

    private function resolveSection(Course $course, ?int $sectionId): CourseSection
    {
        if ($sectionId) {
            $section = $course->sections()->whereKey($sectionId)->first();
            if ($section) {
                return $section;
            }
        }

        $section = $course->sections()->orderBy('id')->first();
        if ($section) {
            return $section;
        }

        // No sections yet — provision a single hidden "Default" section that
        // groups every lecture for this course. Title is bilingual so it
        // satisfies the translatable contract.
        return $course->sections()->create([
            'name' => ['en' => 'Default', 'ar' => 'الافتراضي'],
        ]);
    }
}
