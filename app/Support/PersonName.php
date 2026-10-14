<?php

namespace App\Support;

use Illuminate\Contracts\Database\Query\Builder;

/**
 * A person's name in the reader's language, for query-builder reads.
 *
 * users carry the legacy HR `name` (usually Arabic) plus `name_en` and
 * `name_ar`; admins carry `name` (the English / main name) and `name_ar`.
 * The SQL here mirrors User::getLocalizedName() and Admin::getLocalizedName()
 * so a raw report row shows the same name an Eloquent resource would, and an
 * ORDER BY on it sorts by what the reader actually sees.
 */
final class PersonName
{
    /** Localized learner/employee name of the `users` row aliased `$alias`. */
    public static function userSql(string $alias = 'users', ?string $locale = null): string
    {
        $a = self::alias($alias);
        [$first, $second] = ($locale ?? app()->getLocale()) === 'ar'
            ? ['name_ar', 'name_en']
            : ['name_en', 'name_ar'];

        return "COALESCE(NULLIF({$a}.{$first}, ''), NULLIF({$a}.{$second}, ''), {$a}.name)";
    }

    /** Localized Dashboard-account name of the `admins` row aliased `$alias`. */
    public static function adminSql(string $alias = 'admins', ?string $locale = null): string
    {
        $a = self::alias($alias);

        return ($locale ?? app()->getLocale()) === 'ar'
            ? "COALESCE(NULLIF({$a}.name_ar, ''), {$a}.name)"
            : "COALESCE(NULLIF({$a}.name, ''), {$a}.name_ar)";
    }

    /**
     * Constrain `$query` to users whose name in any language contains `$term`.
     * Adds one parenthesised WHERE group; `$extra` columns (e.g. machine_code)
     * are matched inside the same group.
     *
     * @param  list<string>  $extra  further columns of `$alias` to match
     */
    public static function whereUserMatches(Builder $query, string $alias, string $term, array $extra = []): Builder
    {
        $like = '%'.addcslashes($term, '%_\\').'%';
        $a = self::alias($alias);

        return $query->where(function ($w) use ($a, $like, $extra) {
            foreach (['name', 'name_en', 'name_ar', ...$extra] as $column) {
                $w->orWhere("{$a}.{$column}", 'like', $like);
            }
        });
    }

    /** Aliases are code-supplied, never user input; refuse anything else. */
    private static function alias(string $alias): string
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) {
            throw new \InvalidArgumentException("Invalid table alias [{$alias}].");
        }

        return $alias;
    }
}
