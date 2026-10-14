<?php

namespace App\Services;

use App\Http\Traits\HasFile;
use App\Models\Blog;
use App\Models\JobTitle;
use App\Repositories\Contracts\BlogRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BlogService
{
    use HasFile;

    public function __construct(
        private readonly BlogRepositoryInterface $repo
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Reads
    |--------------------------------------------------------------------------
    */

    public function paginate(int $perPage, array $filters = []): LengthAwarePaginator
    {
        return $this->repo->paginateWithFilters($perPage, $filters);
    }

    public function findBySlug(string $slug, bool $onlyPublished = true): ?Blog
    {
        return $this->repo->findBySlug($slug, $onlyPublished);
    }

    public function findForEdit(Blog $blog): Blog
    {
        return $blog->load(['author', 'creator', 'qualificationSkills', 'sections']);
    }

    public function related(Blog $blog, int $limit = 3): Collection
    {
        return $this->repo->related($blog, $limit);
    }

    /**
     * Toggle the authenticated learner's "love" on a blog.
     *
     * @return array{loved: bool, love_count: int}
     */
    public function toggleLike(Blog $blog, int $userId): array
    {
        $existing = $blog->likes()->where('user_id', $userId)->first();

        if ($existing) {
            $existing->delete();
            $loved = false;
        } else {
            $blog->likes()->create(['user_id' => $userId]);
            $loved = true;
        }

        return [
            'loved'      => $loved,
            'love_count' => $blog->likes()->count(),
        ];
    }

    /**
     * Job titles usable as listing filters — ONLY those linked (via
     * job_title_qualification_skill) to a qualification that is actually
     * assigned to a published blog. Localized to the active locale.
     *
     * @return array<int, array{id: int, name: string}>
     */
    /**
     * Published blogs per level, for the Level filter (NEW2B-5888).
     *
     * @return list<array{value: string, count: int}>
     */
    public function levelFilters(): array
    {
        $counts = Blog::query()->published()->whereNotNull('level')
            ->groupBy('level')->selectRaw('level, COUNT(*) AS c')->pluck('c', 'level');

        return array_map(
            static fn (string $level) => ['value' => $level, 'count' => (int) ($counts[$level] ?? 0)],
            ['beginner', 'intermediate', 'professional'],
        );
    }

    public function jobTitleFilters(): array
    {
        $publishedBlogIds = Blog::query()->published()->pluck('id');

        $qualIds = DB::table('blog_qualification_skill')
            ->whereIn('blog_id', $publishedBlogIds)
            ->distinct()
            ->pluck('qualification_skill_id')
            ->all();

        if (! $qualIds) {
            return [];
        }

        $jobTitleIds = DB::table('job_title_qualification_skill')
            ->whereIn('qualification_skill_id', $qualIds)
            ->distinct()
            ->pluck('job_title_id')
            ->all();

        // Published blogs per job title (NEW2B-5888): a blog counts once for a
        // job title that requires any of its qualifications.
        $counts = DB::table('job_title_qualification_skill as jtqs')
            ->join('blog_qualification_skill as bqs', 'bqs.qualification_skill_id', '=', 'jtqs.qualification_skill_id')
            ->whereIn('jtqs.job_title_id', $jobTitleIds)
            ->whereIn('bqs.blog_id', $publishedBlogIds)
            ->groupBy('jtqs.job_title_id')
            ->selectRaw('jtqs.job_title_id, COUNT(DISTINCT bqs.blog_id) AS c')
            ->pluck('c', 'job_title_id');

        return JobTitle::query()
            ->whereIn('id', $jobTitleIds)
            ->get()
            ->map(fn (JobTitle $jt) => ['id' => $jt->id, 'name' => $jt->getLocalizedName(), 'count' => (int) ($counts[$jt->id] ?? 0)])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Writes
    |--------------------------------------------------------------------------
    */

    public function create(Request $request): Blog
    {
        $data = $request->validated();

        return DB::transaction(function () use ($request, $data) {
            $attributes = $this->baseAttributes($data);
            $attributes['slug']               = $this->uniqueSlug($this->titleForSlug($data));
            $attributes['created_by_admin_id'] = $request->user()?->id;
            $attributes['image']              = $request->hasFile('image')
                ? $this->uploadImageFile('Blog', $request->file('image'))
                : null;

            /** @var Blog $blog */
            $blog = $this->repo->create($attributes);

            $blog->qualificationSkills()->sync($data['qualification_skill_ids'] ?? []);
            $this->syncSections($blog, $request, $data['sections'] ?? []);

            return $this->findForEdit($blog);
        });
    }

    public function update(Blog $blog, Request $request): Blog
    {
        $data = $request->validated();

        return DB::transaction(function () use ($blog, $request, $data) {
            $attributes = $this->baseAttributes($data);
            if ($request->hasFile('image')) {
                $attributes['image'] = $this->uploadImageFile('Blog', $request->file('image'));
            }
            // Keep the existing slug stable across edits.

            $this->repo->update($blog, $attributes);

            if (array_key_exists('qualification_skill_ids', $data)) {
                $blog->qualificationSkills()->sync($data['qualification_skill_ids'] ?? []);
            }
            $this->syncSections($blog, $request, $data['sections'] ?? []);

            return $this->findForEdit($blog->refresh());
        });
    }

    public function delete(Blog $blog): void
    {
        $this->repo->delete($blog); // sections cascade via FK
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Column values shared by create + update (excludes slug / image / creator).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function baseAttributes(array $data): array
    {
        return [
            'title'                  => $data['title'],
            'subtitle'               => $data['subtitle'] ?? null,
            'level'                  => $data['level'],
            'is_anonymous'           => (bool) ($data['is_anonymous'] ?? false),
            'author_user_id'         => ($data['is_anonymous'] ?? false) ? null : ($data['author_user_id'] ?? null),
            'reading_time'           => $data['reading_time'],
            'active'                 => (bool) ($data['active'] ?? true),
            'published_at'           => $data['published_at'] ?? now()->toDateString(),
        ];
    }

    /**
     * Create/update/prune a blog's sections to match the submitted payload.
     *
     * @param  array<int, array<string, mixed>>  $sections
     */
    private function syncSections(Blog $blog, Request $request, array $sections): void
    {
        $keptIds = [];

        foreach (array_values($sections) as $index => $section) {
            $attributes = [
                'title'      => $section['title'],
                'body'       => $section['body'],
                'quote'      => ($section['quote'] ?? null) ?: null,
                'sort_order' => $section['sort_order'] ?? $index,
                'image'      => $this->resolveSectionImage($request, $index, $section),
            ];

            $existingId = isset($section['id']) ? (int) $section['id'] : null;
            $model = $existingId ? $blog->sections()->whereKey($existingId)->first() : null;

            if ($model) {
                $model->update($attributes);
            } else {
                $model = $blog->sections()->create($attributes);
            }

            $keptIds[] = $model->id;
        }

        $blog->sections()->whereKeyNot($keptIds)->delete();
    }

    /**
     * A section image can arrive as a fresh upload (multipart file) or as the
     * previously-stored path string on edit. Anything else clears it.
     *
     * @param  array<string, mixed>  $section
     */
    private function resolveSectionImage(Request $request, int $index, array $section): ?string
    {
        $file = $request->file("sections.{$index}.image");
        if ($file instanceof UploadedFile) {
            return $this->uploadImageFile('Blog', $file, "sections.{$index}.image");
        }

        $value = $section['image'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function titleForSlug(array $data): string
    {
        return (string) ($data['title']['en'] ?? $data['title']['ar'] ?? 'blog');
    }

    private const SLUG_MAX = 150;

    private function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        // Bounded so a suffix still fits the 255-character column.
        $base = rtrim(mb_substr(arabicSlug($title), 0, self::SLUG_MAX), '-') ?: 'blog';
        $slug = $base;
        $suffix = 2;

        while (
            Blog::query()
                ->where('slug', $slug)
                ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
                ->exists()
        ) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
