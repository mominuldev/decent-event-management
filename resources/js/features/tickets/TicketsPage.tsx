import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';
import { Plus, RefreshCw, Search, Trash2, XCircle } from 'lucide-react';
import { Badge, Button, Card, CardHeader, Input, Label, Select, Skeleton, Textarea, type Tone } from '@/components/ui';
import { Dialog, ConfirmDialog } from '@/components/Dialog';
import { DataTable } from '@/components/DataTable';
import { useAuth } from '@/features/auth/AuthProvider';
import { useToast } from '@/components/Toast';
import { cn, money, num } from '@/lib/cn';
import { totalOf } from '@/lib/pagination';
import * as ticketsApi from './api';
import type { Ticket, TicketType, TicketTypePayload } from './types';

function titleCase(s: string) {
    return s.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

const ticketStatusTone: Record<string, Tone> = {
    issued: 'neutral',
    active: 'success',
    partially_admitted: 'warning',
    fully_admitted: 'success',
    voided: 'critical',
    refunded: 'critical',
};

/* ---------------------------------------------------------------- Tickets */

function TicketDetail({ ulid, onClose }: { ulid: string; onClose: () => void }) {
    const { can } = useAuth();
    const { push } = useToast();
    const queryClient = useQueryClient();
    const [confirmVoid, setConfirmVoid] = useState(false);
    const [confirmReissue, setConfirmReissue] = useState(false);

    const { data, isLoading } = useQuery({
        queryKey: ['ticket', ulid],
        queryFn: () => ticketsApi.fetchTicket(ulid),
    });

    const invalidate = () => {
        void queryClient.invalidateQueries({ queryKey: ['ticket', ulid] });
        void queryClient.invalidateQueries({ queryKey: ['tickets'] });
    };

    const voidMutation = useMutation({
        mutationFn: (reason: string) => ticketsApi.voidTicket(ulid, reason),
        onSuccess: () => { push('success', 'Ticket voided.'); invalidate(); },
    });
    const reissueMutation = useMutation({
        mutationFn: () => ticketsApi.reissueTicket(ulid),
        onSuccess: (reissued) => {
            push('success', `Reissued as ${reissued.ticket_number}.`);
            invalidate();
        },
    });

    const isTerminal = data?.status === 'voided' || data?.status === 'refunded';
    const canVoid = can('ticket.void') && !isTerminal;
    const canReissue = can('ticket.reissue') && !isTerminal;

    return (
        <Dialog open onClose={onClose} title={data?.ticket_number ?? 'Ticket'} description={data?.holder_name ?? undefined} className="max-w-lg">
            {isLoading || !data ? (
                <div className="space-y-3">
                    {Array.from({ length: 5 }).map((_, i) => <Skeleton key={i} className="h-9 w-full" />)}
                </div>
            ) : (
                <div className="space-y-5">
                    <div className="flex items-center gap-2">
                        <Badge tone={ticketStatusTone[data.status] ?? 'neutral'}>{titleCase(data.status)}</Badge>
                        {data.ticket_type?.name && <Badge tone="neutral">{data.ticket_type.name}</Badge>}
                    </div>

                    <div className="grid grid-cols-2 gap-3 text-[13px]">
                        <div><div className="text-text-faint">Holder</div><div className="font-medium text-text">{data.holder_name ?? '—'}</div></div>
                        <div><div className="text-text-faint">Batch year</div><div className="font-medium text-text">{data.holder_batch_year ?? '—'}</div></div>
                        <div><div className="text-text-faint">Admits</div><div className="tnum font-medium text-text">{data.admitted_count} / {data.admits_total}</div></div>
                        <div><div className="text-text-faint">Price paid</div><div className="tnum font-medium text-text">{money(data.price_paid_paisa)}</div></div>
                        <div><div className="text-text-faint">Issued</div><div className="font-medium text-text">{data.issued_at ? new Date(data.issued_at).toLocaleString() : '—'}</div></div>
                        <div><div className="text-text-faint">Last admitted</div><div className="font-medium text-text">{data.last_admitted_at ? new Date(data.last_admitted_at).toLocaleString() : '—'}</div></div>
                        {data.void_reason && (
                            <div className="col-span-2"><div className="text-text-faint">Void reason</div><div className="font-medium text-critical-fg">{data.void_reason}</div></div>
                        )}
                    </div>

                    <div className="flex flex-wrap justify-end gap-2 border-t border-border pt-4">
                        <Button variant="outline" size="sm" onClick={onClose}>Close</Button>
                        {canReissue && (
                            <Button variant="outline" size="sm" onClick={() => setConfirmReissue(true)}>
                                <RefreshCw size={14} /> Reissue
                            </Button>
                        )}
                        {canVoid && (
                            <Button variant="danger" size="sm" onClick={() => setConfirmVoid(true)}>
                                <XCircle size={14} /> Void
                            </Button>
                        )}
                    </div>
                </div>
            )}

            <ConfirmDialog
                open={confirmVoid}
                onClose={() => setConfirmVoid(false)}
                onConfirm={async (reason) => { await voidMutation.mutateAsync(reason ?? ''); }}
                title="Void this ticket?"
                description="The ticket becomes permanently unusable and its QR code is deactivated. Tickets are never edited once issued — void + reissue is the only correction path."
                confirmLabel="Void ticket"
                reasonLabel="Void reason"
                reasonPlaceholder="e.g. Duplicate issuance, holder requested change of name"
            />
            <ConfirmDialog
                open={confirmReissue}
                onClose={() => setConfirmReissue(false)}
                onConfirm={async () => { await reissueMutation.mutateAsync(); }}
                title="Reissue this ticket?"
                description="This voids the current ticket and issues a fresh one linked to it via a replaces-chain."
                confirmLabel="Reissue ticket"
                tone="primary"
            />
        </Dialog>
    );
}

const ticketColumns: ColumnDef<Ticket, unknown>[] = [
    {
        accessorKey: 'ticket_number',
        header: 'Ticket',
        cell: (ctx) => <span className="font-medium text-text">{ctx.row.original.ticket_number}</span>,
    },
    { id: 'holder', header: 'Holder', cell: (ctx) => ctx.row.original.holder_name ?? '—' },
    { id: 'type', header: 'Type', cell: (ctx) => ctx.row.original.ticket_type?.name ?? '—' },
    {
        accessorKey: 'status',
        header: 'Status',
        cell: (ctx) => <Badge tone={ticketStatusTone[ctx.row.original.status] ?? 'neutral'}>{titleCase(ctx.row.original.status)}</Badge>,
    },
    {
        id: 'admits',
        header: 'Admits',
        cell: (ctx) => <span className="tnum">{ctx.row.original.admitted_count} / {ctx.row.original.admits_total}</span>,
    },
];

function TicketsTab() {
    const [status, setStatus] = useState('');
    const [search, setSearch] = useState('');
    const [pageIndex, setPageIndex] = useState(0);
    const [selected, setSelected] = useState<string | null>(null);
    const pageSize = 20;

    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['tickets', status, search, pageIndex],
        queryFn: () => ticketsApi.fetchTickets({ status, search, page: pageIndex + 1, per_page: pageSize }),
    });

    return (
        <Card>
            <CardHeader title="All tickets" />
            <div className="flex flex-wrap items-end gap-3 px-5 pb-4 pt-4">
                <div className="min-w-[200px] flex-1">
                    <Label htmlFor="ticket_search">Search</Label>
                    <div className="relative">
                        <Search size={15} className="absolute left-3 top-1/2 -translate-y-1/2 text-text-faint" />
                        <Input
                            id="ticket_search"
                            className="pl-9"
                            placeholder="Ticket number or holder"
                            value={search}
                            onChange={(e) => { setSearch(e.target.value); setPageIndex(0); }}
                        />
                    </div>
                </div>
                <div className="w-44">
                    <Label htmlFor="ticket_status">Status</Label>
                    <Select id="ticket_status" value={status} onChange={(e) => { setStatus(e.target.value); setPageIndex(0); }}>
                        <option value="">All statuses</option>
                        {Object.keys(ticketStatusTone).map((s) => (
                            <option key={s} value={s}>{titleCase(s)}</option>
                        ))}
                    </Select>
                </div>
            </div>

            <DataTable
                columns={ticketColumns}
                data={data?.data ?? []}
                getRowId={(r) => r.ulid}
                isLoading={isLoading}
                isError={isError}
                onRetry={() => void refetch()}
                onRowClick={(row) => setSelected(row.ulid)}
                emptyTitle="No tickets found"
                emptyDescription="Try adjusting your filters."
                pageIndex={pageIndex}
                pageSize={pageSize}
                totalRows={data ? totalOf(data) : 0}
                onPageChange={setPageIndex}
            />

            {selected && <TicketDetail ulid={selected} onClose={() => setSelected(null)} />}
        </Card>
    );
}

