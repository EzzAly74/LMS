<?php

namespace App\Http\Requests\Api;

use App\Models\Course;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CourseLectureRequest extends FormRequest
{
    /** Longest article body, in characters (the Dashboard checks the same). */
    public const ARTICLE_MAX = 500000;

    public function authorize(): bool { return true; }

    public function rules(): array
    {
        // The route binds `{course}` to a Course model (implicit binding);
        // fall back to the raw id when it's not yet resolved.
        $routeCourse = $this->route('course');
        $courseId = $routeCourse instanceof Course ? $routeCourse->id : $routeCourse;
        $contentType = $this->input('content_type');
        $isArticle   = $contentType === 'article';

        // Article bodies live in the dedicated `content` column (rich-text
        // HTML); every other content type puts a URL or a stored file path in
        // `video`. Only one of the two is required, depending on the type.
        $videoRules = $isArticle ? ['nullable', 'string', 'max:2048'] : ['required', 'string'];
        if (! $isArticle && $contentType === 'link') {
            $videoRules[] = 'url';
            $videoRules[] = 'max:2048';
        } elseif (! $isArticle) {
            $videoRules[] = 'max:2048';
        }

        // NEW2B-5763: text pasted from Word or a web page keeps its inline
        // styles, so a few pages of article are well over 64 KB of HTML. The
        // column is LONGTEXT; the cap stays under the usual 1 MB request limit.
        $contentRules = $isArticle
            ? ['required', 'string', 'max:'.self::ARTICLE_MAX]
            : ['nullable', 'string', 'max:'.self::ARTICLE_MAX];

        return [
            // `section_id` is optional: the service will fall back to a
            // course-default section when omitted, since the admin "Module"
            // form intentionally hides section selection.
            'section_id'         => [
                'nullable',
                'integer',
                Rule::exists('course_sections', 'id')->where(fn ($q) => $q->where('course_id', $courseId)),
            ],

            'title'              => 'required|array',
            'title.ar'           => 'required|string|max:255',
            'title.en'           => 'nullable|string|max:255',

            'content_type'       => 'required|in:video,document,article,link',
            // A module belongs to the whole course. Which sessions cover it
            // is set by each cohort's schedule ("content" column, D-079), so
            // the old learner_scope / session_id / session_number fields are
            // no longer accepted.

            'duration_minutes'   => 'nullable|integer|min:0|max:10000',

            // `type` reflects how the primary payload is stored:
            // url | file (in `video`) or article (rich-text in `content`).
            // Derived from `content_type` in the service if absent.
            'type'               => 'nullable|in:url,file,article',
            'video'              => $videoRules,
            // Rich-text HTML body for article modules (dedicated column).
            'content'            => $contentRules,

            // Optional original filename for uploaded documents — preserved so
            // the Edit dialog can render "File Title.pdf · 313 KB" instead of a
            // storage hash. Ignored for URL-based content types.
            'file_name'          => 'nullable|string|max:255',

            'instructions'       => 'nullable|array',
            'instructions.en'    => 'nullable|string|max:1000',
            'instructions.ar'    => 'nullable|string|max:1000',

            'require_completion' => 'nullable|boolean',
        ];
    }
}
