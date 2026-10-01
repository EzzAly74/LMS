<?php

namespace App\Models;

use App\Models\Concerns\ScopedToAdminCourses;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UsersCourse extends Model
{
    use ScopedToAdminCourses;

    use HasFactory;
    protected $guarded = ['id'];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function course()
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function group()
    {
        return $this->belongsTo(CourseSection::class, 'group_id');
    }
}
