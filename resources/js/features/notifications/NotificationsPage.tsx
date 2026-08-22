import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';
import { Pencil, Plus, RefreshCw } from 'lucide-react';
import { Badge, Button, Card, CardHeader, Input, Label, Select, Skeleton, type Tone } from '@/components/ui';
import { Dialog, ConfirmDialog } from '@/components/Dialog';
import { DataTable } from '@/components/DataTable';
import { useAuth } from '@/features/auth/AuthProvider';
import { useToast } from '@/components/Toast';
import { cn } from '@/lib/cn';
import { totalOf } from '@/lib/pagination';
import * as notificationsApi from './api';
import TemplateEditor from './TemplateEditor';
import type { CostRow, KillSwitches, NotificationChannel, NotificationRecord, NotificationTemplateSummary } from './types';

function titleCase(s: string) {
    return s.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function fmt(iso: string | null | undefined) {
    return iso ? new Date(iso).toLocaleString() : '—';
}

function bdt(paisa: number | null | undefined) {
    return paisa == null ? '—' : `৳${(paisa / 100).toFixed(2)}`;
}

const statusTone: Record<string, Tone> = {
    queued: 'neutral',
    sending: 'info',
    sent: 'success',
    delivered: 'success',
    read: 'success',
    failed: 'critical',
    bounced: 'critical',
    cancelled: 'neutral',
};

const RESENDABLE_STATUSES = ['failed', 'bounced'];

/* --------------------------------------------------------------- Detail */

function NotificationDetailDialog({ ulid, onClose }: { ulid: string; onClose: () => void }) {
    const { data, isLoading } = useQuery({
        queryKey: ['notification', ulid],
        queryFn: () => notificationsApi.fetchNotification(ulid),
    });

    return (
        <Dialog open onClose={onClose} title="Notification detail" className="max-w-lg">
            {isLoading && <Skeleton className="h-40 w-full" />}
            {data && (
                <div className="space-y-4">
                    <div className="grid grid-cols-2 gap-3 text-[13px]">
                        <div><span className="text-text-faint">Template</span><div className="font-medium text-text">{data.template_key}</div></div>
                        <div><span className="text-text-faint">Channel</span><div className="font-medium text-text">{titleCase(data.channel)}</div></div>
                        <div><span className="text-text-faint">Recipient</span><div className="font-medium text-text">{data.recipient}</div></div>
                        <div><span className="text-text-faint">Status</span><div><Badge tone={statusTone[data.status] ?? 'neutral'} size="sm">{titleCase(data.status)}</Badge></div></div>
                        <div><span className="text-text-faint">Attempts</span><div className="font-medium text-text tnum">{data.attempts} / {data.max_attempts}</div></div>
                        <div><span className="text-text-faint">Cost</span><div className="font-medium text-text tnum">{bdt(data.cost_paisa)}</div></div>
                    </div>
                    {data.last_error && (
                        <p className="rounded-lg border border-critical/30 bg-critical/5 px-3 py-2 text-[12.5px] text-critical-fg">{data.last_error}</p>
                    )}
                    <div>
                        <div className="mb-2 text-[12px] font-semibold uppercase tracking-wide text-text-faint">Delivery timeline</div>
                        {(!data.events || data.events.length === 0) && <p className="text-[13px] text-text-muted">No delivery receipts recorded yet.</p>}
                        <ul className="space-y-2">
                            {data.events?.map((e, i) => (
                                <li key={i} className="flex items-center justify-between rounded-lg border border-border bg-surface-2 px-3 py-2 text-[12.5px]">
                                    <span className="font-medium text-text">{titleCase(e.event)}</span>
                                    <span className="text-text-faint tnum">{fmt(e.occurred_at)}</span>
                                </li>
                            ))}
                        </ul>
                    </div>
                </div>
            )}
        </Dialog>
    );
}

/* ---------------------------------------------------------- Delivery log */

function deliveryColumns(opts: { canResend: boolean; onResend: (n: NotificationRecord) => void }): ColumnDef<NotificationRecord, unknown>[] {
    return [
        { id: 'created_at', header: 'Time', cell: (ctx) => <span className="tnum">{fmt(ctx.row.original.created_at)}</span> },
        { id: 'channel', header: 'Channel', cell: (ctx) => titleCase(ctx.row.original.channel) },
        { id: 'template_key', header: 'Template', cell: (ctx) => ctx.row.original.template_key },
        { id: 'recipient', header: 'Recipient', cell: (ctx) => <span className="text-text-muted">{ctx.row.original.recipient}</span> },
        {
            id: 'status',
            header: 'Status',
            cell: (ctx) => <Badge tone={statusTone[ctx.row.original.status] ?? 'neutral'} size="sm">{titleCase(ctx.row.original.status)}</Badge>,
        },
        { id: 'cost', header: 'Cost', cell: (ctx) => <span className="tnum">{bdt(ctx.row.original.cost_paisa)}</span> },
        {
            id: 'actions',
            header: '',
            cell: (ctx) => (
                <div className="flex justify-end" onClick={(e) => e.stopPropagation()}>
                    {opts.canResend && RESENDABLE_STATUSES.includes(ctx.row.original.status) && (
                        <Button variant="ghost" size="sm" onClick={() => opts.onResend(ctx.row.original)}>
                            <RefreshCw size={14} /> Resend
                        </Button>
                    )}
                </div>
            ),
        },
    ];
}

function DeliveryLogTab() {
    const { can } = useAuth();
    const { push } = useToast();
    const queryClient = useQueryClient();
    const [channel, setChannel] = useState('');
    const [status, setStatus] = useState('');
    const [templateKey, setTemplateKey] = useState('');
    const [dateFrom, setDateFrom] = useState('');
    const [dateTo, setDateTo] = useState('');
    const [pageIndex, setPageIndex] = useState(0);
    const [selected, setSelected] = useState<string | null>(null);
    const [confirmResend, setConfirmResend] = useState<NotificationRecord | null>(null);
    const pageSize = 20;

    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['notifications', channel, status, templateKey, dateFrom, dateTo, pageIndex],
        queryFn: () => notificationsApi.fetchNotifications({
            channel, status, template_key: templateKey, date_from: dateFrom, date_to: dateTo, page: pageIndex + 1, per_page: pageSize,
        }),
    });

    const resendMutation = useMutation({
        mutationFn: (ulid: string) => notificationsApi.resendNotification(ulid),
        onSuccess: () => {
            push('success', 'Resend queued.');
            void queryClient.invalidateQueries({ queryKey: ['notifications'] });
        },
    });

    const canResend = can('notification.resend');

    return (
        <Card>
            <CardHeader title="Delivery log" subtitle="Every outbox row and its current status" />
            <div className="flex flex-wrap items-end gap-3 px-5 pb-4 pt-4">
                <div className="w-40">
                    <Label htmlFor="notif_channel">Channel</Label>
                    <Select id="notif_channel" value={channel} onChange={(e) => { setChannel(e.target.value); setPageIndex(0); }}>
                        <option value="">All channels</option>
                        <option value="email">Email</option>
                        <option value="sms">SMS</option>
                        <option value="whatsapp">WhatsApp</option>
                    </Select>
                </div>
                <div className="w-44">
                    <Label htmlFor="notif_status">Status</Label>
                    <Select id="notif_status" value={status} onChange={(e) => { setStatus(e.target.value); setPageIndex(0); }}>
                        <option value="">All statuses</option>
                        {Object.keys(statusTone).map((s) => <option key={s} value={s}>{titleCase(s)}</option>)}
                    </Select>
                </div>
                <div className="w-48">
                    <Label htmlFor="notif_template">Template key</Label>
                    <Input id="notif_template" value={templateKey} onChange={(e) => { setTemplateKey(e.target.value); setPageIndex(0); }} placeholder="e.g. payment_succeeded" />
                </div>
                <div>
                    <Label htmlFor="notif_from">From</Label>
                    <Input id="notif_from" type="date" value={dateFrom} onChange={(e) => { setDateFrom(e.target.value); setPageIndex(0); }} />
                </div>
                <div>
                    <Label htmlFor="notif_to">To</Label>
                    <Input id="notif_to" type="date" value={dateTo} onChange={(e) => { setDateTo(e.target.value); setPageIndex(0); }} />
                </div>
            </div>

            <DataTable
                columns={deliveryColumns({ canResend, onResend: setConfirmResend })}
                data={data?.data ?? []}
                getRowId={(r) => r.ulid}
                isLoading={isLoading}
                isError={isError}
                onRetry={() => void refetch()}
                onRowClick={(row) => setSelected(row.ulid)}
                emptyTitle="No notifications found"
                emptyDescription="Try adjusting your filters."
                pageIndex={pageIndex}
                pageSize={pageSize}
                totalRows={data ? totalOf(data) : 0}
                onPageChange={setPageIndex}
            />

            {selected && <NotificationDetailDialog ulid={selected} onClose={() => setSelected(null)} />}
            {confirmResend && (
                <ConfirmDialog
                    open
                    onClose={() => setConfirmResend(null)}
                    onConfirm={async () => { await resendMutation.mutateAsync(confirmResend.ulid); }}
                    title={`Resend to ${confirmResend.recipient}?`}
                    description="Queues a fresh delivery attempt over the same channel. The original row is kept as-is for audit."
                    confirmLabel="Resend"
                    tone="primary"
                />
            )}
        </Card>
    );
}

