<?php

namespace App\Services\Admin;

use App\Models\Admin;
use App\Models\QualificationSkill;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Direct learner→qualification grants (D-045).
 *
 * Figma 2066:100876 lets an admin grant a qualification to a person, and the
 * learners list (Q-038) offers the same as a bulk action. Both land here.
 *
 * ── What a grant is, and what it is not ─────────────────────────────────────
 * A grant records that a learner HOLDS a qualification. It is the manual
 * override for an externally-obtained certificate, a legacy award, or
 * recognition of prior learning.
 *
 * It does NOT enrol anyone in a course, and it does NOT fabricate course
 * completions. The course counts on the compliance screens stay a truthful
 * record of what was actually studied — a granted qualification can show
 * "0 of 3 Courses" and still count as held, which is precisely what an
 * external certificate means.
 *
 * ── Why grants are audited ──────────────────────────────────────────────────
 * This path bypasses the normal evidence trail, so every row records who
 * granted it, when, and optionally why. A qualification appearing on a
 * compliance report with no way to ask "who decided this?" would make the
 * report worth less than no report.
 */
class LearnerQualificationService
{
    /**
     * Grant qualifications to one learner.
     *
     * Idempotent: re-granting an existing qualification updates the note and
     * the audit fields rather than erroring or duplicating. The unique index
     * enforces that at the database level too, so a double-submit is safe.
     *
     * @param  list<int>  $skillIds
     * @return array{granted:int, already_held:int}
     */
    public function grant(User $learner, array $skillIds, ?Admin $by = null, ?string $note = null): array
    {
        $skillIds = array_values(array_unique(array_map('intval', $skillIds)));

        if ($skillIds === []) {
            return ['granted' => 0, 'already_held' => 0];
        }

        $existing = DB::table('user_qualification_skill')
            ->where('user_id', $learner->id)
            ->whereIn('qualification_skill_id', $skillIds)
            ->pluck('qualification_skill_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        $new = array_values(array_diff($skillIds, $existing));

        DB::transaction(function () use ($learner, $new, $existing, $by, $note) {
            $now = now();

            if ($new !== []) {
                DB::table('user_qualification_skill')->insert(
                    array_map(fn (int $id) => [
                        'user_id'                => $learner->id,
                        'qualification_skill_id' => $id,
                        'assigned_by'            => $by?->id,
                        'assigned_at'            => $now,
                        'note'                   => $note,
                        'created_at'             => $now,
                        'updated_at'             => $now,
                    ], $new),
                );
            }

            /*
             * Re-granting is not a no-op: an admin re-issuing a qualification
             * with a new note is recording a new decision, and the audit
             * fields should reflect who made it and when.
             */
            if ($existing !== [] && $note !== null) {
                DB::table('user_qualification_skill')
                    ->where('user_id', $learner->id)
                    ->whereIn('qualification_skill_id', $existing)
                    ->update([
                        'assigned_by' => $by?->id,
                        'assigned_at' => $now,
                        'note'        => $note,
                        'updated_at'  => $now,
                    ]);
            }
        });

        return ['granted' => count($new), 'already_held' => count($existing)];
    }

    /**
     * Grant one qualification to many learners — the bulk action on the
     * learners list (Q-038).
     *
     * @param  list<int>  $learnerIds
     * @return array{granted:int, already_held:int}
     */
    public function grantToMany(QualificationSkill $skill, array $learnerIds, ?Admin $by = null, ?string $note = null): array
    {
        $learnerIds = array_values(array_unique(array_map('intval', $learnerIds)));

        if ($learnerIds === []) {
            return ['granted' => 0, 'already_held' => 0];
        }

        /*
         * Only real learners. Passing an id that does not exist would
         * otherwise fail on the foreign key mid-loop and abort a bulk action
         * that was mostly valid — the caller gets a count back instead, and
         * the FormRequest rejects unknown ids up front.
         */
        $valid = User::query()->whereIn('id', $learnerIds)->pluck('id')->all();

        $existing = DB::table('user_qualification_skill')
            ->where('qualification_skill_id', $skill->id)
            ->whereIn('user_id', $valid)
            ->pluck('user_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        $new = array_values(array_diff($valid, $existing));

        if ($new !== []) {
            $now = now();

            DB::table('user_qualification_skill')->insert(
                array_map(fn (int $userId) => [
                    'user_id'                => $userId,
                    'qualification_skill_id' => $skill->id,
                    'assigned_by'            => $by?->id,
                    'assigned_at'            => $now,
                    'note'                   => $note,
                    'created_at'             => $now,
                    'updated_at'             => $now,
                ], $new),
            );
        }

        return ['granted' => count($new), 'already_held' => count($existing)];
    }

    /**
     * Revoke a direct grant.
     *
     * Only the direct grant is removed. If the learner also earned the
     * qualification by completing the courses that grant it, they still hold
     * it — revoking a manual override cannot retract study that actually
     * happened.
     */
    public function revoke(User $learner, QualificationSkill $skill): bool
    {
        return DB::table('user_qualification_skill')
            ->where('user_id', $learner->id)
            ->where('qualification_skill_id', $skill->id)
            ->delete() > 0;
    }

    /** Direct grants held by one learner, newest first. */
    public function heldBy(User $learner): \Illuminate\Support\Collection
    {
        $locale = app()->getLocale();

        return DB::table('user_qualification_skill as uqs')
            ->join('qualification_skills as qs', 'uqs.qualification_skill_id', '=', 'qs.id')
            ->leftJoin('admins', 'uqs.assigned_by', '=', 'admins.id')
            ->where('uqs.user_id', $learner->id)
            ->orderByDesc('uqs.assigned_at')
            ->get([
                'qs.id',
                'qs.name',
                'uqs.assigned_at',
                'uqs.note',
                'admins.name as assigned_by_name',
            ])
            ->map(function ($row) use ($locale) {
                $name = json_decode((string) $row->name, true);

                return [
                    'id'               => (int) $row->id,
                    'name'             => is_array($name) ? ($name[$locale] ?? reset($name)) : $row->name,
                    'assigned_at'      => $row->assigned_at,
                    'assigned_by_name' => $row->assigned_by_name,
                    'note'             => $row->note,
                ];
            });
    }
}
