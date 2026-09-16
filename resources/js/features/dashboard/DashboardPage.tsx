import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';
import {
    ArrowRight,
    ArrowUpRight,
    CalendarDays,
    ClipboardList,
    DoorOpen,
    Globe,
    Plus,
    ScanLine,
    Store,
    Ticket as TicketIcon,
    TrendingUp,
    Users,
    Wallet,
} from 'lucide-react';
import { Card, CardHeader, Badge, Skeleton, type Tone } from '@/components/ui';
import { DataTable } from '@/components/DataTable';
import { useAuth } from '@/features/auth/AuthProvider';
import { fetchDailySales } from '@/features/reports/api';
import { fetchLiveDashboard } from '@/features/checkin/api';
import { fetchSettings } from '@/features/settings/api';
import type { CheckIn } from '@/features/checkin/types';
import { cn, num, money } from '@/lib/cn';
import { totalOf } from '@/lib/pagination';
import { titleCase, shortDate } from '@/lib/format';
import * as dashboardApi from './api';
import { CapacityRing, PaymentMethodsChart, SalesTrendChart, Sparkline } from './charts';
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

const resultTone: Partial<Record<CheckIn['result'], Tone>> = {
    admitted: 'success',
    manual_override: 'info',
    duplicate: 'warning',
};

function greeting(): string {
    const h = new Date().getHours();
    if (h < 12) return 'Good morning';
    if (h < 17) return 'Good afternoon';
    return 'Good evening';
}

function relativeTime(iso: string): string {
    const diff = Math.max(0, Date.now() - new Date(iso).getTime());
    const m = Math.floor(diff / 60_000);
    if (m < 1) return 'just now';
    if (m < 60) return `${m}m ago`;
    const h = Math.floor(m / 60);
    if (h < 24) return `${h}h ago`;
    return shortDate(iso);
}

/* ---- Header -------------------------------------------------------------- */
function EventChip() {
    const { can } = useAuth();
    const settings = useQuery({
        queryKey: ['settings'],
        queryFn: fetchSettings,
        enabled: can('settings.view'),
        staleTime: 5 * 60_000,
    });

    const event = useMemo(() => {
        const raw = settings.data?.event?.find((s) => s.key === 'event.date')?.typed_value;
        if (typeof raw !== 'string') return null;
        const date = new Date(raw);
        if (Number.isNaN(date.getTime())) return null;
        const days = Math.ceil((date.getTime() - Date.now()) / 86_400_000);
        return { date, days };
    }, [settings.data]);

    if (!event) return null;

    const when = event.date.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
    const label = event.days > 1 ? `${num(event.days)} days to go` : event.days === 1 ? 'Tomorrow' : event.days === 0 ? 'Today' : 'Event has passed';

    return (
        <span className="inline-flex items-center gap-2 rounded-full border border-secondary-200 bg-secondary-50 px-3 py-1 text-[12.5px] font-medium text-secondary-800 dark:border-secondary-500/25 dark:bg-secondary-500/10 dark:text-secondary-300">
            <CalendarDays size={14} />
            <span className="tnum">{label}</span>
            <span className="text-secondary-800/60 dark:text-secondary-300/60">· {when}</span>
        </span>
    );
}

function QuickAction({ to, icon: Icon, children, primary }: { to: string; icon: typeof Plus; children: string; primary?: boolean }) {
    return (
        <Link
            to={to}
            className={cn(
                'inline-flex items-center gap-2 rounded-xl px-3.5 py-2 text-[13px] font-semibold transition-all',
                primary
                    ? 'bg-accent text-accent-fg shadow-[0_6px_18px_-6px_var(--color-brand-600)] hover:-translate-y-px hover:opacity-95'
                    : 'border border-border bg-surface text-text hover:border-border-strong hover:bg-surface-2',
            )}
        >
            <Icon size={15} strokeWidth={2.2} />
            {children}
        </Link>
    );
}

