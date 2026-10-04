<?php

namespace Tests\Feature;

use App\Models\Course;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** 2026_10_04_100000: the legacy `medium` level becomes `intermediate`; nothing else moves. */
class LegacyCourseLevelMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_medium_becomes_intermediate_and_other_levels_stay(): void
    {
        $medium = Course::factory()->create();
        $beginner = Course::factory()->create(['level' => 'beginner']);
        DB::table('courses')->where('id', $medium->id)->update(['level' => 'medium']);

        (require database_path('migrations/2026_10_04_100000_map_legacy_course_level_medium.php'))->up();

        $this->assertSame('intermediate', DB::table('courses')->where('id', $medium->id)->value('level'));
        $this->assertSame('beginner', DB::table('courses')->where('id', $beginner->id)->value('level'));
    }
}
