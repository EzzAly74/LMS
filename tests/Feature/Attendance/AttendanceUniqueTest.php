<?php

namespace Tests\Feature\Attendance;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * B-197: a learner is present at a session once; the database refuses a
 * second row. Rows without a session (legacy / admin manual marks) repeat.
 */
class AttendanceUniqueTest extends TestCase
{
    use RefreshDatabase;

    private function row(?int $sessionId): array
    {
        return [
            'user_id' => 7, 'course_id' => 3, 'section_id' => 2, 'session_id' => $sessionId,
            'attendance_hours' => 1, 'is_manual' => false, 'created_at' => now(), 'updated_at' => now(),
        ];
    }

    public function test_a_second_row_for_the_same_learner_and_session_is_refused(): void
    {
        DB::table('attendances')->insert($this->row(11));

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('attendances')->insert($this->row(11));
    }

    public function test_other_sessions_and_rows_without_a_session_are_allowed(): void
    {
        DB::table('attendances')->insert($this->row(11));
        DB::table('attendances')->insert($this->row(12));
        DB::table('attendances')->insert($this->row(null));
        DB::table('attendances')->insert($this->row(null));

        $this->assertSame(4, DB::table('attendances')->count());
    }
}
