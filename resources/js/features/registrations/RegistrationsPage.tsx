import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';
import { Check, Search, Trash2, X } from 'lucide-react';
import { Badge, Button, Card, CardHeader, Input, Label, Select, Skeleton, Textarea, type Tone } from '@/components/ui';
import { Dialog, ConfirmDialog } from '@/components/Dialog';
import { DataTable } from '@/components/DataTable';
import { useAuth } from '@/features/auth/AuthProvider';
import { useToast } from '@/components/Toast';
import { cn, money } from '@/lib/cn';
import { totalOf } from '@/lib/pagination';
import * as registrationsApi from './api';
import { EDITABLE_STATUSES, type Registration, type RegistrationStatus, type UpdateRegistrationPayload } from './types';

const statusTone: Record<RegistrationStatus, Tone> = {
    draft: 'neutral',
    pending_payment: 'warning',
    pending_approval: 'warning',
    approved: 'success',
    confirmed: 'success',
    rejected: 'critical',
    cancelled: 'critical',
};

function titleCase(s: string) {
    return s.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

const TIMELINE_STEPS: RegistrationStatus[] = ['draft', 'pending_payment', 'pending_approval', 'approved', 'confirmed'];

function StatusTimeline({ status }: { status: RegistrationStatus }) {
    const isTerminalNegative = status === 'rejected' || status === 'cancelled';
    const currentIndex = TIMELINE_STEPS.indexOf(status);

    return (
        <div className="flex items-center gap-1.5">
            {TIMELINE_STEPS.map((step, i) => {
                const reached = !isTerminalNegative && currentIndex >= i;
                const isCurrent = !isTerminalNegative && currentIndex === i;
                return (
                    <div key={step} className="flex flex-1 items-center gap-1.5">
                        <div
                            className={cn(
                                'grid h-6 w-6 shrink-0 place-items-center rounded-full text-[11px] font-semibold',
                                reached ? 'bg-accent text-accent-fg' : 'bg-surface-2 text-text-faint',
                                isCurrent && 'ring-2 ring-accent/30',
                            )}
                        >
                            {reached ? <Check size={12} /> : i + 1}
                        </div>
                        <span className={cn('shrink-0 text-[11px]', reached ? 'font-medium text-text' : 'text-text-faint')}>
                            {titleCase(step)}
                        </span>
                        {i < TIMELINE_STEPS.length - 1 && <div className={cn('h-px flex-1', reached ? 'bg-accent' : 'bg-border')} />}
                    </div>
                );
            })}
            {isTerminalNegative && (
                <div className="flex items-center gap-1.5">
                    <div className="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-critical-fg text-white">
                        <X size={12} />
                    </div>
                    <span className="text-[11px] font-medium text-critical-fg">{titleCase(status)}</span>
                </div>
            )}
        </div>
    );
}

function RegistrationDetail({ ulid, onClose }: { ulid: string; onClose: () => void }) {
    const { can } = useAuth();
    const { push } = useToast();
    const queryClient = useQueryClient();
    const [status, setStatus] = useState<RegistrationStatus | ''>('');
    const [comments, setComments] = useState('');
    const [specialNotes, setSpecialNotes] = useState('');
    const [touched, setTouched] = useState(false);
    const [confirmDelete, setConfirmDelete] = useState(false);

    const { data, isLoading } = useQuery({
        queryKey: ['registration', ulid],
        queryFn: () => registrationsApi.fetchRegistration(ulid),
    });

    useEffect(() => {
        if (data && !touched) {
            setStatus(data.status);
            setComments(data.comments ?? '');
            setSpecialNotes(data.special_notes ?? '');
            setTouched(true);
        }
    }, [data, touched]);

    const updateMutation = useMutation({
        mutationFn: (payload: UpdateRegistrationPayload) => registrationsApi.updateRegistration(ulid, payload),
        onSuccess: () => {
            push('success', 'Registration updated.');
            void queryClient.invalidateQueries({ queryKey: ['registration', ulid] });
            void queryClient.invalidateQueries({ queryKey: ['registrations'] });
        },
        onError: (e: Error) => push('critical', e.message),
    });

    const deleteMutation = useMutation({
        mutationFn: () => registrationsApi.deleteRegistration(ulid),
        onSuccess: () => {
            push('success', 'Registration deleted.');
            void queryClient.invalidateQueries({ queryKey: ['registrations'] });
            onClose();
        },
        onError: (e: Error) => push('critical', e.message),
    });

    const canEdit = can('registration.update');
    const canDelete = can('registration.delete');

    return (
        <Dialog
            open
            onClose={onClose}
            title={data?.registration_number ?? 'Registration'}
            description={data?.attendee?.full_name}
            className="max-w-xl"
        >
            {isLoading || !data ? (
                <div className="space-y-3">
                    {Array.from({ length: 5 }).map((_, i) => <Skeleton key={i} className="h-9 w-full" />)}
                </div>
            ) : (
                <div className="space-y-5">
                    <StatusTimeline status={data.status} />

                    <div className="grid grid-cols-2 gap-3 text-[13px]">
                        <div>
                            <div className="text-text-faint">Ticket type</div>
                            <div className="font-medium text-text">{data.ticket_type?.name ?? '—'}</div>
                        </div>
                        <div>
                            <div className="text-text-faint">Total</div>
                            <div className="tnum font-medium text-text">{money(data.total_paisa)}</div>
                        </div>
                        <div>
                            <div className="text-text-faint">Party</div>
                            <div className="font-medium text-text">{data.adults_count} adult(s), {data.children_count} child(ren)</div>
                        </div>
                        <div>
                            <div className="text-text-faint">Submitted</div>
                            <div className="font-medium text-text">{data.submitted_at ? new Date(data.submitted_at).toLocaleDateString() : '—'}</div>
                        </div>
                    </div>

                    {data.guests && data.guests.length > 0 && (
                        <div>
                            <div className="mb-1.5 text-[12px] font-semibold uppercase tracking-wide text-text-faint">Guests</div>
                            <div className="space-y-1">
                                {data.guests.map((g) => (
                                    <div key={g.ulid} className="flex items-center justify-between rounded-lg border border-border px-3 py-1.5 text-[13px]">
                                        <span className="text-text">{g.full_name}</span>
                                        <span className="text-text-faint">{g.relation ?? g.age_group ?? '—'}</span>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}

                    <div>
                        <Label htmlFor="reg_status">Status</Label>
                        <Select
                            id="reg_status"
                            value={status}
                            disabled={!canEdit}
                            onChange={(e) => setStatus(e.target.value as RegistrationStatus)}
                        >
                            {!EDITABLE_STATUSES.includes(data.status) && (
                                <option value={data.status}>{titleCase(data.status)} (system-set)</option>
                            )}
                            {EDITABLE_STATUSES.map((s) => (
                                <option key={s} value={s}>{titleCase(s)}</option>
                            ))}
                        </Select>
                    </div>

                    <div>
                        <Label htmlFor="reg_comments">Comments</Label>
                        <Textarea id="reg_comments" rows={2} disabled={!canEdit} value={comments} onChange={(e) => setComments(e.target.value)} />
                    </div>
                    <div>
                        <Label htmlFor="reg_notes">Special notes</Label>
                        <Textarea id="reg_notes" rows={2} disabled={!canEdit} value={specialNotes} onChange={(e) => setSpecialNotes(e.target.value)} />
                    </div>

                    <div className="flex items-center justify-between border-t border-border pt-4">
                        {canDelete ? (
                            <Button variant="ghost" size="sm" className="text-critical-fg" onClick={() => setConfirmDelete(true)}>
                                <Trash2 size={15} /> Delete
                            </Button>
                        ) : <span />}
                        <div className="flex gap-2">
                            <Button variant="outline" size="sm" onClick={onClose}>Close</Button>
                            {canEdit && (
                                <Button
                                    size="sm"
                                    disabled={updateMutation.isPending}
                                    onClick={() =>
                                        void updateMutation.mutateAsync({
                                            status: status || undefined,
                                            comments: comments || null,
                                            special_notes: specialNotes || null,
                                        })
                                    }
                                >
                                    {updateMutation.isPending ? 'Saving…' : 'Save changes'}
                                </Button>
                            )}
                        </div>
                    </div>
                </div>
            )}

            <ConfirmDialog
                open={confirmDelete}
                onClose={() => setConfirmDelete(false)}
                onConfirm={() => deleteMutation.mutateAsync()}
                title="Delete registration?"
                description="This permanently removes the registration. Paid or confirmed registrations cannot be deleted."
                confirmLabel="Delete registration"
            />
        </Dialog>
    );
}

const columns: ColumnDef<Registration, unknown>[] = [
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
        id: 'ticket_type',
        header: 'Ticket type',
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
        cell: (ctx) => <span className="tnum">{money(ctx.row.original.total_paisa)}</span>,
    },
    {
        accessorKey: 'created_at',
        header: 'Created',
        cell: (ctx) => new Date(ctx.row.original.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }),
    },
];

export default function RegistrationsPage() {
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState<RegistrationStatus | ''>('');
    const [dateFrom, setDateFrom] = useState('');
    const [dateTo, setDateTo] = useState('');
    const [pageIndex, setPageIndex] = useState(0);
    const [selected, setSelected] = useState<string | null>(null);
    const pageSize = 20;

    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['registrations', search, status, dateFrom, dateTo, pageIndex],
        queryFn: () =>
            registrationsApi.fetchRegistrations({
                search,
                status,
                date_from: dateFrom,
                date_to: dateTo,
                page: pageIndex + 1,
                per_page: pageSize,
            }),
    });

    return (
        <div className="space-y-6">
            <div>
                <h1 className="text-[26px] font-bold tracking-tight text-text">Registrations</h1>
                <p className="mt-1 text-[14px] text-text-muted">Track and manage every registration through its lifecycle.</p>
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
                    onRowClick={(row) => setSelected(row.ulid)}
                    emptyTitle="No registrations found"
                    emptyDescription="Try adjusting your search or filters."
                    pageIndex={pageIndex}
                    pageSize={pageSize}
                    totalRows={data ? totalOf(data) : 0}
                    onPageChange={setPageIndex}
                />
            </Card>

            {selected && <RegistrationDetail ulid={selected} onClose={() => setSelected(null)} />}
        </div>
    );
}