/* ---- KPI card ------------------------------------------------------------ */
function KpiCard({
    icon: Icon,
    label,
    value,
    sub,
    isLoading,
    spark,
    to,
    emphasis,
}: {
    icon: typeof Users;
    label: string;
    value: string;
    sub?: string;
    isLoading: boolean;
    spark?: number[];
    to: string;
    emphasis?: boolean;
}) {
    return (
        <Link
            to={to}
            className={cn(
                'group relative overflow-hidden rounded-2xl border p-5 transition-all hover:-translate-y-0.5',
                emphasis
                    ? 'border-transparent bg-[linear-gradient(135deg,var(--color-brand-600),var(--color-brand-800))] text-white shadow-[0_18px_40px_-18px_var(--color-brand-700)]'
                    : 'border-border bg-surface shadow-[var(--shadow-card)] hover:shadow-[var(--shadow-pop)]',
            )}
        >
            {emphasis && (
                <span
                    aria-hidden
                    className="pointer-events-none absolute -right-10 -top-14 h-40 w-40 rounded-full bg-secondary-500/30 blur-3xl"
                />
            )}
            <div className="relative flex items-start justify-between">
                <div
                    className={cn(
                        'grid h-11 w-11 place-items-center rounded-xl',
                        emphasis ? 'bg-white/15 text-white' : 'bg-brand-50 text-accent dark:bg-brand-500/10',
                    )}
                >
                    <Icon size={21} strokeWidth={2.1} />
                </div>
                <ArrowUpRight
                    size={16}
                    className={cn(
                        'translate-y-0.5 opacity-0 transition-all group-hover:translate-y-0 group-hover:opacity-100',
                        emphasis ? 'text-white/70' : 'text-text-faint',
                    )}
                />
            </div>
            <div className={cn('relative mt-4 text-[13px] font-medium', emphasis ? 'text-white/75' : 'text-text-muted')}>{label}</div>
            {isLoading ? (
                <Skeleton className={cn('mt-2 h-8 w-24', emphasis && 'bg-white/20')} />
            ) : (
                <div className={cn('tnum relative font-display text-[30px] font-bold leading-none tracking-tight', emphasis ? 'text-white' : 'text-text')}>
                    {value}
                </div>
            )}
            <div className="relative mt-3 flex items-end justify-between gap-3">
                <div className={cn('min-w-0 flex-1 text-[12px] leading-snug', emphasis ? 'text-white/65' : 'text-text-faint')}>{sub ?? '\u00a0'}</div>
                {spark && spark.length > 1 && (
                    <div className="w-20 shrink-0">
                        <Sparkline values={spark} color={emphasis ? 'rgba(255,255,255,0.9)' : 'var(--color-brand-500)'} />
                    </div>
                )}
            </div>
        </Link>
    );
}

/* ---- Sales trend --------------------------------------------------------- */
function StatTile({ label, value, icon, color }: { label: string; value: string; icon?: React.ReactNode; color?: string }) {
    return (
        <div className="rounded-xl border border-border bg-surface-2/50 px-3.5 py-3">
            <div className="flex items-center gap-1.5 text-[11px] font-medium uppercase tracking-wide text-text-faint">
                {color && <span className="h-2 w-2 rounded-full" style={{ background: color }} />}
                {icon}
                {label}
            </div>
            <div className="tnum mt-1 text-[17px] font-semibold text-text">{value}</div>
        </div>
    );
}

