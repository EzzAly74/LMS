<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Courses saved before the level list became beginner / intermediate /
 * professional (CourseRequest) can still hold `medium`, which no client can
 * translate (the Website printed `feature.catalogue.level.medium`). Mapped to
 * `intermediate`, the same middle level (human-approved, 2026-10-04).
 * `down()` cannot tell mapped rows from real intermediate ones, so it is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('courses')->where('level', 'medium')->update(['level' => 'intermediate']);
    }

    public function down(): void
    {
        // Irreversible by design: see the class comment.
    }
};
