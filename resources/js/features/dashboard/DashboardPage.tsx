import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';
import { Users, ClipboardList, Ticket as TicketIcon, Wallet } from 'lucide-react';
import { Card, CardHeader, Badge, Skeleton, type Tone } from '@/components/ui';
import { DataTable } from '@/components/DataTable';
import { useAuth } from '@/features/auth/AuthProvider';
import { num, money } from '@/lib/cn';
import { totalOf } from '@/lib/pagination';
import { titleCase, formatGenericValue } from '@/lib/format';
import * as dashboardApi from './api';
import type { Registration, ReportRow } from './types';

const statusTone: Record<string, Tone> = {
    draft: 'neutral',
    pending_payment: 'warning',
    pending_approval: 'warning',
    approved: 'success',
    confirmed: 'success',
    rejected: 'critical',
    cancelled: 'critical',
};

function KpiCard({
    icon: Icon,
    label,
    value,
    isLoading,
}: {
    icon: typeof Users;
    label: string;
    value: number;
    isLoading: boolean;
}) {
    return (
        <Card className="p-5">
            <div className="grid h-11 w-11 place-items-center rounded-xl bg-brand-50 text-accent dark:bg-brand-500/10">
                <Icon size={21} strokeWidth={2.1} />
            </div>
            <div className="mt-4 text-[13px] font-medium text-text-muted">{label}</div>
            {isLoading ? (
                <Skeleton className="mt-2 h-7 w-20" />
            ) : (
                <div className="tnum font-display text-[28px] font-bold leading-none text-text">{num(value)}</div>
            )}
        </Card>
    );
}

function GenericSummary({ data }: { data: ReportRow }) {
    const entries = Object.entries(data);
    if (entries.length === 0) return <p className="px-5 pb-5 text-[13px] text-text-muted">No data yet.</p>;
    return (
        <div className="grid grid-cols-2 gap-3 px-5 pb-5 pt-4 sm:grid-cols-3">
            {entries.map(([key, value]) => (
                <div key={key} className="rounded-xl border border-border bg-surface-2/50 p-3">
                    <div className="text-[11px] uppercase tracking-wide text-text-faint">{titleCase(key)}</div>
                    <div className="tnum mt-1 text-[15px] font-semibold text-text">{formatGenericValue(key, value)}</div>
                </div>
            ))}
        </div>
    );
}