function SalesTrendCard() {
    const { data, isLoading, isError } = useQuery({
        queryKey: ['dashboard', 'daily-sales'],
        queryFn: () => fetchDailySales({}),
        staleTime: 60_000,
    });

    return (
        <Card className="flex h-full flex-col overflow-hidden">
            <CardHeader
                title="Sales, last 30 days"
                subtitle="Online checkout against counter cash, by the day the money arrived"
                action={
                    <Link to="/reports" className="inline-flex shrink-0 items-center gap-1 whitespace-nowrap text-[12.5px] font-semibold text-accent hover:underline">
                        Daily takings <ArrowRight size={13} />
                    </Link>
                }
            />
            {isLoading && (
                <div className="space-y-4 p-5">
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        {Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-16 w-full" />)}
                    </div>
                    <Skeleton className="h-[260px] w-full" />
                </div>
            )}
            {isError && <p className="px-5 pb-5 pt-4 text-[13px] text-critical-fg">Failed to load the sales trend.</p>}
            {data && (
                <div className="flex flex-1 flex-col p-5 pt-4">
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <StatTile label="Takings" value={money(data.totals.total_paisa)} icon={<TrendingUp size={12} />} />
                        <StatTile label="Online" value={money(data.totals.online_paisa)} color="var(--color-brand-500)" />
                        <StatTile label="Counter" value={money(data.totals.offline_paisa)} color="var(--color-secondary-500)" />
                        <StatTile label="Net of refunds" value={money(data.totals.net_paisa)} />
                    </div>
                    <div className="mt-4 -ml-2 min-h-[260px] flex-1">
                        <SalesTrendChart days={data.days} />
                    </div>
                </div>
            )}
        </Card>
    );
}

/* ---- Capacity ------------------------------------------------------------ */
function CapacityCard() {
    const { data, isLoading, isError } = useQuery({
        queryKey: ['ticket-types'],
        queryFn: dashboardApi.fetchTicketTypes,
    });

    const active = useMemo(() => (data ?? []).filter((t) => t.is_active), [data]);
    const overall = useMemo(() => {
        const sold = active.reduce((n, t) => n + t.quantity_sold, 0);
        const reserved = active.reduce((n, t) => n + t.quantity_reserved, 0);
        const total = active.reduce((n, t) => n + (t.quantity_total ?? t.quantity_sold + t.quantity_available + t.quantity_reserved), 0);
        return { sold, reserved, total };
    }, [active]);

    return (
        <Card className="flex h-full flex-col">
            <CardHeader
                title="Capacity"
                subtitle="Sold and held seats across active tiers"
                action={
                    <Link to="/tickets" className="inline-flex shrink-0 items-center gap-1 whitespace-nowrap text-[12.5px] font-semibold text-accent hover:underline">
                        Tickets <ArrowRight size={13} />
                    </Link>
                }
            />
            <div className="flex flex-1 flex-col px-5 pb-5 pt-4">
                {isLoading && (
                    <div className="space-y-3">
                        <Skeleton className="mx-auto h-[150px] w-[150px] rounded-full" />
                        {Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} className="h-10 w-full" />)}
                    </div>
                )}
                {isError && <p className="text-[13px] text-critical-fg">Failed to load ticket types.</p>}
                {data && active.length > 0 && (
                    <>
                        <div className="flex items-center gap-5">
                            <CapacityRing sold={overall.sold} reserved={overall.reserved} total={overall.total} />
                            <dl className="grid flex-1 gap-2.5 text-[13px]">
                                <div className="flex items-center justify-between">
                                    <dt className="flex items-center gap-2 text-text-muted"><span className="h-2 w-2 rounded-full bg-brand-500" />Sold</dt>
                                    <dd className="tnum font-semibold text-text">{num(overall.sold)}</dd>
                                </div>
                                <div className="flex items-center justify-between">
                                    <dt className="flex items-center gap-2 text-text-muted"><span className="h-2 w-2 rounded-full bg-secondary-500" />Held</dt>
                                    <dd className="tnum font-semibold text-text">{num(overall.reserved)}</dd>
                                </div>
                                <div className="flex items-center justify-between">
                                    <dt className="flex items-center gap-2 text-text-muted"><span className="h-2 w-2 rounded-full bg-surface-3 ring-1 ring-border" />Remaining</dt>
                                    <dd className="tnum font-semibold text-text">{num(Math.max(0, overall.total - overall.sold - overall.reserved))}</dd>
                                </div>
                            </dl>
                        </div>
                        <div className="mt-5 space-y-3.5 border-t border-border pt-4">
                            {active.map((t) => {
                                const total = t.quantity_total ?? t.quantity_sold + t.quantity_available + t.quantity_reserved;
                                const pct = total > 0 ? Math.min(100, Math.round((t.quantity_sold / total) * 100)) : 0;
                                return (
                                    <div key={t.ulid}>
                                        <div className="flex items-center justify-between text-[13px]">
                                            <span className="font-medium text-text">{t.name}</span>
                                            <span className="tnum text-text-muted">
                                                {num(t.quantity_sold)} <span className="text-text-faint">/ {total > 0 ? num(total) : '∞'}</span>
                                            </span>
                                        </div>
                                        <div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-surface-2">
                                            <div
                                                className="h-full rounded-full bg-accent transition-[width] duration-500"
                                                style={{ width: `${pct}%`, backgroundColor: t.badge_color ?? undefined }}
                                            />
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </>
                )}
                {data && active.length === 0 && <p className="text-[13px] text-text-muted">No active ticket types yet.</p>}
            </div>
        </Card>
    );
}

/* ---- Payment methods ----------------------------------------------------- */
function PaymentMethodsCard() {
    const { data, isLoading } = useQuery({
        queryKey: ['dashboard', 'daily-sales'],
        queryFn: () => fetchDailySales({}),
        staleTime: 60_000,
    });

    return (
        <Card>
            <CardHeader title="Payment methods" subtitle="Where the last 30 days' money came through" />
            <div className="px-3 pb-4 pt-3">
                {isLoading && <Skeleton className="mx-2 h-28 w-auto" />}
                {data && data.methods.length === 0 && <p className="px-2 text-[13px] text-text-muted">No payments in the window yet.</p>}
                {data && data.methods.length > 0 && <PaymentMethodsChart methods={data.methods} />}
                {data && data.methods.length > 0 && (
                    <div className="mt-2 flex items-center gap-4 px-2 text-[11.5px] text-text-faint">
                        <span className="flex items-center gap-1.5"><Globe size={12} className="text-brand-500" /> Online</span>
                        <span className="flex items-center gap-1.5"><Store size={12} className="text-secondary-500" /> Counter</span>
                    </div>
                )}
            </div>
        </Card>
    );
}

/* ---- Gate activity ------------------------------------------------------- */
function GateActivityCard() {
    const { data, isLoading, isError } = useQuery({
        queryKey: ['dashboard', 'live-dashboard'],
        queryFn: fetchLiveDashboard,
        refetchInterval: 30_000,
    });

    const admitted = data?.gates.reduce((n, g) => n + g.admitted_count, 0) ?? 0;

    return (
        <Card>
            <CardHeader
                title="At the gates"
                subtitle={data ? `${num(admitted)} admitted across ${data.gates.length} active gate${data.gates.length === 1 ? '' : 's'}` : 'Live admissions'}
                action={
                    <Link to="/check-in" className="inline-flex shrink-0 items-center gap-1 whitespace-nowrap text-[12.5px] font-semibold text-accent hover:underline">
                        Live view <ArrowRight size={13} />
                    </Link>
                }
            />
            <div className="px-5 pb-4 pt-3">
                {isLoading && <div className="space-y-2">{Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-9 w-full" />)}</div>}
                {isError && <p className="text-[13px] text-critical-fg">Failed to load gate activity.</p>}
                {data && data.recent_check_ins.length === 0 && (
                    <div className="flex items-center gap-3 rounded-xl border border-dashed border-border px-3 py-3 text-[13px] text-text-muted">
                        <DoorOpen size={16} className="text-text-faint" />
                        No scans yet — activity appears here as devices sync.
                    </div>
                )}
                {data && data.recent_check_ins.length > 0 && (
                    <ul className="divide-y divide-border">
                        {data.recent_check_ins.slice(0, 6).map((c) => (
                            <li key={c.ulid} className="flex items-center justify-between gap-3 py-2">
                                <div className="min-w-0">
                                    <div className="truncate text-[13px] font-medium text-text">{c.ticket?.ticket_number ?? '—'}</div>
                                    <div className="truncate text-[11.5px] text-text-faint">
                                        {c.gate?.name ?? 'Unknown gate'} · {relativeTime(c.scanned_at)}
                                    </div>
                                </div>
                                <Badge size="sm" tone={resultTone[c.result] ?? 'critical'}>{titleCase(c.result)}</Badge>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </Card>
    );
}

/* ---- Recent registrations ------------------------------------------------ */
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
        cell: (ctx) => shortDate(ctx.row.original.created_at),
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
        <Card className="overflow-hidden">
            <CardHeader
                title="Recent registrations"
                subtitle="Latest submissions across all ticket types"
                action={
                    <Link to="/registrations" className="inline-flex shrink-0 items-center gap-1 whitespace-nowrap text-[12.5px] font-semibold text-accent hover:underline">
                        All registrations <ArrowRight size={13} />
                    </Link>
                }
            />
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

/* ---- Page ---------------------------------------------------------------- */
export default function DashboardPage() {
    const { session, can } = useAuth();
    const firstName = session?.name.split(' ')[0] ?? 'there';

    const canRegistrations = can('registration.view_any');
    const canAttendees = can('attendee.view_any');
    const canTickets = can('ticket.view_any');
    const canTicketTypes = can('ticket_type.view_any');
    const canRevenue = can('report.view_revenue');
    const canPayments = can('payment.view_any');
    const canGates = can('checkin.view_live_dashboard');

    const registrations = useQuery({ queryKey: ['kpi-registrations'], queryFn: () => dashboardApi.fetchRegistrationsCount(), enabled: canRegistrations });
    const awaiting = useQuery({ queryKey: ['kpi-registrations', 'pending_payment'], queryFn: () => dashboardApi.fetchRegistrationsCount('pending_payment'), enabled: canRegistrations });
    const attendees = useQuery({ queryKey: ['kpi-attendees'], queryFn: dashboardApi.fetchAttendeesCount, enabled: canAttendees });
    const tickets = useQuery({ queryKey: ['kpi-tickets'], queryFn: dashboardApi.fetchTicketsCount, enabled: canTickets });
    const paymentsSucceeded = useQuery({ queryKey: ['kpi-payments'], queryFn: dashboardApi.fetchPaymentsSucceededCount, enabled: canPayments });
    const revenue = useQuery({
        queryKey: ['report', 'revenue_summary'],
        queryFn: () => dashboardApi.fetchReport('revenue_summary'),
        enabled: canRevenue,
    });
    const dailySales = useQuery({
        queryKey: ['dashboard', 'daily-sales'],
        queryFn: () => fetchDailySales({}),
        enabled: canRevenue,
        staleTime: 60_000,
    });

    const revenueRow = (Array.isArray(revenue.data) ? revenue.data[0] : revenue.data) as ReportRow | undefined;
    const totalRevenue = typeof revenueRow?.total_revenue_paisa === 'number' ? revenueRow.total_revenue_paisa : Number(revenueRow?.total_revenue_paisa ?? 0);
    const totalRefunded = typeof revenueRow?.total_refunded_paisa === 'number' ? revenueRow.total_refunded_paisa : Number(revenueRow?.total_refunded_paisa ?? 0);
    const revenueSpark = useMemo(() => (dailySales.data ? [...dailySales.data.days].reverse().map((d) => d.total_paisa / 100) : undefined), [dailySales.data]);
    const paymentsSpark = useMemo(() => (dailySales.data ? [...dailySales.data.days].reverse().map((d) => d.total_payments) : undefined), [dailySales.data]);
    const todaysTakings = dailySales.data?.days[0];

    const today = new Date().toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' });
    const nothingToShow = !canRegistrations && !canAttendees && !canTickets && !canTicketTypes && !canRevenue && !canPayments && !canGates;

    return (
        <div className="space-y-6">
            {/* Header */}
            <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <div className="flex flex-wrap items-center gap-3">
                        <p className="text-[13px] font-medium text-text-muted">{today}</p>
                        <EventChip />
                    </div>
                    <h1 className="mt-1.5 font-display text-[28px] font-bold tracking-tight text-text">
                        {greeting()}, {firstName}
                    </h1>
                    <p className="mt-1 text-[14px] text-text-muted">
                        {todaysTakings && todaysTakings.total_payments > 0
                            ? <>
                                  <span className="tnum font-semibold text-text">{money(todaysTakings.total_paisa)}</span> taken today across{' '}
                                  <span className="tnum font-semibold text-text">{num(todaysTakings.total_payments)}</span> payment{todaysTakings.total_payments === 1 ? '' : 's'}.
                              </>
                            : "Here's where the event stands right now."}
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    {can('registration.create') && <QuickAction to="/registrations" icon={Plus} primary>New registration</QuickAction>}
                    {canGates && <QuickAction to="/check-in" icon={ScanLine}>Live check-in</QuickAction>}
                    {canRevenue && <QuickAction to="/reports" icon={Wallet}>Daily takings</QuickAction>}
                </div>
            </div>

            {nothingToShow && (
                <Card className="p-8 text-center">
                    <p className="text-[15px] font-semibold text-text">Nothing to show here yet</p>
                    <p className="mt-1 text-[13px] text-text-muted">Your role doesn't include any of the dashboard's reports. Use the navigation on the left.</p>
                </Card>
            )}

            {/* KPIs */}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {canRevenue && (
                    <KpiCard
                        emphasis
                        icon={Wallet}
                        label="Revenue"
                        to="/finance"
                        value={money(totalRevenue)}
                        sub={totalRefunded > 0 ? `${money(totalRefunded)} refunded` : 'All succeeded payments'}
                        isLoading={revenue.isLoading}
                        spark={revenueSpark}
                    />
                )}
                {canRegistrations && (
                    <KpiCard
                        icon={ClipboardList}
                        label="Registrations"
                        to="/registrations"
                        value={num(registrations.data ?? 0)}
                        sub={awaiting.data ? `${num(awaiting.data)} awaiting payment` : 'All paid up'}
                        isLoading={registrations.isLoading}
                    />
                )}
                {canTickets && (
                    <KpiCard
                        icon={TicketIcon}
                        label="Tickets issued"
                        to="/tickets"
                        value={num(tickets.data ?? 0)}
                        sub={canPayments && paymentsSucceeded.data !== undefined ? `${num(paymentsSucceeded.data)} paid` : undefined}
                        isLoading={tickets.isLoading}
                        spark={canRevenue ? paymentsSpark : undefined}
                    />
                )}
                {canAttendees && (
                    <KpiCard
                        icon={Users}
                        label="Attendees"
                        to="/attendees"
                        value={num(attendees.data ?? 0)}
                        sub="Unique people on the roster"
                        isLoading={attendees.isLoading}
                    />
                )}
            </div>

            {/* Trend + capacity */}
            {(canRevenue || canTicketTypes) && (
                <div className="grid grid-cols-1 gap-4 xl:grid-cols-3">
                    {canRevenue && <div className={cn(canTicketTypes ? 'xl:col-span-2' : 'xl:col-span-3')}><SalesTrendCard /></div>}
                    {canTicketTypes && <div className={cn(canRevenue ? 'xl:col-span-1' : 'xl:col-span-3')}><CapacityCard /></div>}
                </div>
            )}

            {/* Registrations + side column */}
            {(canRegistrations || canRevenue || canGates) && (
                <div className="grid grid-cols-1 gap-4 xl:grid-cols-3">
                    {canRegistrations && <div className={cn(canRevenue || canGates ? 'xl:col-span-2' : 'xl:col-span-3')}><RecentRegistrations /></div>}
                    {(canRevenue || canGates) && (
                        <div className={cn('space-y-4', canRegistrations ? 'xl:col-span-1' : 'xl:col-span-3 xl:grid xl:grid-cols-2 xl:gap-4 xl:space-y-0')}>
                            {canRevenue && <PaymentMethodsCard />}
                            {canGates && <GateActivityCard />}
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
