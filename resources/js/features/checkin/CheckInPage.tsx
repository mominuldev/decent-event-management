import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';
import { Ban, Plus, RefreshCw, ShieldAlert, Smartphone, Trash2, UserPlus } from 'lucide-react';
import { Badge, Button, Card, CardHeader, Input, Label, Select, Skeleton, Textarea, type Tone } from '@/components/ui';
import { Dialog, ConfirmDialog } from '@/components/Dialog';
import { DataTable } from '@/components/DataTable';
import { useAuth } from '@/features/auth/AuthProvider';
import { useToast } from '@/components/Toast';
import { cn, num } from '@/lib/cn';
import { totalOf } from '@/lib/pagination';
import * as checkinApi from './api';
import { fetchTicketTypes } from '@/features/tickets/api';
import type {
    CheckIn,
    Device,
    Gate,
    GatePayload,
    Volunteer,
    VolunteerCreatePayload,
} from './types';

function titleCase(s: string) {
    return s.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function fmt(iso: string | null | undefined) {
    return iso ? new Date(iso).toLocaleString() : '—';
}

const resultTone: Record<string, Tone> = {
    admitted: 'success',
    manual_override: 'warning',
    duplicate: 'critical',
    invalid_format: 'critical',
    revoked: 'critical',
    unpaid: 'critical',
    expired: 'critical',
    wrong_gate: 'critical',
    wrong_session: 'critical',
    over_capacity: 'critical',
};

const deviceStatusTone: Record<string, Tone> = {
    active: 'success',
    pending: 'warning',
    revoked: 'critical',
};

/* ------------------------------------------------------------- Dashboard */

function LiveDashboardTab() {
    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['checkin-live-dashboard'],
        queryFn: checkinApi.fetchLiveDashboard,
        refetchInterval: 15000,
    });

    return (
        <div className="space-y-5">
            <Card>
                <CardHeader
                    title="Gates"
                    subtitle="Admitted count per active gate, updated every 15s"
                    action={<Button variant="outline" size="sm" onClick={() => void refetch()}><RefreshCw size={14} /> Refresh</Button>}
                />
                <div className="grid grid-cols-1 gap-3 px-5 pb-5 pt-4 sm:grid-cols-2 lg:grid-cols-3">
                    {isLoading &&
                        Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} className="h-24 w-full" />)}
                    {isError && (
                        <p className="col-span-full text-[13px] text-critical-fg">Failed to load the live dashboard.</p>
                    )}
                    {data?.gates.map((g) => (
                        <div key={g.ulid} className="rounded-xl border border-border bg-surface-2 p-4">
                            <div className="flex items-center justify-between">
                                <div className="font-semibold text-text">{g.name}</div>
                                <Badge tone={g.is_active ? 'success' : 'neutral'} size="sm">{g.code}</Badge>
                            </div>
                            <div className="mt-2 text-[26px] font-bold tnum text-text">{num(g.admitted_count)}</div>
                            <div className="text-[12px] text-text-faint">admitted</div>
                        </div>
                    ))}
                    {data && data.gates.length === 0 && (
                        <p className="col-span-full py-6 text-center text-[13px] text-text-muted">No active gates assigned to you.</p>
                    )}
                </div>
            </Card>

            <Card>
                <CardHeader title="Recent scans" subtitle="Last 10 check-ins" />
                <div className="overflow-x-auto px-2 pb-3 pt-3">
                    {isLoading && (
                        <div className="space-y-2 px-3">
                            {Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-9 w-full" />)}
                        </div>
                    )}
                    {data && (
                        <table className="w-full min-w-[560px] text-left text-[13px]">
                            <thead>
                                <tr className="border-y border-border text-[11px] uppercase tracking-wide text-text-faint">
                                    <th className="px-3 py-2.5 font-semibold">Time</th>
                                    <th className="px-3 py-2.5 font-semibold">Gate</th>
                                    <th className="px-3 py-2.5 font-semibold">Ticket</th>
                                    <th className="px-3 py-2.5 font-semibold">Result</th>
                                </tr>
                            </thead>
                            <tbody>
                                {data.recent_check_ins.map((c) => (
                                    <tr key={c.ulid} className="border-b border-border last:border-0">
                                        <td className="px-3 py-2.5 tnum text-text-muted">{fmt(c.scanned_at)}</td>
                                        <td className="px-3 py-2.5">{c.gate?.name ?? '—'}</td>
                                        <td className="px-3 py-2.5">{c.ticket?.ticket_number ?? '—'}</td>
                                        <td className="px-3 py-2.5"><Badge tone={resultTone[c.result] ?? 'neutral'} size="sm">{titleCase(c.result)}</Badge></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                    {data && data.recent_check_ins.length === 0 && (
                        <p className="px-3 py-6 text-[13px] text-text-muted">No scans yet.</p>
                    )}
                </div>
            </Card>
        </div>
    );
}

/* --------------------------------------------------------------- Scan log */

function CheckInDetail({ ulid, onClose }: { ulid: string; onClose: () => void }) {
    const { can } = useAuth();
    const { push } = useToast();
    const queryClient = useQueryClient();
    const [confirmResolve, setConfirmResolve] = useState(false);

    const { data, isLoading } = useQuery({
        queryKey: ['check-in', ulid],
        queryFn: () => checkinApi.fetchCheckIn(ulid),
    });

    const resolveMutation = useMutation({
        mutationFn: (note?: string) => checkinApi.resolveConflict(ulid, note),
        onSuccess: () => {
            push('success', 'Conflict resolved.');
            void queryClient.invalidateQueries({ queryKey: ['check-in', ulid] });
            void queryClient.invalidateQueries({ queryKey: ['check-ins'] });
        },
    });

    const canResolve = can('checkin.resolve_conflict') && data?.conflict_flag && !data?.conflict_resolved_at;

    return (
        <Dialog open onClose={onClose} title="Check-in" description={data?.ticket?.ticket_number ?? undefined} className="max-w-lg">
            {isLoading || !data ? (
                <div className="space-y-3">
                    {Array.from({ length: 5 }).map((_, i) => <Skeleton key={i} className="h-9 w-full" />)}
                </div>
            ) : (
                <div className="space-y-5">
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge tone={resultTone[data.result] ?? 'neutral'}>{titleCase(data.result)}</Badge>
                        {data.is_manual_override && <Badge tone="warning">Manual override</Badge>}
                        {data.conflict_flag && (
                            <Badge tone={data.conflict_resolved_at ? 'neutral' : 'critical'}>
                                {data.conflict_resolved_at ? 'Conflict resolved' : 'Unresolved conflict'}
                            </Badge>
                        )}
                    </div>

                    <div className="grid grid-cols-2 gap-3 text-[13px]">
                        <div><div className="text-text-faint">Gate</div><div className="font-medium text-text">{data.gate?.name ?? '—'}</div></div>
                        <div><div className="text-text-faint">Ticket</div><div className="font-medium text-text">{data.ticket?.ticket_number ?? '—'}</div></div>
                        <div><div className="text-text-faint">Attendee</div><div className="font-medium text-text">{data.attendee?.full_name ?? '—'}</div></div>
                        <div><div className="text-text-faint">Registration</div><div className="font-medium text-text">{data.registration?.registration_number ?? '—'}</div></div>
                        <div><div className="text-text-faint">Admitted count</div><div className="tnum font-medium text-text">{data.admitted_count}</div></div>
                        <div><div className="text-text-faint">Scan mode</div><div className="font-medium text-text">{titleCase(data.scan_mode)}</div></div>
                        <div><div className="text-text-faint">Scanned at</div><div className="font-medium text-text">{fmt(data.scanned_at)}</div></div>
                        <div><div className="text-text-faint">Synced at</div><div className="font-medium text-text">{fmt(data.synced_at)}</div></div>
                        <div><div className="text-text-faint">Device</div><div className="font-medium text-text">{data.device?.device_name ?? '—'}</div></div>
                        <div><div className="text-text-faint">Scanned by</div><div className="font-medium text-text">{data.scanned_by ?? '—'}</div></div>
                        {data.rejection_detail && (
                            <div className="col-span-2"><div className="text-text-faint">Rejection detail</div><div className="font-medium text-critical-fg">{data.rejection_detail}</div></div>
                        )}
                        {data.is_manual_override && (
                            <div className="col-span-2"><div className="text-text-faint">Override reason</div><div className="font-medium text-text">{data.override_reason ?? '—'} {data.override_by && <span className="text-text-faint">— {data.override_by}</span>}</div></div>
                        )}
                        {data.conflict_resolved_at && (
                            <div className="col-span-2"><div className="text-text-faint">Conflict resolved</div><div className="font-medium text-text">{fmt(data.conflict_resolved_at)} {data.conflict_resolved_by && <span className="text-text-faint">— {data.conflict_resolved_by}</span>}</div></div>
                        )}
                    </div>

                    <div className="flex flex-wrap justify-end gap-2 border-t border-border pt-4">
                        <Button variant="outline" size="sm" onClick={onClose}>Close</Button>
                        {canResolve && (
                            <Button variant="primary" size="sm" onClick={() => setConfirmResolve(true)}>
                                <ShieldAlert size={14} /> Resolve conflict
                            </Button>
                        )}
                    </div>
                </div>
            )}

            <ConfirmDialog
                open={confirmResolve}
                onClose={() => setConfirmResolve(false)}
                onConfirm={async (note) => { await resolveMutation.mutateAsync(note); }}
                title="Resolve this conflict?"
                description="Marks the offline-sync conflict on this check-in as resolved."
                confirmLabel="Resolve"
                tone="primary"
                reasonLabel="Resolution note (optional)"
                minReasonLength={0}
            />
        </Dialog>
    );
}

function ManualOverrideDialog({ gates, onClose }: { gates: Gate[]; onClose: () => void }) {
    const { push } = useToast();
    const queryClient = useQueryClient();
    const [ticketUlid, setTicketUlid] = useState('');
    const [gateUlid, setGateUlid] = useState('');
    const [partySize, setPartySize] = useState(1);
    const [reason, setReason] = useState('');
    const [error, setError] = useState<string | null>(null);

    const mutation = useMutation({
        mutationFn: () => checkinApi.manualOverride({ ticket_ulid: ticketUlid.trim(), gate_ulid: gateUlid, party_size: partySize, reason: reason.trim() }),
        onSuccess: () => {
            push('success', 'Manual admission recorded.');
            void queryClient.invalidateQueries({ queryKey: ['check-ins'] });
            void queryClient.invalidateQueries({ queryKey: ['checkin-live-dashboard'] });
            onClose();
        },
        onError: (e: Error) => setError(e.message),
    });

    const canSubmit = ticketUlid.trim().length > 0 && gateUlid.length > 0 && reason.trim().length >= 3;

    return (
        <Dialog open onClose={onClose} title="Manual admission override" description="Admits a ticket without a QR scan. Reason is logged to the activity trail.">
            <div className="space-y-4">
                <div>
                    <Label htmlFor="mo_ticket">Ticket ULID</Label>
                    <Input id="mo_ticket" value={ticketUlid} onChange={(e) => setTicketUlid(e.target.value)} placeholder="e.g. 01J..." />
                </div>
                <div>
                    <Label htmlFor="mo_gate">Gate</Label>
                    <Select id="mo_gate" value={gateUlid} onChange={(e) => setGateUlid(e.target.value)}>
                        <option value="">Select a gate…</option>
                        {gates.map((g) => <option key={g.ulid} value={g.ulid}>{g.name} ({g.code})</option>)}
                    </Select>
                </div>
                <div>
                    <Label htmlFor="mo_party">Party size</Label>
                    <Input id="mo_party" type="number" min={1} max={20} value={partySize} onChange={(e) => setPartySize(Number(e.target.value))} />
                </div>
                <div>
                    <Label htmlFor="mo_reason">Reason</Label>
                    <Textarea id="mo_reason" rows={3} value={reason} onChange={(e) => setReason(e.target.value)} placeholder="e.g. Scanner offline, verified holder identity manually" />
                </div>
                {error && <p className="text-[13px] text-critical-fg">{error}</p>}
                <div className="flex justify-end gap-2 border-t border-border pt-4">
                    <Button variant="outline" size="sm" onClick={onClose} disabled={mutation.isPending}>Cancel</Button>
                    <Button size="sm" disabled={!canSubmit || mutation.isPending} onClick={() => void mutation.mutateAsync()}>
                        {mutation.isPending ? 'Admitting…' : 'Admit'}
                    </Button>
                </div>
            </div>
        </Dialog>
    );
}

const checkInColumns: ColumnDef<CheckIn, unknown>[] = [
    { id: 'scanned_at', header: 'Time', cell: (ctx) => <span className="tnum">{fmt(ctx.row.original.scanned_at)}</span> },
    { id: 'gate', header: 'Gate', cell: (ctx) => ctx.row.original.gate?.name ?? '—' },
    { id: 'ticket', header: 'Ticket', cell: (ctx) => ctx.row.original.ticket?.ticket_number ?? '—' },
    {
        accessorKey: 'result',
        header: 'Result',
        cell: (ctx) => <Badge tone={resultTone[ctx.row.original.result] ?? 'neutral'}>{titleCase(ctx.row.original.result)}</Badge>,
    },
    {
        id: 'flags',
        header: '',
        cell: (ctx) => (
            <div className="flex gap-1">
                {ctx.row.original.is_manual_override && <Badge tone="warning" size="sm">Override</Badge>}
                {ctx.row.original.conflict_flag && (
                    <Badge tone={ctx.row.original.conflict_resolved_at ? 'neutral' : 'critical'} size="sm">
                        {ctx.row.original.conflict_resolved_at ? 'Resolved' : 'Conflict'}
                    </Badge>
                )}
            </div>
        ),
    },
];

function ScanLogTab() {
    const { can } = useAuth();
    const [gateUlid, setGateUlid] = useState('');
    const [result, setResult] = useState('');
    const [dateFrom, setDateFrom] = useState('');
    const [dateTo, setDateTo] = useState('');
    const [pageIndex, setPageIndex] = useState(0);
    const [selected, setSelected] = useState<string | null>(null);
    const [overriding, setOverriding] = useState(false);
    const pageSize = 20;

    const { data: gates } = useQuery({ queryKey: ['gates-list'], queryFn: () => checkinApi.fetchGates() });

    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['check-ins', gateUlid, result, dateFrom, dateTo, pageIndex],
        queryFn: () => checkinApi.fetchCheckIns({ gate_ulid: gateUlid, result, date_from: dateFrom, date_to: dateTo, page: pageIndex + 1, per_page: pageSize }),
    });

    const canOverride = can('checkin.manual_override');

    return (
        <Card>
            <CardHeader
                title="Scan log"
                subtitle="Every gate scan and its outcome"
                action={canOverride && (
                    <Button size="sm" onClick={() => setOverriding(true)}>
                        <Plus size={14} /> Manual override
                    </Button>
                )}
            />
            <div className="flex flex-wrap items-end gap-3 px-5 pb-4 pt-4">
                <div className="w-48">
                    <Label htmlFor="log_gate">Gate</Label>
                    <Select id="log_gate" value={gateUlid} onChange={(e) => { setGateUlid(e.target.value); setPageIndex(0); }}>
                        <option value="">All gates</option>
                        {gates?.map((g) => <option key={g.ulid} value={g.ulid}>{g.name}</option>)}
                    </Select>
                </div>
                <div className="w-44">
                    <Label htmlFor="log_result">Result</Label>
                    <Select id="log_result" value={result} onChange={(e) => { setResult(e.target.value); setPageIndex(0); }}>
                        <option value="">All results</option>
                        {Object.keys(resultTone).map((r) => <option key={r} value={r}>{titleCase(r)}</option>)}
                    </Select>
                </div>
                <div>
                    <Label htmlFor="log_from">From</Label>
                    <Input id="log_from" type="date" value={dateFrom} onChange={(e) => { setDateFrom(e.target.value); setPageIndex(0); }} />
                </div>
                <div>
                    <Label htmlFor="log_to">To</Label>
                    <Input id="log_to" type="date" value={dateTo} onChange={(e) => { setDateTo(e.target.value); setPageIndex(0); }} />
                </div>
            </div>

            <DataTable
                columns={checkInColumns}
                data={data?.data ?? []}
                getRowId={(r) => r.ulid}
                isLoading={isLoading}
                isError={isError}
                onRetry={() => void refetch()}
                onRowClick={(row) => setSelected(row.ulid)}
                emptyTitle="No check-ins found"
                emptyDescription="Try adjusting your filters."
                pageIndex={pageIndex}
                pageSize={pageSize}
                totalRows={data ? totalOf(data) : 0}
                onPageChange={setPageIndex}
            />

            {selected && <CheckInDetail ulid={selected} onClose={() => setSelected(null)} />}
            {overriding && <ManualOverrideDialog gates={gates ?? []} onClose={() => setOverriding(false)} />}
        </Card>
    );
}

/* -------------------------------------------------------------------- Gates */

function emptyGateForm(): GatePayload {
    return { code: '', name: '', event_session_ulid: '', allowed_ticket_type_ulids: [], location_note: '', is_active: true };
}

function gateToForm(g: Gate): GatePayload {
    return {
        code: g.code,
        name: g.name,
        event_session_ulid: g.event_session?.ulid ?? '',
        allowed_ticket_type_ulids: [],
        location_note: g.location_note ?? '',
        is_active: g.is_active,
    };
}

function GateFormDialog({ existing, onClose }: { existing: Gate | null; onClose: () => void }) {
    const { push } = useToast();
    const queryClient = useQueryClient();
    const [form, setForm] = useState<GatePayload>(existing ? gateToForm(existing) : emptyGateForm());
    const [error, setError] = useState<string | null>(null);

    const { data: ticketTypes } = useQuery({ queryKey: ['ticket-types'], queryFn: fetchTicketTypes });

    const saveMutation = useMutation({
        mutationFn: () => (existing ? checkinApi.updateGate(existing.ulid, form) : checkinApi.createGate(form)),
        onSuccess: () => {
            push('success', existing ? 'Gate updated.' : 'Gate created.');
            void queryClient.invalidateQueries({ queryKey: ['gates'] });
            void queryClient.invalidateQueries({ queryKey: ['gates-list'] });
            onClose();
        },
        onError: (e: Error) => setError(e.message),
    });

    function toggleTicketType(ulid: string) {
        const current = form.allowed_ticket_type_ulids ?? [];
        setForm({
            ...form,
            allowed_ticket_type_ulids: current.includes(ulid) ? current.filter((u) => u !== ulid) : [...current, ulid],
        });
    }

    return (
        <Dialog open onClose={onClose} title={existing ? `Edit ${existing.name}` : 'New gate'} className="max-w-lg">
            <div className="max-h-[65vh] space-y-4 overflow-y-auto pr-1">
                <div className="grid grid-cols-2 gap-3">
                    <div>
                        <Label htmlFor="g_code">Code</Label>
                        <Input id="g_code" value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })} />
                    </div>
                    <div>
                        <Label htmlFor="g_name">Name</Label>
                        <Input id="g_name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
                    </div>
                </div>
                <div>
                    <Label htmlFor="g_session">Event session ULID (optional)</Label>
                    <Input id="g_session" value={form.event_session_ulid ?? ''} onChange={(e) => setForm({ ...form, event_session_ulid: e.target.value })} placeholder="Leave blank for all sessions" />
                </div>
                <div>
                    <Label htmlFor="g_note">Location note</Label>
                    <Input id="g_note" value={form.location_note ?? ''} onChange={(e) => setForm({ ...form, location_note: e.target.value })} />
                </div>
                {ticketTypes && ticketTypes.length > 0 && (
                    <div>
                        <Label>Allowed ticket types (none selected = all allowed)</Label>
                        <div className="grid grid-cols-2 gap-2 text-[13px]">
                            {ticketTypes.map((t) => (
                                <label key={t.ulid} className="flex items-center gap-2 text-text">
                                    <input type="checkbox" checked={(form.allowed_ticket_type_ulids ?? []).includes(t.ulid)} onChange={() => toggleTicketType(t.ulid)} />
                                    {t.name}
                                </label>
                            ))}
                        </div>
                    </div>
                )}
                <label className="flex items-center gap-2 text-[13px] text-text">
                    <input type="checkbox" checked={Boolean(form.is_active)} onChange={(e) => setForm({ ...form, is_active: e.target.checked })} />
                    Active
                </label>
                {error && <p className="text-[13px] text-critical-fg">{error}</p>}
            </div>
            <div className="flex justify-end gap-2 border-t border-border pt-4">
                <Button variant="outline" size="sm" onClick={onClose} disabled={saveMutation.isPending}>Cancel</Button>
                <Button size="sm" disabled={saveMutation.isPending || !form.code || !form.name} onClick={() => void saveMutation.mutateAsync()}>
                    {saveMutation.isPending ? 'Saving…' : existing ? 'Save changes' : 'Create gate'}
                </Button>
            </div>
        </Dialog>
    );
}