function GenericRowsTable({ rows }: { rows: ReportRow[] }) {
    if (rows.length === 0) {
        return <p className="px-5 pb-5 text-[13px] text-text-muted">No rows for this report yet.</p>;
    }
    const columns = Object.keys(rows[0]);
    return (
        <div className="overflow-x-auto px-2 pb-3">
            <table className="w-full min-w-[420px] text-left text-[13px]">
                <thead>
                    <tr className="border-y border-border text-[11px] uppercase tracking-wide text-text-faint">
                        {columns.map((c) => (
                            <th key={c} className="px-3 py-2.5 font-semibold">{titleCase(c)}</th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row, i) => (
                        <tr key={i} className="border-b border-border last:border-0 hover:bg-table-row-hover">
                            {columns.map((c) => (
                                <td key={c} className="tnum px-3 py-2.5 text-text">{formatGenericValue(c, row[c])}</td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function CapacityByTicketType() {
    const { data, isLoading, isError } = useQuery({
        queryKey: ['ticket-types'],
        queryFn: dashboardApi.fetchTicketTypes,
    });

    return (
        <Card>
            <CardHeader title="Capacity by ticket type" subtitle="Sold vs. total capacity per active tier" />
            <div className="space-y-3 px-5 pb-5 pt-4">
                {isLoading && Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} className="h-10 w-full" />)}
                {isError && <p className="text-[13px] text-critical-fg">Failed to load ticket types.</p>}
                {data?.map((t) => {
                    const total = t.quantity_total ?? t.quantity_sold + t.quantity_available + t.quantity_reserved;
                    const pct = total > 0 ? Math.min(100, Math.round((t.quantity_sold / total) * 100)) : 0;
                    return (
                        <div key={t.ulid}>
                            <div className="flex items-center justify-between text-[13px]">
                                <span className="font-medium text-text">{t.name}</span>
                                <span className="tnum text-text-muted">
                                    {num(t.quantity_sold)} / {total > 0 ? num(total) : '∞'}
                                </span>
                            </div>
                            <div className="mt-1.5 h-2 overflow-hidden rounded-full bg-surface-2">
                                <div
                                    className="h-full rounded-full bg-accent"
                                    style={{ width: `${pct}%`, backgroundColor: t.badge_color ?? undefined }}
                                />
                            </div>
                        </div>
                    );
                })}
                {data && data.length === 0 && <p className="text-[13px] text-text-muted">No ticket types configured yet.</p>}
            </div>
        </Card>
    );
}

const registrationColumns: ColumnDef<Registration, unknown>[] = [
    {
        accessorKey: 'registration_number',
        header: 'Registration',
        cell: (ctx) => <span className="font-medium text-text">{ctx.row.original.registration_number}</span>,
    },
    {
        id: 'attendee',
        header: 'Attendee',
        cell: (ctx) => ctx.row.original.attendee?.full_name ?? '—',
    },
    {
        accessorKey: 'status',
        header: 'Status',
        cell: (ctx) => {
            const status = ctx.row.original.status;
            return <Badge tone={statusTone[status] ?? 'neutral'}>{titleCase(status)}</Badge>;
        },
    },
    {
        accessorKey: 'total_paisa',
        header: 'Total',
        cell: (ctx) => <span className="tnum">{money(ctx.row.original.total_paisa)}</span>,
    },
    {
        accessorKey: 'created_at',
        header: 'Created',
        cell: (ctx) => new Date(ctx.row.original.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }),
    },
];

function RecentRegistrations() {
    const [pageIndex, setPageIndex] = useState(0);
    const pageSize = 8;
    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['recent-registrations', pageIndex],
        queryFn: () => dashboardApi.fetchRegistrations({ per_page: pageSize, page: pageIndex + 1 }),
    });

    return (
        <Card>
            <CardHeader title="Recent registrations" subtitle="Latest submissions across all ticket types" />
            <div className="mt-3">
                <DataTable
                    columns={registrationColumns}
                    data={data?.data ?? []}
                    getRowId={(r) => r.ulid}
                    isLoading={isLoading}
                    isError={isError}
                    onRetry={() => void refetch()}
                    emptyTitle="No registrations yet"
                    emptyDescription="New registrations will appear here as they come in."
                    pageIndex={pageIndex}
                    pageSize={pageSize}
                    totalRows={data ? totalOf(data) : 0}
                    onPageChange={setPageIndex}
                    density="compact"
                />
            </div>
        </Card>
    );
}

export default function DashboardPage() {
    const { session } = useAuth();
    const firstName = session?.name.split(' ')[0] ?? 'there';

    const registrations = useQuery({
        queryKey: ['kpi-registrations'],
        queryFn: () => dashboardApi.fetchRegistrations({ per_page: 1 }),
    });
    const attendees = useQuery({ queryKey: ['kpi-attendees'], queryFn: dashboardApi.fetchAttendeesCount });
    const tickets = useQuery({ queryKey: ['kpi-tickets'], queryFn: dashboardApi.fetchTicketsCount });
    const paymentsSucceeded = useQuery({ queryKey: ['kpi-payments'], queryFn: dashboardApi.fetchPaymentsSucceededCount });

    const revenueSummary = useQuery({
        queryKey: ['report', 'revenue_summary'],
        queryFn: () => dashboardApi.fetchReport('revenue_summary'),
    });
    const salesByType = useQuery({
        queryKey: ['report', 'sales_by_type'],
        queryFn: () => dashboardApi.fetchReport('sales_by_type'),
    });

    return (
        <div className="space-y-6">
            <div>
                <h1 className="text-[26px] font-bold tracking-tight text-text">Welcome back, {firstName}</h1>
                <p className="mt-1 text-[14px] text-text-muted">Here's the current state of the event.</p>
            </div>

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <KpiCard icon={ClipboardList} label="Registrations" value={registrations.data ? totalOf(registrations.data) : 0} isLoading={registrations.isLoading} />
                <KpiCard icon={Users} label="Attendees" value={attendees.data ?? 0} isLoading={attendees.isLoading} />
                <KpiCard icon={TicketIcon} label="Tickets issued" value={tickets.data ?? 0} isLoading={tickets.isLoading} />
                <KpiCard icon={Wallet} label="Payments succeeded" value={paymentsSucceeded.data ?? 0} isLoading={paymentsSucceeded.isLoading} />
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <CapacityByTicketType />
                <Card>
                    <CardHeader title="Revenue summary" subtitle="From the revenue_summary report" />
                    {revenueSummary.isLoading && (
                        <div className="grid grid-cols-2 gap-3 px-5 pb-5 pt-4">
                            {Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-14 w-full" />)}
                        </div>
                    )}
                    {revenueSummary.isError && <p className="px-5 pb-5 text-[13px] text-critical-fg">Failed to load revenue summary.</p>}
                    {revenueSummary.data && (
                        <GenericSummary data={(Array.isArray(revenueSummary.data) ? revenueSummary.data[0] : revenueSummary.data) ?? {}} />
                    )}
                </Card>
            </div>

            <Card>
                <CardHeader title="Sales by ticket type" subtitle="From the sales_by_type report" />
                {salesByType.isLoading && (
                    <div className="space-y-2 px-5 pb-5 pt-4">
                        {Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} className="h-9 w-full" />)}
                    </div>
                )}
                {salesByType.isError && <p className="px-5 pb-5 text-[13px] text-critical-fg">Failed to load sales by type.</p>}
                {salesByType.data && (
                    <GenericRowsTable rows={Array.isArray(salesByType.data) ? salesByType.data : [salesByType.data]} />
                )}
            </Card>

            <RecentRegistrations />
        </div>
    );
}
