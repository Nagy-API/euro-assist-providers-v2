<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Literal substring matching that behaves identically on MySQL/MariaDB and SQLite.
 * Wildcards in user input are escaped with '!' and declared through an explicit ESCAPE clause.
 */
final class Like
{
    public static function pattern(string $term): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
    }

    /**
     * Adds "(col1 LIKE ? OR col2 LIKE ? ...)" for the term to the query (an OR group).
     *
     * @param  Builder<*>  $query
     * @param  list<string>  $columns  qualified or bare column names
     */
    public static function anyOf(Builder $query, array $columns, string $term): void
    {
        $pattern = self::pattern($term);
        $grammar = $query->getQuery()->getGrammar();

        $query->where(function (Builder $q) use ($columns, $pattern, $grammar) {
            foreach ($columns as $column) {
                $q->orWhereRaw($grammar->wrap($column)." like ? escape '!'", [$pattern]);
            }
        });
    }
}
