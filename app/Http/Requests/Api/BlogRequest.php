<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BlogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_anonymous' => filter_var($this->input('is_anonymous', false), FILTER_VALIDATE_BOOLEAN),
            'active'       => $this->has('active')
                ? filter_var($this->input('active'), FILTER_VALIDATE_BOOLEAN)
                : true,
        ]);
    }

    public const TITLE_MAX = 100;

    public const IMAGE_RULE = 'image|mimes:png,jpg,jpeg,webp|max:2048';

    public function rules(): array
    {
        $isUpdate = $this->isMethod('PUT') || $this->isMethod('PATCH');

        // NEW2B-5950: PNG, JPG or WebP, at most 2 MB (content-checked by `image` + `mimes`).
        $imageRule = ($isUpdate ? 'nullable' : 'required').'|'.self::IMAGE_RULE;

        return [
            'title'                   => 'required|array',
            // NEW2B-5965: a blog's name is at most 100 characters.
            'title.en'                => 'required|string|max:'.self::TITLE_MAX,
            'title.ar'                => 'required|string|max:'.self::TITLE_MAX,

            'subtitle'                => 'nullable|array',
            'subtitle.en'             => 'nullable|string|max:1000',
            'subtitle.ar'             => 'nullable|string|max:1000',

            'image'                   => $imageRule,
            'level'                   => ['required', Rule::in(['beginner', 'intermediate', 'professional'])],

            'is_anonymous'            => 'boolean',
            'author_user_id'          => 'required_if:is_anonymous,false|nullable|exists:users,id',

            'reading_time'            => 'required|integer|min:1|max:1000',
            'qualification_skill_ids'   => 'nullable|array',
            'qualification_skill_ids.*' => 'integer|exists:qualification_skills,id',

            'active'                  => 'boolean',
            'published_at'            => 'nullable|date',

            'sections'                => 'required|array|min:1',
            'sections.*.id'           => 'nullable|integer',
            'sections.*.title'        => 'required|array',
            'sections.*.title.en'     => 'required|string|max:255',
            'sections.*.title.ar'     => 'required|string|max:255',
            'sections.*.body'         => 'required|array',
            'sections.*.body.en'      => 'required|string',
            'sections.*.body.ar'      => 'required|string',
            'sections.*.quote'        => 'nullable|array',
            'sections.*.quote.en'     => 'nullable|string|max:1000',
            'sections.*.quote.ar'     => 'nullable|string|max:1000',
            // Either a freshly uploaded file, or the existing stored path (edit);
            // an upload follows the cover's rule (NEW2B-5950), checked in after().
            'sections.*.image'        => 'nullable',
            'sections.*.sort_order'   => 'nullable|integer|min:0',
        ];
    }

    /**
     * Field names in the messages (NEW2B-5877 / 5878 / 5890): "The English
     * subtitle must not be greater than 1000 characters", not "subtitle.en".
     */
    public function attributes(): array
    {
        $f = fn (string $key) => __('messages.blog_fields.'.$key);

        return [
            'title.en'            => $f('title_en'),
            'title.ar'            => $f('title_ar'),
            'subtitle.en'         => $f('subtitle_en'),
            'subtitle.ar'         => $f('subtitle_ar'),
            'image'               => $f('image'),
            'level'               => $f('level'),
            'author_user_id'      => $f('author'),
            'reading_time'        => $f('reading_time'),
            'sections'            => $f('sections'),
            'sections.*.title.en' => $f('section_title_en'),
            'sections.*.title.ar' => $f('section_title_ar'),
            'sections.*.body.en'  => $f('section_body_en'),
            'sections.*.body.ar'  => $f('section_body_ar'),
            'sections.*.quote.en' => $f('section_quote_en'),
            'sections.*.quote.ar' => $f('section_quote_ar'),
        ];
    }

    /** A section image upload: the cover's types and size (NEW2B-5950). */
    public function after(): array
    {
        return [function (\Illuminate\Validation\Validator $v) {
            foreach ((array) $this->file('sections', []) as $i => $files) {
                $file = is_array($files) ? ($files['image'] ?? null) : null;
                if (! $file instanceof \Illuminate\Http\UploadedFile) {
                    continue;
                }
                $check = \Illuminate\Support\Facades\Validator::make(['image' => $file], ['image' => self::IMAGE_RULE]);
                foreach ($check->errors()->get('image') as $message) {
                    $v->errors()->add("sections.$i.image", $message);
                }
            }
        }];
    }
}
