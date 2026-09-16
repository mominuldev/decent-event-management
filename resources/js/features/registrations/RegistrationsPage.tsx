import { useCallback, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';
import { Plus, Search } from 'lucide-react';
import { Badge, Button, Card, CardHeader, Input, Label, Select } from '@/components/ui';
import { DataTable } from '@/components/DataTable';
import { useAuth } from '@/features/auth/AuthProvider';
import { money } from '@/lib/cn';
import { totalOf } from '@/lib/pagination';
import { shortDate } from '@/lib/format';
import { useTableSorting } from '@/lib/sorting';
import * as registrationsApi from './api';
import { statusTone, titleCase } from './RegistrationParts';
import type { Registration, RegistrationStatus } from './types';

/** Column ids double as the API's `sort` field names — see lib/sorting.ts. */
const columns: ColumnDef<Registration, unknown>[] = [
    {
        accessorKey: 'registration_number',
        header: 'Registration',
        cell: (ctx) => <span className="font-medium text-text">{ctx.row.original.registration_number}</span>,
    },
    {
        // Both of these live behind a relation, so `registrations` cannot be
        // ordered by them without a join this list will not take on at scale.
        id: 'attendee',
        header: 'Attendee',
        enableSorting: false,
        cell: (ctx) => ctx.row.original.attendee?.full_name ?? '—',
    },
    {
        id: 'ticket_type',
        header: 'Ticket type',
        enableSorting: false,
        cell: (ctx) => ctx.row.original.ticket_type?.name ?? '—',
    },
    {
        accessorKey: 'status',
        header: 'Status',
        cell: (ctx) => <Badge tone={statusTone[ctx.row.original.status]}>{titleCase(ctx.row.original.status)}</Badge>,
    },
    {
        accessorKey: 'total_paisa',
        header: 'Total',
        sortDescFirst: true,
        cell: (ctx) => <span className="tnum">{money(ctx.row.original.total_paisa)}</span>,
    },
    {
        accessorKey: 'created_at',
        header: 'Created',
        sortDescFirst: true,
        cell: (ctx) => <span className="tnum text-text-muted">{shortDate(ctx.row.original.created_at)}</span>,
    },
];

export default function RegistrationsPage() {
    const { can } = useAuth();
    const navigate = useNavigate();
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState<RegistrationStatus | ''>('');
    const [dateFrom, setDateFrom] = useState('');
    const [dateTo, setDateTo] = useState('');
    const [pageIndex, setPageIndex] = useState(0);
    const pageSize = 20;

    const resetPage = useCallback(() => setPageIndex(0), []);
    const { sorting, setSorting, sortParams } = useTableSorting(undefined, resetPage);

    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['registrations', search, status, dateFrom, dateTo, sortParams, pageIndex],
        queryFn: () =>
            registrationsApi.fetchRegistrations({
                search,
                status,
                date_from: dateFrom,
                date_to: dateTo,
                ...sortParams,
                page: pageIndex + 1,
                per_page: pageSize,
            }),
    });

    return (
        <div className="space-y-6">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 className="text-[26px] font-bold tracking-tight text-text">Registrations</h1>
                    <p className="mt-1 text-[14px] text-text-muted">Track and manage every registration through its lifecycle.</p>
                </div>
                {can('registration.create') && (
                    <Button onClick={() => navigate('/registrations/new')}>
                        <Plus size={15} /> New registration
                    </Button>
                )}
            </div>

            <Card>
                <CardHeader title="All registrations" />
                <div className="flex flex-wrap items-end gap-3 px-5 pb-4 pt-4">
                    <div className="min-w-[200px] flex-1">
                        <Label htmlFor="search">Search</Label>
                        <div className="relative">
                            <Search size={15} className="absolute left-3 top-1/2 -translate-y-1/2 text-text-faint" />
                            <Input
                                id="search"
                                className="pl-9"
                                placeholder="Registration # or attendee"
                                value={search}
                                onChange={(e) => { setSearch(e.target.value); setPageIndex(0); }}
                            />
                        </div>
                    </div>
                    <div className="w-44">
                        <Label htmlFor="status_filter">Status</Label>
                        <Select id="status_filter" value={status} onChange={(e) => { setStatus(e.target.value as RegistrationStatus | ''); setPageIndex(0); }}>
                            <option value="">All statuses</option>
                            {Object.keys(statusTone).map((s) => (
                                <option key={s} value={s}>{titleCase(s)}</option>
                            ))}
                        </Select>
                    </div>
                    <div className="w-40">
                        <Label htmlFor="date_from">From</Label>
                        <Input id="date_from" type="date" value={dateFrom} onChange={(e) => { setDateFrom(e.target.value); setPageIndex(0); }} />
                    </div>
                    <div className="w-40">
                        <Label htmlFor="date_to">To</Label>
                        <Input id="date_to" type="date" value={dateTo} onChange={(e) => { setDateTo(e.target.value); setPageIndex(0); }} />
                    </div>
                </div>

                <DataTable
                    columns={columns}
                    data={data?.data ?? []}
                    getRowId={(r) => r.ulid}
                    isLoading={isLoading}
                    isError={isError}
                    onRetry={() => void refetch()}
                    onRowClick={(row) => navigate(`/registrations/${row.ulid}`)}
                    emptyTitle="No registrations found"
                    emptyDescription="Try adjusting your search or filters."
                    pageIndex={pageIndex}
                    pageSize={pageSize}
                    totalRows={data ? totalOf(data) : 0}
                    onPageChange={setPageIndex}
                    sorting={sorting}
                    onSortingChange={setSorting}
                />
            </Card>
        </div>
    );
}