/* -------------------------------------------------------------------- Costs */

function CostsTab() {
    const [dateFrom, setDateFrom] = useState('');
    const [dateTo, setDateTo] = useState('');

    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['notification-costs', dateFrom, dateTo],
        queryFn: () => notificationsApi.fetchNotificationCosts({ date_from: dateFrom, date_to: dateTo }),
    });

    const totals = (data ?? []).reduce(
        (acc, row) => ({
            cost: acc.cost + row.total_cost_paisa,
            segments: acc.segments + row.total_segments,
            messages: acc.messages + row.message_count,
        }),
        { cost: 0, segments: 0, messages: 0 },
    );

    return (
        <Card>
            <CardHeader
                title="Delivery cost"
                subtitle="Segment and cost totals by channel and day"
                action={<Button variant="outline" size="sm" onClick={() => void refetch()}><RefreshCw size={14} /> Refresh</Button>}
            />
            <div className="flex flex-wrap items-end gap-3 px-5 pb-4 pt-4">
                <div>
                    <Label htmlFor="cost_from">From</Label>
                    <Input id="cost_from" type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} />
                </div>
                <div>
                    <Label htmlFor="cost_to">To</Label>
                    <Input id="cost_to" type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} />
                </div>
            </div>

            <div className="grid grid-cols-1 gap-3 px-5 pb-5 sm:grid-cols-3">
                <div className="rounded-xl border border-border bg-surface-2 p-4">
                    <div className="text-[12px] text-text-faint">Total cost</div>
                    <div className="mt-1 text-[22px] font-bold tnum text-text">{bdt(totals.cost)}</div>
                </div>
                <div className="rounded-xl border border-border bg-surface-2 p-4">
                    <div className="text-[12px] text-text-faint">Total segments</div>
                    <div className="mt-1 text-[22px] font-bold tnum text-text">{totals.segments}</div>
                </div>
                <div className="rounded-xl border border-border bg-surface-2 p-4">
                    <div className="text-[12px] text-text-faint">Messages sent</div>
                    <div className="mt-1 text-[22px] font-bold tnum text-text">{totals.messages}</div>
                </div>
            </div>

            <div className="overflow-x-auto px-2 pb-3">
                {isLoading && <div className="space-y-2 px-3"><Skeleton className="h-9 w-full" /><Skeleton className="h-9 w-full" /></div>}
                {isError && <p className="px-3 py-6 text-[13px] text-critical-fg">Failed to load cost data.</p>}
                {data && (
                    <table className="w-full min-w-[520px] text-left text-[13px]">
                        <thead>
                            <tr className="border-y border-border text-[11px] uppercase tracking-wide text-text-faint">
                                <th className="px-3 py-2.5 font-semibold">Date</th>
                                <th className="px-3 py-2.5 font-semibold">Channel</th>
                                <th className="px-3 py-2.5 font-semibold">Messages</th>
                                <th className="px-3 py-2.5 font-semibold">Segments</th>
                                <th className="px-3 py-2.5 font-semibold">Cost</th>
                            </tr>
                        </thead>
                        <tbody>
                            {data.map((row: CostRow, i: number) => (
                                <tr key={i} className="border-b border-border last:border-0">
                                    <td className="px-3 py-2.5 tnum text-text-muted">{row.date}</td>
                                    <td className="px-3 py-2.5">{titleCase(row.channel)}</td>
                                    <td className="px-3 py-2.5 tnum">{row.message_count}</td>
                                    <td className="px-3 py-2.5 tnum">{row.total_segments}</td>
                                    <td className="px-3 py-2.5 tnum">{bdt(row.total_cost_paisa)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
                {data && data.length === 0 && <p className="px-3 py-6 text-center text-[13px] text-text-muted">No delivery cost recorded for this range.</p>}
            </div>
        </Card>
    );
}

/* ------------------------------------------------------------- Kill switches */

const CHANNEL_LABELS: Record<NotificationChannel, string> = { email: 'Email', sms: 'SMS', whatsapp: 'WhatsApp' };

function KillSwitchesTab() {
    const { push } = useToast();
    const queryClient = useQueryClient();
    const [confirmChannel, setConfirmChannel] = useState<NotificationChannel | null>(null);

    const { data, isLoading } = useQuery({ queryKey: ['notification-kill-switches'], queryFn: notificationsApi.fetchKillSwitches });

    const updateMutation = useMutation({
        mutationFn: ({ channel, enabled }: { channel: NotificationChannel; enabled: boolean }) => notificationsApi.updateKillSwitch(channel, enabled),
        onSuccess: (result) => {
            push('success', `${CHANNEL_LABELS[result.channel]} ${result.enabled ? 'enabled' : 'disabled'}.`);
            queryClient.setQueryData<KillSwitches | undefined>(['notification-kill-switches'], (prev) =>
                prev ? { ...prev, [result.channel]: result.enabled } : prev);
        },
    });

    const channels: NotificationChannel[] = ['email', 'sms', 'whatsapp'];

    return (
        <Card>
            <CardHeader title="Kill switches" subtitle="Stop a channel from sending within 60 seconds — a two-step confirm, since this affects every queued message" />
            <div className="space-y-3 px-5 pb-5 pt-2">
                {isLoading && <Skeleton className="h-16 w-full" />}
                {data && channels.map((channel) => {
                    const enabled = data[channel];
                    return (
                        <div key={channel} className="flex items-center justify-between rounded-xl border border-border bg-surface-2 px-4 py-3">
                            <div>
                                <div className="font-medium text-text">{CHANNEL_LABELS[channel]}</div>
                                <div className="text-[12.5px] text-text-muted">Checked at send-time — a flip cancels anything still queued.</div>
                            </div>
                            <div className="flex items-center gap-3">
                                <Badge tone={enabled ? 'success' : 'critical'} size="sm">{enabled ? 'Enabled' : 'Disabled'}</Badge>
                                <Button variant="outline" size="sm" onClick={() => setConfirmChannel(channel)}>
                                    {enabled ? 'Disable' : 'Enable'}
                                </Button>
                            </div>
                        </div>
                    );
                })}
            </div>

            {confirmChannel && data && (
                <ConfirmDialog
                    open
                    onClose={() => setConfirmChannel(null)}
                    onConfirm={async () => { await updateMutation.mutateAsync({ channel: confirmChannel, enabled: !data[confirmChannel] }); }}
                    title={`${data[confirmChannel] ? 'Disable' : 'Enable'} ${CHANNEL_LABELS[confirmChannel]}?`}
                    description={
                        data[confirmChannel]
                            ? 'All queued and future messages on this channel stop sending immediately. Existing message history is kept.'
                            : 'Messages on this channel resume sending, including anything still queued from before it was disabled.'
                    }
                    confirmLabel={data[confirmChannel] ? 'Disable channel' : 'Enable channel'}
                    tone={data[confirmChannel] ? 'danger' : 'primary'}
                />
            )}
        </Card>
    );
}

/* ---------------------------------------------------------------- Templates */

const whatsappStatusTone: Record<string, Tone> = {
    approved: 'success',
    pending_approval: 'warning',
    rejected: 'critical',
};

function TemplatesTab() {
    const { can } = useAuth();
    const canManage = can('notification.manage_templates');
    const [key, setKey] = useState('');
    // `null` means the editor is closed; a template means edit; `'new'`
    // means create. Three states in one, because "edit nothing" and "create"
    // are genuinely different and a boolean cannot say which.
    const [editing, setEditing] = useState<NotificationTemplateSummary | 'new' | null>(null);
    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['notification-templates', key],
        queryFn: () => notificationsApi.fetchNotificationTemplates(key || undefined),
    });

    return (
        <Card>
            <CardHeader
                title="Templates"
                subtitle="The exact wording sent to attendees. SMS is billed per segment, so the cost of each is shown."
                action={
                    <div className="flex gap-2">
                        <Button variant="outline" size="sm" onClick={() => void refetch()}><RefreshCw size={14} /> Refresh</Button>
                        {canManage && (
                            <Button size="sm" onClick={() => setEditing('new')}><Plus size={14} /> New template</Button>
                        )}
                    </div>
                }
            />
            <div className="px-5 pb-4 pt-2">
                <div className="w-64">
                    <Label htmlFor="tpl_key">Filter by template key</Label>
                    <Input id="tpl_key" value={key} onChange={(e) => setKey(e.target.value)} placeholder="e.g. ticket_delivered" />
                </div>
            </div>
            <div className="overflow-x-auto px-2 pb-3">
                {isLoading && <div className="space-y-2 px-3"><Skeleton className="h-9 w-full" /><Skeleton className="h-9 w-full" /></div>}
                {isError && <p className="px-3 py-6 text-[13px] text-critical-fg">Failed to load templates.</p>}
                {data && (
                    <table className="w-full min-w-[640px] text-left text-[13px]">
                        <thead>
                            <tr className="border-y border-border text-[11px] uppercase tracking-wide text-text-faint">
                                <th className="px-3 py-2.5 font-semibold">Key</th>
                                <th className="px-3 py-2.5 font-semibold">Channel</th>
                                <th className="px-3 py-2.5 font-semibold">Locale</th>
                                <th className="px-3 py-2.5 font-semibold">Message</th>
                                <th className="px-3 py-2.5 font-semibold">Cost</th>
                                <th className="px-3 py-2.5 font-semibold">Active</th>
                                <th className="px-3 py-2.5 font-semibold">WhatsApp approval</th>
                                <th className="px-3 py-2.5" />
                            </tr>
                        </thead>
                        <tbody>
                            {data.map((t: NotificationTemplateSummary, i: number) => (
                                <tr key={i} className="border-b border-border last:border-0">
                                    <td className="px-3 py-2.5">{t.key}</td>
                                    <td className="px-3 py-2.5">{titleCase(t.channel)}</td>
                                    <td className="px-3 py-2.5 uppercase">{t.locale}</td>
                                    <td className="max-w-[22rem] px-3 py-2.5">
                                        <span className="block truncate text-text-muted" title={t.body}>{t.body}</span>
                                    </td>
                                    <td className="px-3 py-2.5 tnum">
                                        {t.estimated_segments === null
                                            ? <span className="text-text-faint">—</span>
                                            : <Badge tone={t.estimated_segments > 1 ? 'warning' : 'neutral'} size="sm">
                                                {t.estimated_segments} seg
                                            </Badge>}
                                    </td>
                                    <td className="px-3 py-2.5"><Badge tone={t.is_active ? 'success' : 'neutral'} size="sm">{t.is_active ? 'Active' : 'Inactive'}</Badge></td>
                                    <td className="px-3 py-2.5">
                                        {t.channel === 'whatsapp'
                                            ? <Badge tone={whatsappStatusTone[t.whatsapp_template_status ?? ''] ?? 'neutral'} size="sm">{titleCase(t.whatsapp_template_status ?? 'unknown')}</Badge>
                                            : <span className="text-text-faint">—</span>}
                                    </td>
                                    <td className="px-3 py-2.5 text-right">
                                        {canManage && (
                                            <Button variant="ghost" size="sm" onClick={() => setEditing(t)}>
                                                <Pencil size={14} /> Edit
                                            </Button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
                {data && data.length === 0 && <p className="px-3 py-6 text-center text-[13px] text-text-muted">No templates match this filter.</p>}
            </div>

            <Dialog
                open={editing !== null}
                onClose={() => setEditing(null)}
                title={editing === 'new' ? 'New template' : 'Edit template'}
                className="max-w-2xl"
            >
                {editing !== null && (
                    <TemplateEditor
                        template={editing === 'new' ? null : editing}
                        onClose={() => setEditing(null)}
                    />
                )}
            </Dialog>
        </Card>
    );
}

/* --------------------------------------------------------------------- Page */

const TABS = [
    { key: 'log', label: 'Delivery log', permission: 'notification.view_any' },
    { key: 'costs', label: 'Costs', permission: 'notification.view_costs' },
    { key: 'kill-switches', label: 'Kill switches', permission: 'notification.send_broadcast' },
    { key: 'templates', label: 'Templates', permission: 'notification.manage_templates' },
] as const;

export default function NotificationsPage() {
    const { can } = useAuth();
    const visibleTabs = TABS.filter((t) => can(t.permission));
    const [tab, setTab] = useState<(typeof TABS)[number]['key']>(visibleTabs[0]?.key ?? 'log');

    return (
        <div className="space-y-6">
            <div>
                <h1 className="text-[26px] font-bold tracking-tight text-text">Notifications</h1>
                <p className="mt-1 text-[14px] text-text-muted">Delivery log, cost by channel, kill switches, and template status.</p>
            </div>

            <div className="flex flex-wrap gap-1 rounded-xl border border-border bg-surface p-1 w-fit">
                {visibleTabs.map((t) => (
                    <button
                        key={t.key}
                        onClick={() => setTab(t.key)}
                        className={cn(
                            'rounded-lg px-3.5 py-1.5 text-[13px] font-medium transition-colors',
                            tab === t.key ? 'bg-accent text-accent-fg' : 'text-text-muted hover:text-text',
                        )}
                    >
                        {t.label}
                    </button>
                ))}
            </div>

            {tab === 'log' && <DeliveryLogTab />}
            {tab === 'costs' && <CostsTab />}
            {tab === 'kill-switches' && <KillSwitchesTab />}
            {tab === 'templates' && <TemplatesTab />}

            {visibleTabs.length === 0 && <p className="text-[13px] text-text-muted">You don't have permission to view notification data.</p>}
        </div>
    );
}