/* ----------------------------------------------------------- Ticket types */

function emptyTicketTypeForm(): TicketTypePayload {
    return {
        code: '',
        name: '',
        name_bn: '',
        description: '',
        base_price_paisa: 0,
        additional_adult_price_paisa: 0,
        additional_child_price_paisa: 0,
        base_admits: 1,
        max_admits: 1,
        quantity_total: null,
        requires_approval: false,
        includes_tshirt: false,
        includes_meal: false,
        is_active: true,
        is_public: true,
        badge_color: '',
        sort_order: 0,
    };
}

function ticketTypeToForm(t: TicketType): TicketTypePayload {
    return {
        code: t.code,
        name: t.name,
        name_bn: t.name_bn ?? '',
        description: t.description ?? '',
        base_price_paisa: t.base_price_paisa,
        additional_adult_price_paisa: t.additional_adult_price_paisa,
        additional_child_price_paisa: t.additional_child_price_paisa,
        base_admits: t.base_admits,
        max_admits: t.max_admits,
        quantity_total: t.quantity_total,
        requires_approval: t.requires_approval,
        includes_tshirt: t.includes_tshirt,
        includes_meal: t.includes_meal,
        is_active: t.is_active,
        is_public: t.is_public,
        badge_color: t.badge_color ?? '',
        sort_order: t.sort_order,
    };
}

