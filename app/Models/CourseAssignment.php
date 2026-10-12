<?php

namespace App\Models;

use App\Models\Concerns\ScopedToAdminCourses;
use App\Models\Concerns\VisibleToLearnerCohort;
use App\Http\Traits\HasFile;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CourseAssignment extends Model
{
    use ScopedToAdminCourses;
    use VisibleToLearnerCohort;

    use HasFactory, HasFile;

    /** The cohorts chosen when cohort_scope is "specific" (NEW2B-6255). */
    public const COHORT_PIVOT = ['course_assignment_cohorts', 'course_assignment_id'];
    protected $guarded = ['id'];

    protected $casts = [
        'due_date' => 'date',
    ];


    /**
     * The name in the reader's language (NEW2B-6253): `title` is the English
     * name, `title_ar` the Arabic; either falls back to the other.
     */
    public function localizedTitle(?string $locale = null): string
    {
        $en = trim((string) ($this->title ?? ''));
        $ar = trim((string) ($this->title_ar ?? ''));

        return ($locale ?? app()->getLocale()) === 'ar' ? ($ar !== '' ? $ar : $en) : ($en !== '' ? $en : $ar);
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_course_assignments', 'course_assignment_id', 'user_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Additive relationships for the rich-question assignment system.
    | These are NEW methods only — no existing behaviour is modified.
    |--------------------------------------------------------------------------
    */

    public function questions()
    {
        return $this->hasMany(CourseAssignmentQuestion::class, 'course_assignment_id')
            ->orderBy('position');
    }

    public function cohorts()
    {
        return $this->hasMany(CourseAssignmentCohort::class, 'course_assignment_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submissions()
    {
        return $this->hasMany(UserCourseAssignment::class, 'course_assignment_id');
    }
}
