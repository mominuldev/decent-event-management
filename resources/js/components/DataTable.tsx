import {
    flexRender,
    getCoreRowModel,
    useReactTable,
    type ColumnDef,
    type OnChangeFn,
    type SortingState,
} from '@tanstack/react-table';
import { ChevronLeft, ChevronRight, ChevronUp, ChevronDown, Inbox } from 'lucide-react';
import { cn } from '@/lib/cn';
import { Skeleton, ErrorState, IconButton } from '@/components/ui';

/**
 * The dashboard's core table primitive (docs/08 §3.2) — server-driven
 * pagination and sorting against endpoints that can hold 20,000+ rows.
 * Client-side sorting of a full table is explicitly out of scope; this
 * component never sorts or paginates locally.
 */
interface DataTableProps<T> {
    columns: ColumnDef<T, unknown>[];
    data: T[];
    getRowId?: (row: T) => string;
    isLoading?: boolean;
    isError?: boolean;
    errorMessage?: string;
    onRetry?: () => void;
    emptyTitle?: string;
    emptyDescription?: string;
    pageIndex: number;
    pageSize: number;
    totalRows: number;
    onPageChange: (pageIndex: number) => void;
    sorting?: SortingState;
    onSortingChange?: OnChangeFn<SortingState>;
    density?: 'comfortable' | 'compact';
}

export function DataTable<T>({
    columns,
    data,
    getRowId,
    isLoading,
    isError,
    errorMessage,
    onRetry,
    emptyTitle = 'No results',
    emptyDescription = 'Nothing matches the current filters.',
    pageIndex,
    pageSize,
    totalRows,
    onPageChange,
    sorting,
    onSortingChange,
    density = 'comfortable',
}: DataTableProps<T>) {
    const pageCount = Math.max(1, Math.ceil(totalRows / pageSize));

    const table = useReactTable({
        data,
        columns,
        state: sorting ? { sorting } : undefined,
        onSortingChange,
        getRowId,
        manualPagination: true,
        manualSorting: true,
        getCoreRowModel: getCoreRowModel(),
    });

    const cellPad = density === 'compact' ? 'px-4 py-2' : 'px-5 py-3';

    if (isError) {
        return <ErrorState message={errorMessage ?? 'Failed to load data.'} onRetry={onRetry} />;
    }

    return (
        <div className="flex flex-col">
            <div className="overflow-x-auto">
                <table className="w-full min-w-[560px] text-left text-[13.5px]">
                    <thead className="sticky top-0 z-10 bg-surface">
                        {table.getHeaderGroups().map((hg) => (
                            <tr key={hg.id} className="border-y border-border text-[11px] uppercase tracking-wide text-text-faint">
                                {hg.headers.map((header) => {
                                    const sortable = header.column.getCanSort();
                                    const sortDir = header.column.getIsSorted();
                                    return (
                                        <th key={header.id} className={cn(cellPad, 'font-semibold first:sticky first:left-0 first:bg-surface')}>
                                            {header.isPlaceholder ? null : (
                                                <button
                                                    type="button"
                                                    disabled={!sortable}
                                                    onClick={header.column.getToggleSortingHandler()}
                                                    className={cn('inline-flex items-center gap-1', sortable && 'cursor-pointer hover:text-text')}
                                                >
                                                    {flexRender(header.column.columnDef.header, header.getContext())}
                                                    {sortable && sortDir === 'asc' && <ChevronUp size={13} />}
                                                    {sortable && sortDir === 'desc' && <ChevronDown size={13} />}
                                                </button>
                                            )}
                                        </th>
                                    );
                                })}
                            </tr>
                        ))}
                    </thead>
                    <tbody>
                        {isLoading &&
                            Array.from({ length: Math.min(pageSize, 6) }).map((_, i) => (
                                <tr key={`skeleton-${i}`} className="border-b border-border last:border-0">
                                    {columns.map((_, ci) => (
                                        <td key={ci} className={cellPad}>
                                            <Skeleton className="h-4 w-full max-w-[160px]" />
                                        </td>
                                    ))}
                                </tr>
                            ))}

                        {!isLoading && data.length === 0 && (
                            <tr>
                                <td colSpan={columns.length} className="px-5 py-16">
                                    <div className="flex flex-col items-center gap-2 text-center">
                                        <Inbox size={28} className="text-text-faint" />
                                        <div className="text-[13.5px] font-semibold text-text">{emptyTitle}</div>
                                        <div className="text-[12.5px] text-text-muted">{emptyDescription}</div>
                                    </div>
                                </td>
                            </tr>
                        )}

                        {!isLoading &&
                            table.getRowModel().rows.map((row) => (
                                <tr key={row.id} className="border-b border-border last:border-0 hover:bg-table-row-hover">
                                    {row.getVisibleCells().map((cell) => (
                                        <td key={cell.id} className={cn(cellPad, 'text-text first:sticky first:left-0 first:bg-inherit')}>
                                            {flexRender(cell.column.columnDef.cell, cell.getContext())}
                                        </td>
                                    ))}
                                </tr>
                            ))}
                    </tbody>
                </table>
            </div>

            {totalRows > 0 && (
                <div className="flex items-center justify-between border-t border-border px-5 py-3 text-[12.5px] text-text-muted">
                    <span>
                        Page {pageIndex + 1} of {pageCount} · {totalRows.toLocaleString('en-US')} total
                    </span>
                    <div className="flex items-center gap-1">
                        <IconButton
                            aria-label="Previous page"
                            disabled={pageIndex <= 0}
                            onClick={() => onPageChange(pageIndex - 1)}
                        >
                            <ChevronLeft size={16} />
                        </IconButton>
                        <IconButton
                            aria-label="Next page"
                            disabled={pageIndex + 1 >= pageCount}
                            onClick={() => onPageChange(pageIndex + 1)}
                        >
                            <ChevronRight size={16} />
                        </IconButton>
                    </div>
                </div>
            )}
        </div>
    );
}
