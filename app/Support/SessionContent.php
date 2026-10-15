<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * What a cohort session covers (D-079, D-109): modules, quizzes and
 * assignments, in the order the schedule sheet lists them.
 *
 * In the sheet's "content" column and in this class a module is its ID
 * (`12`), a quiz `Q<id>` and an assignment `A<id>`. The three link tables
 * share one `position` sequence per session.
 *
 *   int          -> course_session_lectures   (module)
 *   'Q<id>'      -> course_session_exams      (quiz)
 *   'A<id>'      -> course_session_assignments (assignment)
 */
final class SessionContent
{
    /** kind => [link table, foreign key] */
    public const TABLES = [
        'lecture' => ['course_session_lectures', 'lecture_id'],
        'quiz' => ['course_session_exams', 'exam_id'],
        'assignment' => ['course_session_assignments', 'assignment_id'],
    ];

    private const PREFIX = ['quiz' => 'Q', 'assignment' => 'A'];

    /** The sheet token of an item: 12, 'Q5', 'A3'. */
    public static function token(string $kind, int $id): int|string
    {
        return $kind === 'lecture' ? $id : self::PREFIX[$kind].$id;
    }

    /**
     * One sheet token to [kind, id]; null when it is not one. Accepts
     * lower-case prefixes.
     *
     * @return array{0: string, 1: int}|null
     */
    public static function parse(string $token): ?array
    {
        if (! preg_match('/^([QqAa]?)(\d{1,9})$/', $token, $m) || (int) $m[2] < 1) {
            return null;
        }

        $kind = match (strtoupper($m[1])) {
            'Q' => 'quiz',
            'A' => 'assignment',
            default => 'lecture',
        };

        return [$kind, (int) $m[2]];
    }

    /**
     * The items each session covers, in sheet order, as tokens.
     *
     * @param  list<int>  $sessionIds
     * @return array<int, list<int|string>>
     */
    public static function tokensBySession(array $sessionIds): array
    {
        $out = [];
        foreach (self::itemsBySession($sessionIds) as $sessionId => $items) {
            $out[$sessionId] = array_map(fn (array $i) => self::token($i['kind'], $i['id']), $items);
        }

        return $out;
    }

    /**
     * @param  list<int>  $sessionIds
     * @return array<int, list<array{kind: string, id: int, position: int}>>
     */
    public static function itemsBySession(array $sessionIds): array
    {
        if ($sessionIds === []) {
            return [];
        }

        $out = [];
        foreach (self::TABLES as $kind => [$table, $key]) {
            foreach (DB::table($table)->whereIn('session_id', $sessionIds)->get(['session_id', $key, 'position']) as $row) {
                $out[(int) $row->session_id][] = ['kind' => $kind, 'id' => (int) $row->{$key}, 'position' => (int) $row->position];
            }
        }
        foreach ($out as &$items) {
            usort($items, fn ($a, $b) => $a['position'] <=> $b['position']);
        }

        return $out;
    }

    /**
     * Link a session to its content, in order. Call inside the import's
     * transaction.
     *
     * @param  list<int|string>  $tokens
     */
    public static function link(int $sessionId, array $tokens): void
    {
        $rows = [];
        foreach (array_values($tokens) as $i => $token) {
            [$kind, $id] = self::parse((string) $token) ?? [null, 0];
            if ($kind === null) {
                continue;
            }
            [$table, $key] = self::TABLES[$kind];
            $rows[$table][] = ['session_id' => $sessionId, $key => $id, 'position' => $i + 1];
        }
        foreach ($rows as $table => $insert) {
            DB::table($table)->insert($insert);
        }
    }

    public static function unlink(int $sessionId): void
    {
        foreach (self::TABLES as [$table]) {
            DB::table($table)->where('session_id', $sessionId)->delete();
        }
    }
}
