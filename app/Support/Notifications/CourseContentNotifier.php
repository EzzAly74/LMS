<?php

namespace App\Support\Notifications;

use App\Models\Course;
use App\Models\User;
use App\Notifications\CourseContentAddedNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Tells the course's enrolled learners about new content (NEW2B-5803). Sent
 * after the response, in chunks, so adding content never waits on it and a
 * large course does not load every learner at once. Content is course-wide
 * (D-079), so every enrolled learner is told.
 */
class CourseContentNotifier
{
    /**
     * @param  'module'|'quiz'  $kind
     * @param  array{en: string, ar: string}  $title
     */
    public function added(Course $course, string $kind, array $title, int $itemId): void
    {
        $courseId = (int) $course->id;
        dispatch(function () use ($courseId, $kind, $title, $itemId): void {
            $course = Course::query()->find($courseId);
            if ($course === null) {
                return;
            }
            User::query()
                ->whereIn('id', fn ($q) => $q->select('user_id')->from('users_courses')->where('course_id', $courseId))
                ->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'active'))
                ->select(['id', 'email'])
                ->chunkById(200, fn ($learners) => Notification::send(
                    $learners,
                    new CourseContentAddedNotification($course, $kind, $title, $itemId),
                ));
        })->afterResponse();
    }
}