function TicketTypeFormDialog({ existing, onClose }: { existing: TicketType | null; onClose: () => void }) {
    const { push } = useToast();
    const queryClient = useQueryClient();
    const [form, setForm] = useState<TicketTypePayload>(existing ? ticketTypeToForm(existing) : emptyTicketTypeForm());
    const [error, setError] = useState<string | null>(null);

    const locked = (existing?.quantity_sold ?? 0) > 0;

    const saveMutation = useMutation({
        mutationFn: () =>
            existing ? ticketsApi.updateTicketType(existing.ulid, form) : ticketsApi.createTicketType(form),
        onSuccess: () => {
            push('success', existing ? 'Ticket type updated.' : 'Ticket type created.');
            void queryClient.invalidateQueries({ queryKey: ['ticket-types'] });
            onClose();
        },
        onError: (e: Error) => setError(e.message),
    });

    function money2paisa(bdt: string) {
        return Math.round(Number(bdt || 0) * 100);
    }

    return (
        <Dialog open onClose={onClose} title={existing ? `Edit ${existing.name}` : 'New ticket type'} className="max-w-lg">
            <div className="max-h-[65vh] space-y-4 overflow-y-auto pr-1">
                {locked && (
                    <p className="rounded-lg bg-warning-bg px-3 py-2 text-[12.5px] text-warning-fg">
                        Code and prices are locked — this tier already has tickets sold.
                    </p>
                )}
                <div className="grid grid-cols-2 gap-3">
                    <div>
                        <Label htmlFor="tt_code">Code</Label>
                        <Input id="tt_code" disabled={locked} value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })} />
                    </div>
                    <div>
                        <Label htmlFor="tt_name">Name</Label>
                        <Input id="tt_name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
                    </div>
                    <div className="col-span-2">
                        <Label htmlFor="tt_name_bn">Name (বাংলা)</Label>
                        <Input id="tt_name_bn" value={form.name_bn ?? ''} onChange={(e) => setForm({ ...form, name_bn: e.target.value })} />
                    </div>
                </div>

                <div>
                    <Label htmlFor="tt_description">Description</Label>
                    <Textarea id="tt_description" rows={2} value={form.description ?? ''} onChange={(e) => setForm({ ...form, description: e.target.value })} />
                </div>

                <div className="grid grid-cols-3 gap-3">
                    <div>
                        <Label htmlFor="tt_base_price">Base price (BDT)</Label>
                        <Input
                            id="tt_base_price"
                            type="number"
                            disabled={locked}
                            value={form.base_price_paisa / 100}
                            onChange={(e) => setForm({ ...form, base_price_paisa: money2paisa(e.target.value) })}
                        />
                    </div>
                    <div>
                        <Label htmlFor="tt_adult_price">+Adult (BDT)</Label>
                        <Input
                            id="tt_adult_price"
                            type="number"
                            disabled={locked}
                            value={form.additional_adult_price_paisa / 100}
                            onChange={(e) => setForm({ ...form, additional_adult_price_paisa: money2paisa(e.target.value) })}
                        />
                    </div>
                    <div>
                        <Label htmlFor="tt_child_price">+Child (BDT)</Label>
                        <Input
                            id="tt_child_price"
                            type="number"
                            disabled={locked}
                            value={form.additional_child_price_paisa / 100}
                            onChange={(e) => setForm({ ...form, additional_child_price_paisa: money2paisa(e.target.value) })}
                        />
                    </div>
                </div>

                <div className="grid grid-cols-3 gap-3">
                    <div>
                        <Label htmlFor="tt_base_admits">Base admits</Label>
                        <Input id="tt_base_admits" type="number" min={1} value={form.base_admits} onChange={(e) => setForm({ ...form, base_admits: Number(e.target.value) })} />
                    </div>
                    <div>
                        <Label htmlFor="tt_max_admits">Max admits</Label>
                        <Input id="tt_max_admits" type="number" min={form.base_admits} value={form.max_admits} onChange={(e) => setForm({ ...form, max_admits: Number(e.target.value) })} />
                    </div>
                    <div>
                        <Label htmlFor="tt_quantity">Capacity</Label>
                        <Input
                            id="tt_quantity"
                            type="number"
                            min={1}
                            placeholder="Unlimited"
                            value={form.quantity_total ?? ''}
                            onChange={(e) => setForm({ ...form, quantity_total: e.target.value ? Number(e.target.value) : null })}
                        />
                    </div>
                </div>

                <div className="grid grid-cols-2 gap-3">
                    <div>
                        <Label htmlFor="tt_badge_color">Badge colour</Label>
                        <Input id="tt_badge_color" type="text" placeholder="#22c55e" value={form.badge_color ?? ''} onChange={(e) => setForm({ ...form, badge_color: e.target.value })} />
                    </div>
                    <div>
                        <Label htmlFor="tt_sort_order">Sort order</Label>
                        <Input id="tt_sort_order" type="number" value={form.sort_order ?? 0} onChange={(e) => setForm({ ...form, sort_order: Number(e.target.value) })} />
                    </div>
                </div>

                <div className="grid grid-cols-2 gap-2 text-[13px]">
                    {([
                        ['requires_approval', 'Requires approval'],
                        ['includes_tshirt', 'Includes T-shirt'],
                        ['includes_meal', 'Includes meal'],
                        ['is_active', 'Active'],
                        ['is_public', 'Public'],
                    ] as const).map(([key, label]) => (
                        <label key={key} className="flex items-center gap-2 text-text">
                            <input
                                type="checkbox"
                                checked={Boolean(form[key])}
                                onChange={(e) => setForm({ ...form, [key]: e.target.checked })}
                            />
                            {label}
                        </label>
                    ))}
                </div>

                {error && <p className="text-[13px] text-critical-fg">{error}</p>}
            </div>
            <div className="flex justify-end gap-2 border-t border-border pt-4">
                <Button variant="outline" size="sm" onClick={onClose} disabled={saveMutation.isPending}>Cancel</Button>
                <Button size="sm" disabled={saveMutation.isPending || !form.code || !form.name} onClick={() => void saveMutation.mutateAsync()}>
                    {saveMutation.isPending ? 'Saving…' : existing ? 'Save changes' : 'Create ticket type'}
                </Button>
            </div>
        </Dialog>
    );
}

