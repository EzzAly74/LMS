<?php

namespace App\Http\Requests\Api;

use App\Http\Traits\AcceptsEnumIds;
use Illuminate\Foundation\Http\FormRequest;

class CourseRequest extends FormRequest
{
    use AcceptsEnumIds;

    public const IMAGE_MAX_KB = 3072;
    public const TEXT_MAX     = 10000;
    public const POINTS_MAX   = 50;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Enum fields on this request. Frontends may submit either the numeric
     * dropdown id (preferred, matches `/api/v1/enums/course_type`) OR the
     * legacy string code — the trait normalizes both to the string before
     * validation runs.
     */
    protected function enumFieldMap(): array
    {
        return [
            'course_type' => 'course_type',
            'type'        => 'course_type',
            'level'       => 'course_level',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeEnumIdsToCodes();

        $merge = [];

        if ($this->has('instructor_id') && ! $this->has('instructors')) {
            $merge['instructors'] = [(int) $this->input('instructor_id')];
        }

        if ($this->has('type') && ! $this->has('course_type')) {
            // Normalize legacy `type` field to the full course_type enum code.
            $allowed = ['online', 'offline', 'hybrid', 'external_link'];
            $type    = $this->input('type');
            $merge['course_type'] = in_array($type, $allowed, true) ? $type : 'offline';
        }

        if ($this->has('qualification_ids') && ! $this->has('qualification_skill_ids')) {
            $merge['qualification_skill_ids'] = $this->input('qualification_ids');
        }

        if (! $this->has('hours')) {
            $merge['hours'] = 1;
        }

        if (is_string($this->input('title'))) {
            $merge['title'] = ['en' => $this->input('title'), 'ar' => $this->input('title')];
        }

        if (is_string($this->input('description'))) {
            $merge['description'] = ['en' => $this->input('description'), 'ar' => $this->input('description')];
        }

        // The bilingual bullet lists come over multipart as JSON strings
        // (so an empty list survives the round-trip and clearing all points
        // actually persists). Decode them back into arrays before validation.
        foreach (['what_students_will_learn', 'requirements'] as $field) {
            $value = $this->input($field);
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                $merge[$field] = is_array($decoded) ? $decoded : [];
            }
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    /**
     * The Add / Edit Course modal (D6, Figma 2401:126596 / 2401:126340).
     *
     * Both titles are required; description and the bullet lists are optional.
     * The planned session count and the first cohort's dates left the modal -
     * cohorts are created on Course Details - so they are optional here and
     * still accepted from older callers. The image is required on create:
     * PNG / JPG / WEBP / GIF by content (never SVG, D-044), up to 3 MB.
     * `certificate_rule` is 'general' (Platform Config) or the course's own
     * basis, whose thresholds are then required (D-058).
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $imageRule = ($creating ? 'required' : 'nullable').'|image|mimes:png,jpg,jpeg,webp,gif|max:'.self::IMAGE_MAX_KB;

        return [
            'course_type'             => ($creating ? 'required' : 'sometimes').'|in:online,offline,hybrid,external_link',
            'title'                   => 'required|array',
            'title.en'                => 'required|string|max:255',
            'title.ar'                => 'required|string|max:255',
            'title_for_certificate'   => 'nullable|string|max:255',
            'description'             => 'nullable|array',
            'description.en'          => 'nullable|string|max:'.self::TEXT_MAX,
            'description.ar'          => 'nullable|string|max:'.self::TEXT_MAX,
            // Category is optional (Figma: the field carries no required
            // marker). A blank/absent value is fine; when present it must
            // reference a real category.
            'category_id'             => 'nullable|exists:categories,id',
            // Bilingual bullet lists (Overview tab). Each locale is an
            // optional array of short strings.
            'what_students_will_learn'      => 'nullable|array',
            'what_students_will_learn.en'   => 'nullable|array|max:'.self::POINTS_MAX,
            'what_students_will_learn.en.*' => 'string|max:500',
            'what_students_will_learn.ar'   => 'nullable|array|max:'.self::POINTS_MAX,
            'what_students_will_learn.ar.*' => 'string|max:500',
            'requirements'                  => 'nullable|array',
            'requirements.en'               => 'nullable|array|max:'.self::POINTS_MAX,
            'requirements.en.*'             => 'string|max:500',
            'requirements.ar'               => 'nullable|array|max:'.self::POINTS_MAX,
            'requirements.ar.*'             => 'string|max:500',
            'intro_video'             => 'nullable|string',
            'price'                   => 'nullable|numeric|min:0',
            'currency'                => 'nullable|string|max:10',
            'hours'                   => 'required|integer|min:1',
            'max_learners'            => 'nullable|integer|min:1|max:10000',
            'number_of_sessions'      => 'nullable|integer|min:1|max:1000',
            'language'                => 'nullable|string|max:50',
            // Kept beside the drawn fields (human, 2026-09-27): the Website
            // card badge and the Website / mobile level filters read it.
            'level'                   => ($creating ? 'required' : 'nullable').'|in:beginner,intermediate,professional',
            'certificate'             => 'sometimes|boolean',
            'certificate_rule'        => ($creating ? 'required' : 'sometimes').'|in:general,attendance,score,both',
            'certificate_min_attendance' => 'nullable|required_if:certificate_rule,attendance,both|integer|between:1,100',
            'certificate_min_score'      => 'nullable|required_if:certificate_rule,score,both|integer|between:1,100',
            'image'                   => $imageRule,
            'active'                  => 'nullable|boolean',
            'outside_materials'       => 'nullable|boolean',
            'is_evaluate'             => 'nullable|boolean',
            'allow_attendances'       => 'nullable|boolean',
            'instructors'             => 'required|array|min:1|max:20',
            'instructors.*'           => 'required|integer|distinct|exists:instructors,id',
            'qualification_skill_ids' => 'nullable|array',
            'qualification_skill_ids.*' => 'integer|distinct|exists:qualification_skills,id',
            'cohort_start'            => 'nullable|date_format:Y-m-d',
            'cohort_end'              => 'nullable|date_format:Y-m-d|after_or_equal:cohort_start',
        ];
    }
}
