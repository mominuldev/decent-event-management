import {
    Area,
    AreaChart,
    Bar,
    BarChart,
    CartesianGrid,
    Cell,
    Pie,
    PieChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { money, num } from '@/lib/cn';
import type { DailySalesDay, DailySalesMethod } from '@/features/reports/types';

/*
 * Every colour here is a CSS variable from app.css, never a literal — the
 * charts follow the theme toggle and the brand tokens the same way the rest
 * of the console does. Online sales are brand blue, counter sales are the
 * secondary orange, so the two tokens carry a real distinction rather than
 * decorating.
 */
const ONLINE = 'var(--color-brand-500)';
const OFFLINE = 'var(--color-secondary-500)';
const GRID = 'var(--color-chart-grid)';
const AXIS = 'var(--color-chart-axis)';

const axisTick = { fill: AXIS, fontSize: 11, fontFamily: 'inherit' } as const;

function dayLabel(iso: string): string {
    const d = new Date(`${iso}T00:00:00`);
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
}

/* ---- Tooltip ------------------------------------------------------------- */
interface TipRow {
    label: string;
    value: string;
    color?: string;
}

function ChartTip({ title, rows }: { title: string; rows: TipRow[] }) {
    return (
        <div className="min-w-[160px] rounded-xl border border-border bg-surface px-3 py-2.5 shadow-[var(--shadow-pop)]">
            <div className="text-[11px] font-semibold uppercase tracking-wide text-text-faint">{title}</div>
            <div className="mt-1.5 space-y-1">
                {rows.map((r) => (
                    <div key={r.label} className="flex items-center justify-between gap-4 text-[12.5px]">
                        <span className="flex items-center gap-1.5 text-text-muted">
                            {r.color && <span className="h-2 w-2 rounded-full" style={{ background: r.color }} />}
                            {r.label}
                        </span>
                        <span className="tnum font-semibold text-text">{r.value}</span>
                    </div>
                ))}
            </div>
        </div>
    );
}

/* ---- Sales trend --------------------------------------------------------- */
export function SalesTrendChart({ days }: { days: DailySalesDay[] }) {
    // The report answers newest day first; a time axis reads left to right.
    const data = [...days].reverse().map((d) => ({
        date: d.date,
        online: d.online_paisa / 100,
        offline: d.offline_paisa / 100,
        payments: d.total_payments,
    }));

    return (
        <ResponsiveContainer width="100%" height="100%" minHeight={260}>
            <AreaChart data={data} margin={{ top: 12, right: 8, left: 0, bottom: 0 }}>
                <defs>
                    <linearGradient id="dash-online" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stopColor={ONLINE} stopOpacity={0.35} />
                        <stop offset="100%" stopColor={ONLINE} stopOpacity={0.02} />
                    </linearGradient>
                    <linearGradient id="dash-offline" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stopColor={OFFLINE} stopOpacity={0.35} />
                        <stop offset="100%" stopColor={OFFLINE} stopOpacity={0.02} />
                    </linearGradient>
                </defs>
                <CartesianGrid stroke={GRID} strokeDasharray="3 6" vertical={false} />
                <XAxis
                    dataKey="date"
                    tickFormatter={dayLabel}
                    tick={axisTick}
                    axisLine={false}
                    tickLine={false}
                    minTickGap={28}
                    dy={6}
                />
                <YAxis
                    tick={axisTick}
                    axisLine={false}
                    tickLine={false}
                    width={52}
                    tickFormatter={(v: number) => (v >= 1000 ? `৳${(v / 1000).toFixed(v >= 10000 ? 0 : 1)}k` : `৳${v}`)}
                />
                <Tooltip
                    cursor={{ stroke: GRID, strokeWidth: 1 }}
                    content={({ active, payload }) => {
                        if (!active || !payload?.length) return null;
                        const p = payload[0].payload as (typeof data)[number];
                        return (
                            <ChartTip
                                title={dayLabel(p.date)}
                                rows={[
                                    { label: 'Online', value: money(Math.round(p.online * 100)), color: ONLINE },
                                    { label: 'Counter', value: money(Math.round(p.offline * 100)), color: OFFLINE },
                                    { label: 'Payments', value: num(p.payments) },
                                ]}
                            />
                        );
                    }}
                />
                {/* Counter first so it sits at the bottom of the stack and the online
                    stroke is painted last — otherwise a zero-height counter series draws
                    its stroke straight over the online line. */}
                <Area
                    type="monotone"
                    dataKey="offline"
                    stackId="sales"
                    stroke={OFFLINE}
                    strokeWidth={2}
                    fill="url(#dash-offline)"
                    isAnimationActive={false}
                />
                <Area
                    type="monotone"
                    dataKey="online"
                    stackId="sales"
                    stroke={ONLINE}
                    strokeWidth={2}
                    fill="url(#dash-online)"
                    isAnimationActive={false}
                />
            </AreaChart>
        </ResponsiveContainer>
    );
}

/* ---- Sparkline — the trend line inside a KPI card ------------------------ */
export function Sparkline({ values, color = ONLINE }: { values: number[]; color?: string }) {
    const data = values.map((v, i) => ({ i, v }));
    const id = `spark-${color.replace(/[^a-z0-9]/gi, '')}`;

    return (
        <ResponsiveContainer width="100%" height={40}>
            <AreaChart data={data} margin={{ top: 4, right: 0, left: 0, bottom: 0 }}>
                <defs>
                    <linearGradient id={id} x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stopColor={color} stopOpacity={0.3} />
                        <stop offset="100%" stopColor={color} stopOpacity={0} />
                    </linearGradient>
                </defs>
                <Area type="monotone" dataKey="v" stroke={color} strokeWidth={1.75} fill={`url(#${id})`} isAnimationActive={false} />
            </AreaChart>
        </ResponsiveContainer>
    );
}

/* ---- Capacity ring ------------------------------------------------------- */
export function CapacityRing({ sold, reserved, total }: { sold: number; reserved: number; total: number }) {
    const remaining = Math.max(0, total - sold - reserved);
    const data = [
        { key: 'sold', value: sold, color: ONLINE },
        { key: 'reserved', value: reserved, color: OFFLINE },
        { key: 'remaining', value: remaining, color: 'var(--color-surface-3)' },
    ];
    const pct = total > 0 ? Math.round((sold / total) * 100) : 0;

    return (
        <div className="relative h-[150px] w-[150px] shrink-0">
            <ResponsiveContainer width="100%" height="100%">
                <PieChart>
                    <Pie
                        data={data}
                        dataKey="value"
                        innerRadius={56}
                        outerRadius={70}
                        startAngle={90}
                        endAngle={-270}
                        stroke="none"
                        paddingAngle={total > 0 ? 2 : 0}
                        isAnimationActive={false}
                    >
                        {data.map((d) => (
                            <Cell key={d.key} fill={d.color} />
                        ))}
                    </Pie>
                </PieChart>
            </ResponsiveContainer>
            <div className="pointer-events-none absolute inset-0 grid place-items-center text-center">
                <div>
                    <div className="tnum font-display text-[26px] font-bold leading-none text-text">{pct}%</div>
                    <div className="mt-1 text-[11px] font-medium uppercase tracking-wide text-text-faint">sold</div>
                </div>
            </div>
        </div>
    );
}

/* ---- Payment methods ----------------------------------------------------- */
export function PaymentMethodsChart({ methods }: { methods: DailySalesMethod[] }) {
    const data = [...methods]
        .sort((a, b) => b.paisa - a.paisa)
        .slice(0, 6)
        .map((m) => ({
            name: m.method.replace(/_/g, ' '),
            amount: m.paisa / 100,
            payments: m.payments,
            color: m.kind === 'online' ? ONLINE : OFFLINE,
        }));

    return (
        <ResponsiveContainer width="100%" height={Math.max(120, data.length * 34 + 16)}>
            <BarChart data={data} layout="vertical" margin={{ top: 4, right: 12, left: 0, bottom: 0 }} barCategoryGap={8}>
                <XAxis type="number" hide />
                <YAxis
                    type="category"
                    dataKey="name"
                    width={84}
                    tick={{ ...axisTick, fill: 'var(--color-text-muted)', fontSize: 12 }}
                    axisLine={false}
                    tickLine={false}
                    className="capitalize"
                />
                <Tooltip
                    cursor={{ fill: 'var(--color-surface-2)' }}
                    content={({ active, payload }) => {
                        if (!active || !payload?.length) return null;
                        const p = payload[0].payload as (typeof data)[number];
                        return (
                            <ChartTip
                                title={p.name}
                                rows={[
                                    { label: 'Takings', value: money(Math.round(p.amount * 100)), color: p.color },
                                    { label: 'Payments', value: num(p.payments) },
                                ]}
                            />
                        );
                    }}
                />
                <Bar dataKey="amount" radius={[0, 6, 6, 0]} isAnimationActive={false} maxBarSize={18}>
                    {data.map((d) => (
                        <Cell key={d.name} fill={d.color} />
                    ))}
                </Bar>
            </BarChart>
        </ResponsiveContainer>
    );
}
