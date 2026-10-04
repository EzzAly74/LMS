<?php

declare(strict_types=1);

namespace App\Services\Learner;

use App\Models\Course;
use App\Models\CourseNotifyInterest;
use App\Models\User;
use App\Notifications\CohortOpenedNotification;
use App\Repositories\Contracts\Mobile\AcademyRepositoryInterface;
use App\Services\Mobile\MobileSettings;
use Illuminate\Support\Facades\DB;

/**
 * Tells the learners who asked ("Get notified" / "Notify me", NEW2B-5780)
 * that a course has a cohort they can join, by bell and email, once.
 *
 * "Can join" is the catalogue's own rule (AcademyRepository::nextJoinableCohort),
 * so nobody is told about a cohort the catalogue would not offer. The interest
 * rows are claimed and deleted in one locked transaction before anything is
 * sent, so a cohort save and the daily run never notify the same learner twice.
 */
final class CohortInterestNotifier
{
    private const CHUNK = 200;

    public function __construct(
        private readonly AcademyRepositoryInterface $academy,
        private readonly MobileSettings $settings,
    ) {}

    /** @return int learners notified */
    public function notifyForCourse(int $courseId): int
    {
        $course = Course::query()->find($courseId);
        if ($course === null) {
            return 0;
        }

        $cohort = $this->academy->nextJoinableCohort(
            $course,
            null,
            now(),
            $this->settings->academyDefaultCloseOffsetDays(),
            $this->settings->academyScheduledVisibilityDays(),
        );
        if ($cohort === null) {
            return 0; // keep the requests until a cohort really opens
        }

        $userIds = DB::transaction(function () use ($courseId) {
            $rows = CourseNotifyInterest::query()
                ->where('course_id', $courseId)
                ->lockForUpdate()
                ->get(['id', 'user_id']);
            CourseNotifyInterest::query()->whereKey($rows->pluck('id'))->delete();

            return $rows->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        });
        if ($userIds === []) {
            return 0;
        }

        // Someone who enrolled meanwhile needs no reminder.
        $enrolled = DB::table('users_courses')->where('course_id', $courseId)->whereIn('user_id', $userIds)->pluck('user_id')->all();
        $sent = 0;
        foreach (array_chunk(array_values(array_diff($userIds, $enrolled)), self::CHUNK) as $chunk) {
            foreach (User::query()->whereKey($chunk)->get() as $user) {
                try {
                    $user->notify(new CohortOpenedNotification($course, $cohort));
                    $sent++;
                } catch (\Throwable $e) {
                    report($e); // one bad mailbox stops nobody else
                }
            }
        }

        return $sent;
    }

    /** Every course someone is waiting for (the daily run). @return int learners notified */
    public function notifyAll(): int
    {
        $sent = 0;
        foreach (CourseNotifyInterest::query()->distinct()->pluck('course_id') as $courseId) {
            $sent += $this->notifyForCourse((int) $courseId);
        }

        return $sent;
    }
}
