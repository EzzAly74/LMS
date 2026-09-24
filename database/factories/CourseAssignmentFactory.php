<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\CourseAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CourseAssignment>
 *
 * Added in Phase 4 / Stage A so the B-02 upload-security regression tests can
 * build an assignment. The model already used HasFactory but no factory
 * existed — the same gap that makes CourseApiTest fail on a missing
 * InstructorFactory (tracked separately).
 */
class CourseAssignmentFactory extends Factory
{
    protected $model = CourseAssignment::class;

    public function definition(): array
    {
        return [
            'course_id'    => Course::factory(),
            'title'        => $this->faker->sentence(3),
            'title_ar'     => $this->faker->sentence(3),
            'file'         => null,
            'due_date'     => now()->addWeek()->toDateString(),
            'cohort_scope' => 'all',
            'total_score'  => 100,
            'pass_score'   => 50,
            'status'       => 'active',
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => 'draft']);
    }
}
