<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Single question on a course_assignment. See migration for `type` semantics.
 */
class CourseAssignmentQuestion extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'position'   => 'integer',
        'score'      => 'integer',
        'options_en' => 'array',
        'options_ar' => 'array',
        'attachment_size' => 'integer',
        'attachment_uploaded_at' => 'datetime',
    ];

    /** Private-disk key; served only through an authorized route (D-031). */
    protected $hidden = ['attachment_path'];

    public const TYPE_MCQ     = 'mcq';
    public const TYPE_YES_NO  = 'yes_no';
    public const TYPE_OPEN    = 'open';
    public const TYPE_REORDER = 'reorder';
    /** The learner uploads a file; graded by hand (D-033, D-064). */
    public const TYPE_FILE    = 'file';

    public const TYPES = [self::TYPE_MCQ, self::TYPE_YES_NO, self::TYPE_OPEN, self::TYPE_REORDER, self::TYPE_FILE];

    /** Types with no correct answer: a person scores them. */
    public const MANUAL_TYPES = [self::TYPE_OPEN, self::TYPE_FILE];

    public function isFile(): bool
    {
        return $this->type === self::TYPE_FILE;
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(CourseAssignment::class, 'course_assignment_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(UserCourseAssignmentAnswer::class, 'course_assignment_question_id');
    }
}
