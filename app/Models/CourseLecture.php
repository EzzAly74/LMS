<?php

namespace App\Models;

use App\Models\Concerns\ScopedToAdminCourses;
use App\Http\Traits\HasFile;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

class CourseLecture extends Model
{
    use ScopedToAdminCourses;

    use HasFactory, HasFile, HasTranslations;

    public array $translatable = ['title', 'instructions'];

    protected $guarded = ['id'];

    protected $casts = [
        'duration_minutes'   => 'integer',
        'require_completion' => 'boolean',
    ];

    public function section()
    {
        return $this->belongsTo(CourseSection::class, 'section_id');
    }

    public function course()
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    /** The cohort sessions that cover this module (D-079). */
    public function sessions()
    {
        return $this->belongsToMany(CourseSession::class, 'course_session_lectures', 'lecture_id', 'session_id');
    }
}
