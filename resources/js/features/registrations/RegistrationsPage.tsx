import { useCallback, useEffect, useState } from 'react';
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
import { shortDate } from '@/lib/format';
import { useTableSorting } from '@/lib/sorting';
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

/** "2 adults, 1 child, 1 infant (free)" — infants included, because they
 *  occupy an admit at the gate even though they cost nothing. */
function partySummary(r: Registration): string {
    const parts = [`${r.adults_count} adult${r.adults_count === 1 ? '' : 's'}`];

    if (r.children_count > 0) {
        parts.push(`${r.children_count} child${r.children_count === 1 ? '' : 'ren'}`);
    }
    if (r.infants_count > 0) {
        parts.push(`${r.infants_count} infant${r.infants_count === 1 ? '' : 's'} (free)`);
    }

    return parts.join(', ');
}

interface PriceLine {
    label: string;
    detail?: string;
    amountPaisa: number;
    free?: boolean;
}

interface PriceBreakdown {
    lines: PriceLine[];
    /** Whether the lines add up to the stored subtotal. */
    reconciles: boolean;
}

/**
 * Attributes a registration's stored total to the ticket type's price
 * tiers — a mirror of CreateRegistration's formula, including
 * TicketType::basePriceFor(), which bills a current student their own rate.
 *
 * This is an *explanation* of a total, never a recomputation of it: the
 * stored `subtotal_paisa` is what was charged, and a ticket type's prices
 * can legitimately have moved since (the post-sale price lock only applies
 * once a tier has sold). So the caller renders the stored figure as the
 * total and these lines beside it, and `reconciles` says whether the two
 * agree — a disagreement is surfaced rather than papered over, because the
 * alternative is a breakdown that quietly explains the wrong number.
 *
 * Returns null when the row did not carry prices, so a half-built
 * breakdown never renders.
 */
function priceBreakdown(r: Registration): PriceBreakdown | null {
    const type = r.ticket_type;

    if (
        !type ||
        type.base_price_paisa === undefined ||
        type.additional_adult_price_paisa === undefined ||
        type.additional_child_price_paisa === undefined
    ) {
        return null;
    }

    const isStudent = r.attendee?.participant_type === 'current_student';
    const studentRate = type.current_student_price_paisa;
    // Compared against null/undefined rather than checked for truthiness:
    // 0 is a real price (a free student ticket), not an absent rule.
    const onStudentRate = isStudent && studentRate !== null && studentRate !== undefined;
    const basePaisa = onStudentRate ? (studentRate as number) : type.base_price_paisa;

    const baseAdmits = type.base_admits ?? 1;
    const extraAdults = Math.max(0, r.adults_count - baseAdmits);

    const lines: PriceLine[] = [
        {
            label: 'Registrant',
            detail: onStudentRate ? 'Current student rate' : 'Standard rate',
            amountPaisa: basePaisa,
        },
    ];

    if (extraAdults > 0) {
        lines.push({
            label: `${extraAdults} extra adult${extraAdults === 1 ? '' : 's'}`,
            detail: `${money(type.additional_adult_price_paisa)} each`,
            amountPaisa: extraAdults * type.additional_adult_price_paisa,
        });
    }

    if (r.children_count > 0) {
        lines.push({
            label: `${r.children_count} child${r.children_count === 1 ? '' : 'ren'}`,
            detail: `${money(type.additional_child_price_paisa)} each`,
            amountPaisa: r.children_count * type.additional_child_price_paisa,
        });
    }

    // Priced at zero but listed, so the breakdown accounts for every head
    // the gate will admit rather than appearing to have lost one.
    if (r.infants_count > 0) {
        lines.push({
            label: `${r.infants_count} infant${r.infants_count === 1 ? '' : 's'}`,
            detail: 'Under the free age',
            amountPaisa: 0,
            free: true,
        });
    }

    const sum = lines.reduce((total, line) => total + line.amountPaisa, 0);

    return { lines, reconciles: sum === r.subtotal_paisa };
}

/**
 * What was charged, and why. The stored `subtotal_paisa`/`total_paisa` are
 * the money — the itemised lines only explain them, and say so out loud
 * when they no longer add up (a ticket type repriced after this
 * registration was taken).
 */