function GatesTab() {
    const { can } = useAuth();
    const { push } = useToast();
    const queryClient = useQueryClient();
    const [editing, setEditing] = useState<Gate | null | 'new'>(null);
    const [confirmDelete, setConfirmDelete] = useState<Gate | null>(null);

    const { data, isLoading, isError, refetch } = useQuery({ queryKey: ['gates'], queryFn: () => checkinApi.fetchGates() });

    const deleteMutation = useMutation({
        mutationFn: (ulid: string) => checkinApi.deleteGate(ulid),
        onSuccess: () => {
            push('success', 'Gate deleted.');
            void queryClient.invalidateQueries({ queryKey: ['gates'] });
            void queryClient.invalidateQueries({ queryKey: ['gates-list'] });
        },
    });

    const canManage = can('gate.manage');
    const canDelete = can('gate.delete');

    return (
        <Card>
            <CardHeader
                title="Gates"
                subtitle="Physical admission points and their ticket-type restrictions"
                action={canManage && (
                    <Button size="sm" onClick={() => setEditing('new')}>
                        <Plus size={14} /> New gate
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
                        <span>Failed to load gates.</span>
                        <Button variant="outline" size="sm" onClick={() => void refetch()}>Retry</Button>
                    </div>
                )}
                {data && (
                    <table className="w-full min-w-[640px] text-left text-[13px]">
                        <thead>
                            <tr className="border-y border-border text-[11px] uppercase tracking-wide text-text-faint">
                                <th className="px-3 py-2.5 font-semibold">Gate</th>
                                <th className="px-3 py-2.5 font-semibold">Session</th>
                                <th className="px-3 py-2.5 font-semibold">Admitted</th>
                                <th className="px-3 py-2.5 font-semibold">Status</th>
                                <th className="px-3 py-2.5 font-semibold" />
                            </tr>
                        </thead>
                        <tbody>
                            {data.map((g) => (
                                <tr key={g.ulid} className="border-b border-border last:border-0 hover:bg-table-row-hover">
                                    <td className="px-3 py-2.5">
                                        <div className="font-medium text-text">{g.name}</div>
                                        <div className="text-[11.5px] text-text-faint">{g.code}{g.location_note ? ` · ${g.location_note}` : ''}</div>
                                    </td>
                                    <td className="px-3 py-2.5">{g.event_session?.name ?? 'All sessions'}</td>
                                    <td className="tnum px-3 py-2.5">{num(g.admitted_count)}</td>
                                    <td className="px-3 py-2.5"><Badge tone={g.is_active ? 'success' : 'neutral'}>{g.is_active ? 'Active' : 'Inactive'}</Badge></td>
                                    <td className="px-3 py-2.5 text-right">
                                        <div className="flex justify-end gap-1">
                                            {canManage && <Button variant="ghost" size="sm" onClick={() => setEditing(g)}>Edit</Button>}
                                            {canDelete && (
                                                <Button variant="ghost" size="sm" className="text-critical-fg" onClick={() => setConfirmDelete(g)}>
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
                {data && data.length === 0 && <p className="px-3 py-6 text-[13px] text-text-muted">No gates configured yet.</p>}
            </div>

            {editing && <GateFormDialog existing={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
            {confirmDelete && (
                <ConfirmDialog
                    open
                    onClose={() => setConfirmDelete(null)}
                    onConfirm={async () => { await deleteMutation.mutateAsync(confirmDelete.ulid); setConfirmDelete(null); }}
                    title={`Delete "${confirmDelete.name}"?`}
                    description="This cannot be undone. Gates with any recorded check-ins cannot be deleted."
                    confirmLabel="Delete gate"
                />
            )}
        </Card>
    );
}

/* --------------------------------------------------------------- Volunteers */

function emptyVolunteerForm(): VolunteerCreatePayload {
    return { name: '', email: '', phone: '', password: '', volunteer_code: '', team: '', shift_starts_at: '', shift_ends_at: '' };
}

function VolunteerCreateDialog({ onClose }: { onClose: () => void }) {
    const { push } = useToast();
    const queryClient = useQueryClient();
    const [form, setForm] = useState<VolunteerCreatePayload>(emptyVolunteerForm());
    const [error, setError] = useState<string | null>(null);

    const mutation = useMutation({
        mutationFn: () => checkinApi.createVolunteer(form),
        onSuccess: () => {
            push('success', 'Volunteer created.');
            void queryClient.invalidateQueries({ queryKey: ['volunteers'] });
            onClose();
        },
        onError: (e: Error) => setError(e.message),
    });

    const canSubmit = form.name && form.email && form.password.length >= 8 && form.volunteer_code;

    return (
        <Dialog open onClose={onClose} title="New volunteer" className="max-w-lg">
            <div className="max-h-[65vh] space-y-4 overflow-y-auto pr-1">
                <div className="grid grid-cols-2 gap-3">
                    <div>
                        <Label htmlFor="v_name">Name</Label>
                        <Input id="v_name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
                    </div>
                    <div>
                        <Label htmlFor="v_code">Volunteer code</Label>
                        <Input id="v_code" value={form.volunteer_code} onChange={(e) => setForm({ ...form, volunteer_code: e.target.value })} />
                    </div>
                    <div>
                        <Label htmlFor="v_email">Email</Label>
                        <Input id="v_email" type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
                    </div>
                    <div>
                        <Label htmlFor="v_phone">Phone</Label>
                        <Input id="v_phone" value={form.phone ?? ''} onChange={(e) => setForm({ ...form, phone: e.target.value })} />
                    </div>
                    <div>
                        <Label htmlFor="v_password">Password</Label>
                        <Input id="v_password" type="password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} />
                    </div>
                    <div>
                        <Label htmlFor="v_team">Team</Label>
                        <Input id="v_team" value={form.team ?? ''} onChange={(e) => setForm({ ...form, team: e.target.value })} />
                    </div>
                    <div>
                        <Label htmlFor="v_shift_start">Shift starts</Label>
                        <Input id="v_shift_start" type="datetime-local" value={form.shift_starts_at ?? ''} onChange={(e) => setForm({ ...form, shift_starts_at: e.target.value })} />
                    </div>
                    <div>
                        <Label htmlFor="v_shift_end">Shift ends</Label>
                        <Input id="v_shift_end" type="datetime-local" value={form.shift_ends_at ?? ''} onChange={(e) => setForm({ ...form, shift_ends_at: e.target.value })} />
                    </div>
                </div>
                {error && <p className="text-[13px] text-critical-fg">{error}</p>}
            </div>
            <div className="flex justify-end gap-2 border-t border-border pt-4">
                <Button variant="outline" size="sm" onClick={onClose} disabled={mutation.isPending}>Cancel</Button>
                <Button size="sm" disabled={!canSubmit || mutation.isPending} onClick={() => void mutation.mutateAsync()}>
                    {mutation.isPending ? 'Creating…' : 'Create volunteer'}
                </Button>
            </div>
        </Dialog>
    );
}

function AssignGateDialog({ volunteer, gates, onClose }: { volunteer: Volunteer; gates: Gate[]; onClose: () => void }) {
    const { push } = useToast();
    const queryClient = useQueryClient();
    const [gateUlid, setGateUlid] = useState('');
    const [error, setError] = useState<string | null>(null);

    const mutation = useMutation({
        mutationFn: () => checkinApi.assignVolunteerGate(volunteer.ulid, gateUlid),
        onSuccess: () => {
            push('success', 'Gate assigned.');
            void queryClient.invalidateQueries({ queryKey: ['volunteers'] });
            onClose();
        },
        onError: (e: Error) => setError(e.message),
    });

    return (
        <Dialog open onClose={onClose} title={`Assign a gate to ${volunteer.volunteer_code}`}>
            <div className="space-y-4">
                <div>
                    <Label htmlFor="ag_gate">Gate</Label>
                    <Select id="ag_gate" value={gateUlid} onChange={(e) => setGateUlid(e.target.value)}>
                        <option value="">Select a gate…</option>
                        {gates.map((g) => <option key={g.ulid} value={g.ulid}>{g.name} ({g.code})</option>)}
                    </Select>
                </div>
                {error && <p className="text-[13px] text-critical-fg">{error}</p>}
                <div className="flex justify-end gap-2">
                    <Button variant="outline" size="sm" onClick={onClose} disabled={mutation.isPending}>Cancel</Button>
                    <Button size="sm" disabled={!gateUlid || mutation.isPending} onClick={() => void mutation.mutateAsync()}>
                        {mutation.isPending ? 'Assigning…' : 'Assign'}
                    </Button>
                </div>
            </div>
        </Dialog>
    );
}

function volunteerColumnsFor(): ColumnDef<Volunteer, unknown>[] {
    return [
        {
            id: 'volunteer',
            header: 'Volunteer',
            cell: (ctx) => (
                <div>
                    <div className="font-medium text-text">{ctx.row.original.user?.name ?? ctx.row.original.volunteer_code}</div>
                    <div className="text-[11.5px] text-text-faint">{ctx.row.original.volunteer_code} · {ctx.row.original.user?.email ?? '—'}</div>
                </div>
            ),
        },
        { id: 'team', header: 'Team', cell: (ctx) => ctx.row.original.team ?? '—' },
        {
            id: 'gates',
            header: 'Gates',
            cell: (ctx) => (ctx.row.original.gate_assignments ?? []).map((a) => a.gate?.code).filter(Boolean).join(', ') || '—',
        },
        { id: 'scans', header: 'Scans', cell: (ctx) => <span className="tnum">{num(ctx.row.original.total_scans)}</span> },
        {
            accessorKey: 'is_active',
            header: 'Status',
            cell: (ctx) => <Badge tone={ctx.row.original.is_active ? 'success' : 'neutral'}>{ctx.row.original.is_active ? 'Active' : 'Revoked'}</Badge>,
        },
    ];
}

function VolunteersTab() {
    const { can } = useAuth();
    const { push } = useToast();
    const queryClient = useQueryClient();
    const [isActive, setIsActive] = useState('');
    const [pageIndex, setPageIndex] = useState(0);
    const [creating, setCreating] = useState(false);
    const [assigning, setAssigning] = useState<Volunteer | null>(null);
    const [confirmRevoke, setConfirmRevoke] = useState<Volunteer | null>(null);
    const pageSize = 20;

    const { data: gates } = useQuery({ queryKey: ['gates-list'], queryFn: () => checkinApi.fetchGates() });

    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['volunteers', isActive, pageIndex],
        queryFn: () => checkinApi.fetchVolunteers({ is_active: isActive === '' ? undefined : isActive === '1', page: pageIndex + 1, per_page: pageSize }),
    });

    const revokeMutation = useMutation({
        mutationFn: (v: { ulid: string; reason?: string }) => checkinApi.revokeVolunteerAccess(v.ulid, v.reason),
        onSuccess: () => {
            push('success', 'Volunteer access revoked.');
            void queryClient.invalidateQueries({ queryKey: ['volunteers'] });
        },
    });

    const canCreate = can('volunteer.create');
    const canAssign = can('volunteer.assign_gate');
    const canRevoke = can('volunteer.revoke_access');

    const columns: ColumnDef<Volunteer, unknown>[] = [
        ...volunteerColumnsFor(),
        {
            id: 'actions',
            header: '',
            cell: (ctx) => (
                <div className="flex justify-end gap-1" onClick={(e) => e.stopPropagation()}>
                    {canAssign && ctx.row.original.is_active && (
                        <Button variant="ghost" size="sm" onClick={() => setAssigning(ctx.row.original)}>Assign gate</Button>
                    )}
                    {canRevoke && ctx.row.original.is_active && (
                        <Button variant="ghost" size="sm" className="text-critical-fg" onClick={() => setConfirmRevoke(ctx.row.original)}>
                            <Ban size={14} />
                        </Button>
                    )}
                </div>
            ),
        },
    ];

    return (
        <Card>
            <CardHeader
                title="Volunteers"
                subtitle="Scanner-guard accounts and their gate assignments"
                action={canCreate && (
                    <Button size="sm" onClick={() => setCreating(true)}>
                        <UserPlus size={14} /> New volunteer
                    </Button>
                )}
            />
            <div className="flex flex-wrap items-end gap-3 px-5 pb-4 pt-4">
                <div className="w-44">
                    <Label htmlFor="vol_active">Status</Label>
                    <Select id="vol_active" value={isActive} onChange={(e) => { setIsActive(e.target.value); setPageIndex(0); }}>
                        <option value="">All</option>
                        <option value="1">Active</option>
                        <option value="0">Revoked</option>
                    </Select>
                </div>
            </div>

            <DataTable
                columns={columns}
                data={data?.data ?? []}
                getRowId={(r) => r.ulid}
                isLoading={isLoading}
                isError={isError}
                onRetry={() => void refetch()}
                emptyTitle="No volunteers found"
                pageIndex={pageIndex}
                pageSize={pageSize}
                totalRows={data ? totalOf(data) : 0}
                onPageChange={setPageIndex}
            />

            {creating && <VolunteerCreateDialog onClose={() => setCreating(false)} />}
            {assigning && <AssignGateDialog volunteer={assigning} gates={gates ?? []} onClose={() => setAssigning(null)} />}
            {confirmRevoke && (
                <ConfirmDialog
                    open
                    onClose={() => setConfirmRevoke(null)}
                    onConfirm={async (reason) => { await revokeMutation.mutateAsync({ ulid: confirmRevoke.ulid, reason }); }}
                    title={`Revoke access for ${confirmRevoke.volunteer_code}?`}
                    description="The volunteer will no longer be able to sign in or sync scans. This does not revoke their enrolled devices — do that separately from the Devices tab."
                    confirmLabel="Revoke access"
                    reasonLabel="Reason (optional)"
                    minReasonLength={0}
                />
            )}
        </Card>
    );
}

/* ------------------------------------------------------------------ Devices */

function DeviceSyncDialog({ device, onClose }: { device: Device; onClose: () => void }) {
    const { data, isLoading } = useQuery({
        queryKey: ['device-sync-status', device.ulid],
        queryFn: () => checkinApi.fetchDeviceSyncStatus(device.ulid),
    });

    return (
        <Dialog open onClose={onClose} title={device.device_name} description={device.device_code}>
            {isLoading || !data ? (
                <div className="space-y-3">
                    {Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-8 w-full" />)}
                </div>
            ) : (
                <div className="grid grid-cols-2 gap-3 text-[13px]">
                    <div><div className="text-text-faint">Status</div><Badge tone={deviceStatusTone[data.status] ?? 'neutral'}>{titleCase(data.status)}</Badge></div>
                    <div><div className="text-text-faint">Manifest version</div><div className="tnum font-medium text-text">{data.manifest_version}</div></div>
                    <div><div className="text-text-faint">Last sync</div><div className="font-medium text-text">{fmt(data.last_sync_at)}</div></div>
                    <div><div className="text-text-faint">Last seen</div><div className="font-medium text-text">{fmt(data.last_seen_at)}</div></div>
                    <div><div className="text-text-faint">Pending scans</div><div className="tnum font-medium text-text">{num(data.pending_scan_count)}</div></div>
                    <div><div className="text-text-faint">Total scans</div><div className="tnum font-medium text-text">{num(data.total_scans)}</div></div>
                    <div><div className="text-text-faint">Battery</div><div className="tnum font-medium text-text">{data.battery_level != null ? `${data.battery_level}%` : '—'}</div></div>
                </div>
            )}
            <div className="flex justify-end border-t border-border pt-4">
                <Button variant="outline" size="sm" onClick={onClose}>Close</Button>
            </div>
        </Dialog>
    );
}

const deviceColumns = (opts: { canRevoke: boolean; onRevoke: (d: Device) => void; onSync: (d: Device) => void }): ColumnDef<Device, unknown>[] => [
    {
        id: 'device',
        header: 'Device',
        cell: (ctx) => (
            <div>
                <div className="font-medium text-text">{ctx.row.original.device_name}</div>
                <div className="text-[11.5px] text-text-faint">{ctx.row.original.device_code} · {ctx.row.original.platform}</div>
            </div>
        ),
    },
    { id: 'volunteer', header: 'Volunteer', cell: (ctx) => ctx.row.original.volunteer?.name ?? ctx.row.original.volunteer?.volunteer_code ?? '—' },
    { id: 'last_seen', header: 'Last seen', cell: (ctx) => <span className="tnum">{fmt(ctx.row.original.last_seen_at)}</span> },
    { id: 'pending', header: 'Pending', cell: (ctx) => <span className="tnum">{num(ctx.row.original.pending_scan_count)}</span> },
    {
        accessorKey: 'status',
        header: 'Status',
        cell: (ctx) => <Badge tone={deviceStatusTone[ctx.row.original.status] ?? 'neutral'}>{titleCase(ctx.row.original.status)}</Badge>,
    },
    {
        id: 'actions',
        header: '',
        cell: (ctx) => (
            <div className="flex justify-end gap-1" onClick={(e) => e.stopPropagation()}>
                <Button variant="ghost" size="sm" onClick={() => opts.onSync(ctx.row.original)}><Smartphone size={14} /></Button>
                {opts.canRevoke && ctx.row.original.status === 'active' && (
                    <Button variant="ghost" size="sm" className="text-critical-fg" onClick={() => opts.onRevoke(ctx.row.original)}>
                        <Ban size={14} />
                    </Button>
                )}
            </div>
        ),
    },
];

function DevicesTab() {
    const { can } = useAuth();
    const { push } = useToast();
    const queryClient = useQueryClient();
    const [status, setStatus] = useState('');
    const [pageIndex, setPageIndex] = useState(0);
    const [syncing, setSyncing] = useState<Device | null>(null);
    const [confirmRevoke, setConfirmRevoke] = useState<Device | null>(null);
    const pageSize = 20;

    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['devices', status, pageIndex],
        queryFn: () => checkinApi.fetchDevices({ status, page: pageIndex + 1, per_page: pageSize }),
    });

    const revokeMutation = useMutation({
        mutationFn: (ulid: string) => checkinApi.revokeDevice(ulid),
        onSuccess: () => {
            push('success', 'Device revoked.');
            void queryClient.invalidateQueries({ queryKey: ['devices'] });
        },
    });

    const canRevoke = can('device.revoke');

    return (
        <Card>
            <CardHeader title="Devices" subtitle="Enrolled scanner devices and offline-sync status" />
            <div className="flex flex-wrap items-end gap-3 px-5 pb-4 pt-4">
                <div className="w-44">
                    <Label htmlFor="dev_status">Status</Label>
                    <Select id="dev_status" value={status} onChange={(e) => { setStatus(e.target.value); setPageIndex(0); }}>
                        <option value="">All</option>
                        <option value="active">Active</option>
                        <option value="pending">Pending</option>
                        <option value="revoked">Revoked</option>
                    </Select>
                </div>
            </div>

            <DataTable
                columns={deviceColumns({ canRevoke, onRevoke: setConfirmRevoke, onSync: setSyncing })}
                data={data?.data ?? []}
                getRowId={(r) => r.ulid}
                isLoading={isLoading}
                isError={isError}
                onRetry={() => void refetch()}
                emptyTitle="No devices found"
                pageIndex={pageIndex}
                pageSize={pageSize}
                totalRows={data ? totalOf(data) : 0}
                onPageChange={setPageIndex}
            />

            {syncing && <DeviceSyncDialog device={syncing} onClose={() => setSyncing(null)} />}
            {confirmRevoke && (
                <ConfirmDialog
                    open
                    onClose={() => setConfirmRevoke(null)}
                    onConfirm={async () => { await revokeMutation.mutateAsync(confirmRevoke.ulid); }}
                    title={`Revoke "${confirmRevoke.device_name}"?`}
                    description="The device's Sanctum token is invalidated immediately — it can no longer sync scans or pull the admission manifest."
                    confirmLabel="Revoke device"
                />
            )}
        </Card>
    );
}

/* --------------------------------------------------------------------- Page */

const TABS = [
    { key: 'dashboard', label: 'Live dashboard', permission: 'checkin.view_live_dashboard' },
    { key: 'log', label: 'Scan log', permission: 'checkin.view_any' },
    { key: 'gates', label: 'Gates', permission: 'gate.view_any' },
    { key: 'volunteers', label: 'Volunteers', permission: 'volunteer.view_any' },
    { key: 'devices', label: 'Devices', permission: 'device.view_any' },
] as const;

export default function CheckInPage() {
    const { can } = useAuth();
    const visibleTabs = TABS.filter((t) => can(t.permission));
    const [tab, setTab] = useState<(typeof TABS)[number]['key']>(visibleTabs[0]?.key ?? 'dashboard');

    return (
        <div className="space-y-6">
            <div>
                <h1 className="text-[26px] font-bold tracking-tight text-text">Check-in</h1>
                <p className="mt-1 text-[14px] text-text-muted">Live gate monitor, scan log, override requests, and device/volunteer status.</p>
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

            {tab === 'dashboard' && <LiveDashboardTab />}
            {tab === 'log' && <ScanLogTab />}
            {tab === 'gates' && <GatesTab />}
            {tab === 'volunteers' && <VolunteersTab />}
            {tab === 'devices' && <DevicesTab />}

            {visibleTabs.length === 0 && <p className="text-[13px] text-text-muted">You don't have permission to view check-in data.</p>}
        </div>
    );
}
