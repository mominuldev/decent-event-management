<?php

namespace App\Domain\Shared\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The one place a client-supplied sort reaches an ORDER BY, for every admin
 * list endpoint.
 *
 * Two things it exists to guarantee:
 *
 *  - **A column name never travels from the request into SQL.** The caller
 *    passes an allowlist mapping a public field name to a real column; the
 *    request only ever selects a key from it. `orderBy()` does not bind its
 *    column argument, so a raw `$request->input('sort')` there is a straight
 *    SQL injection — this class makes that impossible by construction rather
 *    than by remembering to sanitise at four call sites.
 *  - **Every sort is a total order.** MySQL is free to answer successive
 *    LIMIT/OFFSET pages inconsistently when the ORDER BY has ties, so the same
 *    row can appear on page 2 and page 3 while another never appears at all.
 *    Sorting by `status` — hundreds of ties — makes that near-certain, so the
 *    primary key is always appended as the final tiebreaker.
 *
 * An unrecognised field or direction falls back to the default rather than
 * raising a 422. These are read-only list endpoints reached by bookmarked and
 * hand-edited URLs; answering the default page beats failing one, and the
 * export endpoint normalises through this same class, so the file can never
 * come back in a different order from the screen it was launched from.
 */
final class ListSort
{
    /**
     * Applies the requested sort, falling back to the default for anything
     * not on the allowlist.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, mixed>  $input  the request's own input, unfiltered
     * @param  array<string, string>  $sortable  public field name => real column name
     * @param  string  $default  key of $sortable to use when none is requested
     * @param  'asc'|'desc'  $defaultDirection
     * @return Builder<TModel>
     */
    public static function apply(
        Builder $query,
        array $input,
        array $sortable,
        string $default,
        string $defaultDirection = 'desc',
    ): Builder {
        $field = self::field($input, $sortable, $default);
        $direction = self::direction($input, $defaultDirection);

        // Qualified with the table so a caller that later joins does not turn
        // this into an ambiguous-column error. Safe to interpolate: both
        // halves come from the allowlist and the model, never the request.
        $table = $query->getModel()->getTable();
        $column = $sortable[$field];

        $query->orderBy("{$table}.{$column}", $direction);

        if ($column !== $query->getModel()->getKeyName()) {
            // Same direction as the primary sort, so "latest first" stays
            // latest-first all the way down to rows sharing a timestamp.
            $query->orderBy("{$table}.".$query->getModel()->getKeyName(), $direction);
        }

        return $query;
    }

    /**
     * The requested field if the allowlist has it, the default otherwise.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $sortable
     */
    private static function field(array $input, array $sortable, string $default): string
    {
        $requested = $input['sort'] ?? null;

        if (is_string($requested) && array_key_exists($requested, $sortable)) {
            return $requested;
        }

        return $default;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return 'asc'|'desc'
     */
    private static function direction(array $input, string $default): string
    {
        $requested = $input['direction'] ?? null;

        if (is_string($requested) && in_array(strtolower($requested), ['asc', 'desc'], true)) {
            /** @var 'asc'|'desc' */
            return strtolower($requested);
        }

        return $default === 'asc' ? 'asc' : 'desc';
    }
}
