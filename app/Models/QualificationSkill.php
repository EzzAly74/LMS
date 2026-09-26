<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Translatable\HasTranslations;

class QualificationSkill extends Model
{
    use HasFactory, HasTranslations;

    public array $translatable = ['name'];

    protected $fillable = ['name'];

    /**
     * Job titles that require this qualification.
     *
     * The inverse of JobTitle::qualificationSkills(), which already existed;
     * this side was missing, so the export and importer had no way to read or
     * write the association without a raw query.
     */
    public function jobTitles(): BelongsToMany
    {
        return $this->belongsToMany(
            JobTitle::class,
            'job_title_qualification_skill',
            'qualification_skill_id',
            'job_title_id',
        )->withTimestamps();
    }

    public function courses()
    {
        return $this->belongsToMany(
            Course::class,
            'course_qualification_skills',
            'qualification_skill_id',
            'course_id',
        );
    }
}
