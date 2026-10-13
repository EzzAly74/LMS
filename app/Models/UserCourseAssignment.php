<?php

namespace App\Models;

use App\Models\Concerns\ScopedToAdminCourses;
use App\Http\Traits\HasFile;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserCourseAssignment extends Model
{
    /** The submission has question answers (the question-based flow). */
    public const HAS_ANSWERS_SQL = 'EXISTS (SELECT 1 FROM user_course_assignment_answers uca_a WHERE uca_a.user_course_assignment_id = user_course_assignments.id)';

    /**
     * The grade (B-172, NEW2B-6232): a question-based submission keeps it in
     * total_score and has none while a person still has to score an answer;
     * a file-only (legacy) submission keeps it in `score`.
     */
    public const SCORE_SQL = '(CASE WHEN '.self::HAS_ANSWERS_SQL.' THEN (CASE WHEN EXISTS (SELECT 1 FROM user_course_assignment_answers uca_p WHERE uca_p.user_course_assignment_id = user_course_assignments.id AND uca_p.awarded_score IS NULL) THEN NULL ELSE user_course_assignments.total_score END) ELSE COALESCE(user_course_assignments.score, user_course_assignments.total_score) END)';

    /** A result: question-based once sent; a legacy file submission always. */
    public const SENT_SQL = '(user_course_assignments.submitted_at IS NOT NULL OR NOT '.self::HAS_ANSWERS_SQL.')';

    use ScopedToAdminCourses;

    /** A submission is in scope through its assignment's course (D-074). */
    protected static function constrainToCourses(\Illuminate\Database\Eloquent\Builder $query, array $courseIds): void
    {
        $query->whereIn(
            $query->getModel()->qualifyColumn('course_assignment_id'),
            \Illuminate\Support\Facades\DB::table('course_assignments')->select('id')->whereIn('course_id', $courseIds),
        );
    }

    use HasFactory, HasFile;
    protected $guarded = ['id'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function assignment()
    {
        return $this->belongsTo(CourseAssignment::class , 'course_assignment_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Additive relationships for the rich-question assignment system.
    | These are NEW methods only — no existing behaviour is modified.
    |--------------------------------------------------------------------------
    */

    public function answers()
    {
        return $this->hasMany(UserCourseAssignmentAnswer::class, 'user_course_assignment_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    protected $casts = [
        'submitted_at' => 'datetime',
        'reviewed_at'  => 'datetime',
    ];
}
