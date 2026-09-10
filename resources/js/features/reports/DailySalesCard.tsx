import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { CalendarRange, FileSpreadsheet, FileText, Globe, Sheet, Store } from 'lucide-react';
import { Button, Card, CardHeader, ErrorState, Input, Label, Select, Skeleton } from '@/components/ui';
import { useAuth } from '@/features/auth/AuthProvider';
import { useToast } from '@/components/Toast';
import { fetchTicketTypes } from '@/features/tickets/api';
import { money, num } from '@/lib/cn';
import * as reportsApi from './api';
import { dailySalesExportPermissionFor, type DailySalesDay, type DailySalesExportFormat, type DailySalesFilters, type DailySalesReport } from './types';

/**
 * Preset windows. "Today" is the one an operator actually opens this page for
 * at the end of a shift, so it leads.
 */
const PRESETS: { label: string; days: number }[] = [
    { label: 'Today', days: 1 },
    { label: '7 days', days: 7 },
    { label: '30 days', days: 30 },
    { label: '90 days', days: 90 },
];

/**
 * `YYYY-MM-DD` for a date `offset` days before today, computed from the
 * browser's own calendar. It only seeds the inputs — the server re-resolves
 * the window in the reporting timezone and echoes back what it used, which is
 * what the header displays, so a reader in another timezone is never shown a
 * window the numbers do not belong to.
 */
