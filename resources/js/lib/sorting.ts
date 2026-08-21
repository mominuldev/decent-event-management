import { useCallback, useState } from 'react';
import type { OnChangeFn, SortingState } from '@tanstack/react-table';

/**
 * Server-side sorting for the admin data tables.
 *
 * The tables target 20,000+ rows, so sorting is the server's job for the same
 * reason pagination is — the client only ever holds one page, and sorting that
 * page would reorder twenty rows out of twenty thousand while looking like it
 * worked. TanStack Table runs with `manualSorting`, and this module is the
 * translation layer between its SortingState and the API's `sort`/`direction`
 * query pair.
 *
 * A column's TanStack id must equal the API's field name, since that id is
 * what gets sent. The server validates it against its own allowlist and falls
 * back to the default for anything else, so a stale column id degrades to the
 * default order rather than an error page.
 */
export interface SortParams {
    sort?: string;
    direction?: 'asc' | 'desc';
}

export function toSortParams(sorting: SortingState): SortParams {
    const primary = sorting[0];

    if (!primary) return {};

    return { sort: primary.id, direction: primary.desc ? 'desc' : 'asc' };
}

/** Newest first — every admin list opens on the most recent rows. */
export const LATEST_FIRST: SortingState = [{ id: 'created_at', desc: true }];

/**
 * Table sorting state plus the query params for it.
 *
 * `onChange` fires whenever the sort changes and exists to reset pagination:
 * re-sorting while on page 4 leaves the operator looking at rows 61–80 of a
 * completely different ordering, which reads as data appearing at random.
 *
 * An empty SortingState is coerced back to the default. TanStack's third click
 * clears the sort, and an unsorted request would fall back to the server's
 * default order anyway — but with no header showing it, so the table would be
 * ordered by something the UI was no longer admitting to.
 */
export function useTableSorting(defaultSorting: SortingState = LATEST_FIRST, onChange?: () => void) {
    const [sorting, setSortingState] = useState<SortingState>(defaultSorting);

    const setSorting = useCallback<OnChangeFn<SortingState>>(
        (updater) => {
            setSortingState((prev) => {
                const next = typeof updater === 'function' ? updater(prev) : updater;
                return next.length > 0 ? next : defaultSorting;
            });
            onChange?.();
        },
        [defaultSorting, onChange],
    );

    return { sorting, setSorting, sortParams: toSortParams(sorting) };
}