function PriceBreakdownBlock({ registration }: { registration: Registration }) {
    const breakdown = priceBreakdown(registration);

    return (
        <div>
            <div className="mb-1.5 text-[12px] font-semibold uppercase tracking-wide text-text-faint">Price</div>
            <div className="rounded-lg border border-border">
                {breakdown?.lines.map((line, i) => (
                    <div
                        key={`${line.label}-${i}`}
                        className="flex items-baseline justify-between gap-3 border-b border-border px-3 py-1.5 text-[13px]"
                    >
                        <span className="min-w-0">
                            <span className="text-text">{line.label}</span>
                            {line.detail && <span className="ml-1.5 text-[12px] text-text-faint">{line.detail}</span>}
                        </span>
                        {line.free ? (
                            <Badge tone="success">Free</Badge>
                        ) : (
                            <span className="tnum shrink-0 text-text">{money(line.amountPaisa)}</span>
                        )}
                    </div>
                ))}

                {registration.discount_paisa > 0 && (
                    <div className="flex items-baseline justify-between gap-3 border-b border-border px-3 py-1.5 text-[13px]">
                        <span className="text-text">
                            Discount
                            {registration.discount_code && (
                                <span className="ml-1.5 text-[12px] text-text-faint">{registration.discount_code}</span>
                            )}
                        </span>
                        <span className="tnum shrink-0 text-text">−{money(registration.discount_paisa)}</span>
                    </div>
                )}

                <div className="flex items-baseline justify-between gap-3 px-3 py-1.5 text-[13px]">
                    <span className="font-semibold text-text">Total</span>
                    <span className="tnum shrink-0 font-semibold text-text">{money(registration.total_paisa)}</span>
                </div>
            </div>

            {breakdown && !breakdown.reconciles && (
                <p className="mt-1.5 text-[12px] text-warning-fg">
                    These lines no longer add up to the amount charged — the ticket type has been repriced since this
                    registration was taken. {money(registration.total_paisa)} is what applies.
                </p>
            )}
        </div>
    );
}

function RegistrationDetail({ ulid, onClose }: { ulid: string; onClose: () => void }) {
    const { can } = useAuth();
    const { push } = useToast();
    const queryClient = useQueryClient();
    const [status, setStatus] = useState<RegistrationStatus | ''>('');
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
            className="max-w-2xl"
            /* Pinned, so Delete/Save stay reachable without scrolling past a
               large party — and out of the body, which is what scrolls. */
            footer={
                data && (
                    <div className="flex items-center justify-between gap-3">
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
                                            special_notes: specialNotes || null,
                                        })
                                    }
                                >
                                    {updateMutation.isPending ? 'Saving…' : 'Save changes'}
                                </Button>
                            )}
                        </div>
                    </div>
                )
            }
        >
            {isLoading || !data ? (
                <div className="space-y-3">
                    {Array.from({ length: 5 }).map((_, i) => <Skeleton key={i} className="h-9 w-full" />)}
                </div>
            ) : (
                <div className="space-y-4">
                    <StatusTimeline status={data.status} />

                    {/* One row on desktop rather than two stacked pairs. */}
                    <div className="grid grid-cols-2 gap-x-3 gap-y-2.5 text-[13px] sm:grid-cols-4">
                        <div>
                            <div className="text-text-faint">Ticket type</div>
                            <div className="font-medium text-text">{data.ticket_type?.name ?? '—'}</div>
                        </div>
                        <div>
                            <div className="text-text-faint">Participant type</div>
                            <div className="font-medium text-text">
                                {data.attendee?.participant_type ? titleCase(data.attendee.participant_type) : '—'}
                            </div>
                        </div>
                        <div>
                            <div className="text-text-faint">Party</div>
                            <div className="font-medium text-text">{partySummary(data)}</div>
                        </div>
                        <div>
                            <div className="text-text-faint">Submitted</div>
                            <div className="font-medium text-text">{data.submitted_at ? new Date(data.submitted_at).toLocaleDateString() : '—'}</div>
                        </div>
                    </div>

                    {/* Price on the left, Status top-right where the eye lands
                        on the one control that acts on the record. Guests fill
                        the rest of the right column — both it and the price
                        grow with the party, so keeping them in one row means the
                        block is as tall as the longer of the two, not the sum. */}
                    <div className="grid gap-4 sm:grid-cols-2">
                        <PriceBreakdownBlock registration={data} />

                        <div className="space-y-4">
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

                            {data.guests && data.guests.length > 0 && (
                                <div>
                                    <div className="mb-1.5 text-[12px] font-semibold uppercase tracking-wide text-text-faint">Guests</div>
                                    <div className="rounded-lg border border-border">
                                        {data.guests.map((g) => (
                                            <div
                                                key={g.ulid}
                                                className="flex items-baseline justify-between gap-3 border-b border-border px-3 py-1.5 text-[13px] last:border-0"
                                            >
                                                <span className="min-w-0 truncate text-text">{g.full_name}</span>
                                                <span className="shrink-0 text-[12px] text-text-faint">{g.relation ?? g.age_group ?? '—'}</span>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>

                    <div>
                        <Label htmlFor="reg_notes">Special notes</Label>
                        <Textarea id="reg_notes" rows={2} disabled={!canEdit} value={specialNotes} onChange={(e) => setSpecialNotes(e.target.value)} />
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
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState<RegistrationStatus | ''>('');
    const [dateFrom, setDateFrom] = useState('');
    const [dateTo, setDateTo] = useState('');
    const [pageIndex, setPageIndex] = useState(0);
    const [selected, setSelected] = useState<string | null>(null);
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
                    sorting={sorting}
                    onSortingChange={setSorting}
                />
            </Card>

            {selected && <RegistrationDetail ulid={selected} onClose={() => setSelected(null)} />}
        </div>
    );
}