function TicketTypesTab() {
    const { can } = useAuth();
    const { push } = useToast();
    const queryClient = useQueryClient();
    const [editing, setEditing] = useState<TicketType | null | 'new'>(null);
    const [confirmDelete, setConfirmDelete] = useState<TicketType | null>(null);

    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['ticket-types'],
        queryFn: ticketsApi.fetchTicketTypes,
    });

    const deleteMutation = useMutation({
        mutationFn: (ulid: string) => ticketsApi.deleteTicketType(ulid),
        onSuccess: () => {
            push('success', 'Ticket type deleted.');
            void queryClient.invalidateQueries({ queryKey: ['ticket-types'] });
        },
    });

    const canManage = can('ticket_type.manage');
    const canDelete = can('ticket_type.delete');

    return (
        <Card>
            <CardHeader
                title="Ticket types"
                subtitle="Pricing, capacity, and eligibility per tier"
                action={canManage && (
                    <Button size="sm" onClick={() => setEditing('new')}>
                        <Plus size={14} /> New ticket type
                    </Button>
                )}
            />
            <div className="overflow-x-auto px-2 pb-3 pt-3">
                {isLoading && (
                    <div className="space-y-2 px-3">
                        {Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} className="h-10 w-full" />)}
                    </div>
                )}
                {isError && (
                    <div className="flex items-center justify-between px-3 text-[13px] text-critical-fg">
                        <span>Failed to load ticket types.</span>
                        <Button variant="outline" size="sm" onClick={() => void refetch()}>Retry</Button>
                    </div>
                )}
                {data && (
                    <table className="w-full min-w-[640px] text-left text-[13px]">
                        <thead>
                            <tr className="border-y border-border text-[11px] uppercase tracking-wide text-text-faint">
                                <th className="px-3 py-2.5 font-semibold">Type</th>
                                <th className="px-3 py-2.5 font-semibold">Price</th>
                                <th className="px-3 py-2.5 font-semibold">Sold / Capacity</th>
                                <th className="px-3 py-2.5 font-semibold">Status</th>
                                <th className="px-3 py-2.5 font-semibold" />
                            </tr>
                        </thead>
                        <tbody>
                            {data.map((t) => (
                                <tr key={t.ulid} className="border-b border-border last:border-0 hover:bg-table-row-hover">
                                    <td className="px-3 py-2.5">
                                        <div className="font-medium text-text">{t.name}</div>
                                        <div className="text-[11.5px] text-text-faint">{t.code}</div>
                                    </td>
                                    <td className="tnum px-3 py-2.5">{money(t.base_price_paisa)}</td>
                                    <td className="tnum px-3 py-2.5">{num(t.quantity_sold)} / {t.quantity_total ? num(t.quantity_total) : '∞'}</td>
                                    <td className="px-3 py-2.5">
                                        <Badge tone={t.is_active ? 'success' : 'neutral'}>{t.is_active ? 'Active' : 'Inactive'}</Badge>
                                    </td>
                                    <td className="px-3 py-2.5 text-right">
                                        <div className="flex justify-end gap-1">
                                            {canManage && (
                                                <Button variant="ghost" size="sm" onClick={() => setEditing(t)}>Edit</Button>
                                            )}
                                            {canDelete && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="text-critical-fg"
                                                    disabled={t.quantity_sold > 0 || t.quantity_reserved > 0}
                                                    onClick={() => setConfirmDelete(t)}
                                                >
                                                    <Trash2 size={14} />
                                                </Button>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
                {data && data.length === 0 && <p className="px-3 py-6 text-[13px] text-text-muted">No ticket types configured yet.</p>}
            </div>

            {editing && <TicketTypeFormDialog existing={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
            {confirmDelete && (
                <ConfirmDialog
                    open
                    onClose={() => setConfirmDelete(null)}
                    onConfirm={async () => {
                        await deleteMutation.mutateAsync(confirmDelete.ulid);
                        setConfirmDelete(null);
                    }}
                    title={`Delete "${confirmDelete.name}"?`}
                    description="This cannot be undone. Ticket types with any sold or reserved capacity cannot be deleted."
                    confirmLabel="Delete ticket type"
                />
            )}
        </Card>
    );
}

/* --------------------------------------------------------------- Page */

export default function TicketsPage() {
    const [tab, setTab] = useState<'tickets' | 'types'>('tickets');

    return (
        <div className="space-y-6">
            <div>
                <h1 className="text-[26px] font-bold tracking-tight text-text">Tickets</h1>
                <p className="mt-1 text-[14px] text-text-muted">Issue, void, reissue, and manage ticket types.</p>
            </div>

            <div className="flex gap-1 rounded-xl border border-border bg-surface p-1 w-fit">
                {(['tickets', 'types'] as const).map((t) => (
                    <button
                        key={t}
                        onClick={() => setTab(t)}
                        className={cn(
                            'rounded-lg px-3.5 py-1.5 text-[13px] font-medium transition-colors',
                            tab === t ? 'bg-accent text-accent-fg' : 'text-text-muted hover:text-text',
                        )}
                    >
                        {t === 'tickets' ? 'Tickets' : 'Ticket types'}
                    </button>
                ))}
            </div>

            {tab === 'tickets' ? <TicketsTab /> : <TicketTypesTab />}
        </div>
    );
}