function isoDaysAgo(offset: number): string {
    const d = new Date();
    d.setDate(d.getDate() - offset);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function Tile({
    label,
    value,
    sub,
    icon,
    tone = 'neutral',
}: {
    label: string;
    value: string;
    sub?: string;
    icon?: React.ReactNode;
    tone?: 'neutral' | 'online' | 'offline' | 'total';
}) {
    const accent =
        tone === 'online'
            ? 'text-sky-600 dark:text-sky-400'
            : tone === 'offline'
              ? 'text-amber-600 dark:text-amber-400'
              : tone === 'total'
                ? 'text-text'
                : 'text-text-muted';

    return (
        <div className="rounded-xl border border-border bg-surface-2/50 p-3">
            <div className="flex items-center gap-1.5 text-[11px] uppercase tracking-wide text-text-faint">
                {icon}
                {label}
            </div>
            <div className={`tnum mt-1 text-[17px] font-semibold ${accent}`}>{value}</div>
            {sub && <div className="tnum mt-0.5 text-[11.5px] text-text-faint">{sub}</div>}
        </div>
    );
}

/**
 * A day's takings as one bar, online and offline stacked, scaled against the
 * busiest day in the window. Bars rather than a chart library: it is one
 * series of at most 366 values, and a dependency for that would be its own
 * decision.
 */
function DayBar({ day, max }: { day: DailySalesDay; max: number }) {
    const pct = (paisa: number) => (max === 0 ? 0 : (paisa / max) * 100);

    return (
        <div className="flex h-2 w-full overflow-hidden rounded-full bg-surface-2" title={`${money(day.total_paisa)} on ${day.date}`}>
            <div className="bg-sky-500/80" style={{ width: `${pct(day.online_paisa)}%` }} />
            <div className="bg-amber-500/80" style={{ width: `${pct(day.offline_paisa)}%` }} />
        </div>
    );
}

function DaysTable({ report }: { report: DailySalesReport }) {
    // Scaled against the busiest day so the bars are comparable down the
    // column; an all-zero window would divide by zero otherwise.
    const max = useMemo(() => Math.max(0, ...report.days.map((d) => d.total_paisa)), [report.days]);

    // A long window is mostly zeroes once registration closes, and a reader
    // scrolling past 300 empty rows to find the four that matter is worse off
    // than one who has to press a button to see them.
    const [showEmpty, setShowEmpty] = useState(false);
    const rows = showEmpty ? report.days : report.days.filter((d) => d.total_payments > 0 || d.refund_count > 0);
    const hidden = report.days.length - rows.length;

    if (report.days.length === 0) return null;

    return (
        <div className="px-2 pb-3">
            <div className="overflow-x-auto">
                <table className="w-full min-w-[720px] text-left text-[13px]">
                    <thead>
                        <tr className="border-y border-border text-[11px] uppercase tracking-wide text-text-faint">
                            <th className="px-3 py-2.5 font-semibold">Date</th>
                            <th className="w-[15%] px-3 py-2.5 font-semibold">Mix</th>
                            <th className="px-3 py-2.5 text-right font-semibold">Online</th>
                            <th className="px-3 py-2.5 text-right font-semibold">Offline</th>
                            <th className="px-3 py-2.5 text-right font-semibold">Total</th>
                            <th className="px-3 py-2.5 text-right font-semibold">People</th>
                            <th className="px-3 py-2.5 text-right font-semibold">Refunds</th>
                            <th className="px-3 py-2.5 text-right font-semibold">Net</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((d) => (
                            <tr key={d.date} className="border-b border-border last:border-0 hover:bg-table-row-hover">
                                <td className="whitespace-nowrap px-3 py-2.5 font-medium text-text">{d.date}</td>
                                <td className="px-3 py-2.5">
                                    <DayBar day={d} max={max} />
                                </td>
                                <td className="tnum px-3 py-2.5 text-right text-text">
                                    {money(d.online_paisa)}
                                    <span className="ml-1 text-[11.5px] text-text-faint">({num(d.online_payments)})</span>
                                </td>
                                <td className="tnum px-3 py-2.5 text-right text-text">
                                    {money(d.offline_paisa)}
                                    <span className="ml-1 text-[11.5px] text-text-faint">({num(d.offline_payments)})</span>
                                </td>
                                <td className="tnum px-3 py-2.5 text-right font-semibold text-text">{money(d.total_paisa)}</td>
                                <td className="tnum px-3 py-2.5 text-right text-text-muted">{num(d.total_persons)}</td>
                                <td className="tnum px-3 py-2.5 text-right text-text-muted">
                                    {d.refunded_paisa > 0 ? `−${money(d.refunded_paisa)}` : '—'}
                                </td>
                                <td className="tnum px-3 py-2.5 text-right text-text">{money(d.net_paisa)}</td>
                            </tr>
                        ))}
                        {rows.length === 0 && (
                            <tr>
                                <td colSpan={8} className="px-3 py-6 text-center text-[13px] text-text-muted">
                                    No sales in this window.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>

            {hidden > 0 && (
                <button
                    type="button"
                    onClick={() => setShowEmpty(true)}
                    className="mt-2 px-3 text-[12px] text-text-faint underline-offset-2 hover:underline"
                >
                    Show {num(hidden)} {hidden === 1 ? 'day' : 'days'} with no sales
                </button>
            )}
        </div>
    );
}


/**
 * Export controls for the applied filter set — `applied`, not the form, so the
 * file matches the numbers on screen rather than edits the reader has typed
 * but not pressed Apply on.
 *
 * There is no TanStack Query entry for this: a download is a one-off side
 * effect, not server state, and caching it would hand the operator a stale
 * file after they changed a filter.
 */
function ExportButtons({ filters }: { filters: DailySalesFilters }) {
    const { can } = useAuth();
    const { push } = useToast();
    const [pending, setPending] = useState<DailySalesExportFormat | null>(null);

    async function run(format: DailySalesExportFormat) {
        setPending(format);
        try {
            await reportsApi.exportDailySales(filters, format);
        } catch (e) {
            push('critical', e instanceof Error ? e.message : 'Export failed.');
        } finally {
            setPending(null);
        }
    }

    const formats: { format: DailySalesExportFormat; label: string; Icon: typeof FileText; title: string }[] = [
        { format: 'csv', label: 'CSV', Icon: Sheet, title: 'One row per day, for a spreadsheet or an accounts package' },
        { format: 'xlsx', label: 'Excel', Icon: FileSpreadsheet, title: 'Workbook with totals and a breakdown by payment method' },
        { format: 'pdf', label: 'PDF', Icon: FileText, title: 'A printable takings sheet' },
    ];

    // Each button is gated on its own format permission, so a role that may
    // take a CSV but not a PDF sees only the one it can use.
    const allowed = formats.filter((f) => can(dailySalesExportPermissionFor(f.format)));

    if (allowed.length === 0) return null;

    return (
        <div className="flex items-center gap-1.5">
            {allowed.map(({ format, label, Icon, title }) => (
                <Button
                    key={format}
                    variant="outline"
                    size="sm"
                    title={title}
                    disabled={pending !== null}
                    onClick={() => void run(format)}
                >
                    <Icon size={14} />
                    {pending === format ? 'Preparing…' : label}
                </Button>
            ))}
        </div>
    );
}

export function DailySalesCard() {
    // Seeded to the same default window the server would pick, so the inputs
    // never disagree with the numbers on first paint.
    const [form, setForm] = useState<DailySalesFilters>({
        from: isoDaysAgo(29),
        to: isoDaysAgo(0),
        ssc_batch_year: '',
        ticket_type_ulid: '',
    });
    const [applied, setApplied] = useState<DailySalesFilters>(form);

    const { data, isLoading, isError, error, refetch, isFetching } = useQuery({
        queryKey: ['report', 'daily-sales', applied],
        queryFn: () => reportsApi.fetchDailySales(applied),
    });

    // Ticket types are a short, rarely-changing list; the dropdown must not
    // take the report down with it, so a failure just leaves it empty.
    const { data: ticketTypes } = useQuery({
        queryKey: ['ticket-types'],
        queryFn: fetchTicketTypes,
        staleTime: 5 * 60 * 1000,
    });

    const preset = (days: number) => {
        const next = { ...form, from: isoDaysAgo(days - 1), to: isoDaysAgo(0) };
        setForm(next);
        setApplied(next);
    };

    const totals = data?.totals;

    return (
        <Card>
            <CardHeader
                title="Daily sales"
                subtitle={
                    data
                        ? `${data.filters.from} → ${data.filters.to} · days close at midnight ${data.filters.timezone}`
                        : 'Takings per day, split into online and counter sales.'
                }
                action={<ExportButtons filters={applied} />}
            />

            <div className="flex flex-wrap items-end gap-3 border-b border-border px-5 pb-4">
                <div className="w-[150px]">
                    <Label htmlFor="ds-from">From</Label>
                    <Input
                        id="ds-from"
                        type="date"
                        value={form.from ?? ''}
                        max={form.to || undefined}
                        onChange={(e) => setForm({ ...form, from: e.target.value })}
                    />
                </div>
                <div className="w-[150px]">
                    <Label htmlFor="ds-to">To</Label>
                    <Input
                        id="ds-to"
                        type="date"
                        value={form.to ?? ''}
                        min={form.from || undefined}
                        onChange={(e) => setForm({ ...form, to: e.target.value })}
                    />
                </div>
                <div className="w-[130px]">
                    <Label htmlFor="ds-batch">SSC batch</Label>
                    <Input
                        id="ds-batch"
                        type="number"
                        inputMode="numeric"
                        placeholder="Any"
                        value={form.ssc_batch_year ?? ''}
                        onChange={(e) => setForm({ ...form, ssc_batch_year: e.target.value })}
                    />
                </div>
                <div className="w-[200px]">
                    <Label htmlFor="ds-type">Ticket type</Label>
                    <Select
                        id="ds-type"
                        value={form.ticket_type_ulid ?? ''}
                        onChange={(e) => setForm({ ...form, ticket_type_ulid: e.target.value })}
                    >
                        <option value="">All ticket types</option>
                        {(ticketTypes ?? []).map((t) => (
                            <option key={t.ulid} value={t.ulid}>
                                {t.name}
                            </option>
                        ))}
                    </Select>
                </div>

                <Button size="sm" disabled={isFetching} onClick={() => setApplied(form)}>
                    <CalendarRange size={14} /> {isFetching ? 'Loading…' : 'Apply'}
                </Button>

                <div className="ml-auto flex gap-1">
                    {PRESETS.map((p) => (
                        <Button key={p.label} variant="outline" size="sm" onClick={() => preset(p.days)}>
                            {p.label}
                        </Button>
                    ))}
                </div>
            </div>

            {isLoading && (
                <div className="grid grid-cols-2 gap-3 px-5 py-4 sm:grid-cols-4">
                    {Array.from({ length: 4 }).map((_, i) => (
                        <Skeleton key={i} className="h-16 w-full" />
                    ))}
                </div>
            )}

            {isError && (
                <div className="px-5 py-6">
                    <ErrorState message={error instanceof Error ? error.message : 'Could not load daily sales.'} onRetry={() => void refetch()} />
                </div>
            )}

            {data && totals && (
                <>
                    <div className="grid grid-cols-2 gap-3 px-5 py-4 sm:grid-cols-4">
                        <Tile
                            label="Online"
                            tone="online"
                            icon={<Globe size={12} />}
                            value={money(totals.online_paisa)}
                            sub={`${num(totals.online_payments)} payments · ${num(totals.online_persons)} people`}
                        />
                        <Tile
                            label="Offline"
                            tone="offline"
                            icon={<Store size={12} />}
                            value={money(totals.offline_paisa)}
                            sub={`${num(totals.offline_payments)} payments · ${num(totals.offline_persons)} people`}
                        />
                        <Tile
                            label="Total taken"
                            tone="total"
                            value={money(totals.total_paisa)}
                            sub={`${num(totals.total_payments)} payments · ${num(totals.total_persons)} people`}
                        />
                        <Tile
                            label="Net of refunds"
                            tone="total"
                            value={money(totals.net_paisa)}
                            sub={totals.refund_count > 0 ? `${num(totals.refund_count)} refunded · −${money(totals.refunded_paisa)}` : 'No refunds in window'}
                        />
                    </div>

                    {data.methods.length > 0 && (
                        <div className="flex flex-wrap gap-2 px-5 pb-4">
                            {data.methods.map((m) => (
                                <span
                                    key={`${m.channel}|${m.method}`}
                                    className="inline-flex items-center gap-1.5 rounded-full border border-border bg-surface-2/50 px-2.5 py-1 text-[11.5px] text-text-muted"
                                >
                                    <span
                                        className={`size-1.5 rounded-full ${m.kind === 'online' ? 'bg-sky-500' : 'bg-amber-500'}`}
                                        aria-hidden
                                    />
                                    <span className="font-medium text-text">{m.method}</span>
                                    <span className="tnum">{money(m.paisa)}</span>
                                    <span className="tnum text-text-faint">({num(m.payments)})</span>
                                </span>
                            ))}
                        </div>
                    )}

                    <DaysTable report={data} />
                </>
            )}
        </Card>
    );
}
