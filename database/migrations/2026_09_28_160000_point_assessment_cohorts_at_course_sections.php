<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B-137: a quiz or assignment limited to "specific cohorts" stored class
 * SESSIONS (course_sessions: "session 1", "session 2") instead of COHORTS
 * (course_sections: "First Group", "Second Group"), so the picker listed
 * sessions and the scope pointed at the wrong thing.
 *
 * Each pivot gets a course_section_id. Existing rows are carried over
 * through course_sessions.section_id (duplicates collapse to one row per
 * cohort); the course_session_id column is then dropped.
 */
return new class extends Migration {
    private const PIVOTS = [
        'course_assignment_cohorts' => ['parent' => 'course_assignment_id', 'prefix' => 'cac', 'unique' => 'cac_unique'],
        'course_exam_cohorts'       => ['parent' => 'course_exam_id', 'prefix' => 'cec', 'unique' => 'course_exam_cohorts_unique'],
    ];

    public function up(): void
    {
        foreach (self::PIVOTS as $table => ['parent' => $parent, 'prefix' => $p, 'unique' => $u]) {
            Schema::table($table, function (Blueprint $t) {
                $t->unsignedBigInteger('course_section_id')->nullable()->after('id');
            });

            // Carry each session row over to its cohort, once per parent.
            $rows = DB::table($table)
                ->join('course_sessions', 'course_sessions.id', '=', "$table.course_session_id")
                ->whereNotNull('course_sessions.section_id')
                ->select("$table.id", "$table.$parent as parent", 'course_sessions.section_id')
                ->orderBy("$table.id")
                ->get();

            $seen = [];
            foreach ($rows as $row) {
                $key = $row->parent . ':' . $row->section_id;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                DB::table($table)->where('id', $row->id)->update(['course_section_id' => $row->section_id]);
            }
            DB::table($table)->whereNull('course_section_id')->delete();

            $fks = collect(Schema::getForeignKeys($table))->pluck('name')->all();
            $indexes = collect(Schema::getIndexes($table))->pluck('name')->all();

            Schema::table($table, function (Blueprint $t) use ($fks, $indexes, $parent, $p, $u) {
                foreach ($fks as $fk) {
                    if (str_contains($fk, 'session')) {
                        $t->dropForeign($fk);
                    }
                }
                if (in_array($u, $indexes, true)) {
                    // The parent FK needs an index while the unique goes.
                    $t->index($parent, "{$p}_parent_idx");
                    $t->dropUnique($u);
                }
            });

            Schema::table($table, function (Blueprint $t) use ($parent, $p) {
                $t->dropColumn('course_session_id');
                $t->unsignedBigInteger('course_section_id')->nullable(false)->change();
                $t->foreign('course_section_id', "{$p}_section_fk")->references('id')->on('course_sections')->cascadeOnDelete();
                $t->unique([$parent, 'course_section_id'], "{$p}_section_unique");
            });
        }
    }

    public function down(): void
    {
        // Sessions cannot be recovered from a cohort; rows are dropped.
        foreach (self::PIVOTS as $table => ['parent' => $parent, 'prefix' => $p, 'unique' => $u]) {
            DB::table($table)->delete();
            Schema::table($table, function (Blueprint $t) use ($p) {
                $t->dropForeign("{$p}_section_fk");
                $t->dropUnique("{$p}_section_unique");
                $t->dropColumn('course_section_id');
            });
            Schema::table($table, function (Blueprint $t) use ($parent, $p, $u) {
                $t->unsignedBigInteger('course_session_id');
                $t->foreign('course_session_id', "{$p}_session_fk")->references('id')->on('course_sessions')->cascadeOnDelete();
                $t->unique([$parent, 'course_session_id'], $u);
            });
        }
    }
};
