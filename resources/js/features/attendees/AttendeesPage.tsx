import { useCallback, useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';
import { FileSpreadsheet, FileText, Search, ShieldCheck, Trash2 } from 'lucide-react';
import { Avatar, Badge, Button, Card, CardHeader, Input, Label, Select, Skeleton, Textarea } from '@/components/ui';
import { Dialog, ConfirmDialog } from '@/components/Dialog';
import { DataTable } from '@/components/DataTable';
import { useAuth } from '@/features/auth/AuthProvider';
import { useToast } from '@/components/Toast';
import { totalOf } from '@/lib/pagination';
import { shortDate } from '@/lib/format';
import { useTableSorting } from '@/lib/sorting';
import * as attendeesApi from './api';
import { PARTICIPANT_TYPES, SSC_BATCH_YEARS, type Attendee, type ParticipantType, type UpdateAttendeePayload } from './types';

function useDebounced<T>(value: T, delayMs = 350): T {
    const [debounced, setDebounced] = useState(value);
    useEffect(() => {
        const t = setTimeout(() => setDebounced(value), delayMs);
        return () => clearTimeout(t);
    }, [value, delayMs]);
    return debounced;
}

function participantLabel(type: ParticipantType) {
    return PARTICIPANT_TYPES.find((p) => p.value === type)?.label ?? type;
}

function AttendeeDetail({ ulid, onClose }: { ulid: string; onClose: () => void }) {
    const { can } = useAuth();
    const { push } = useToast();
    const queryClient = useQueryClient();
    const [form, setForm] = useState<UpdateAttendeePayload | null>(null);
    const [confirmDelete, setConfirmDelete] = useState(false);

    const { data, isLoading } = useQuery({
        queryKey: ['attendee', ulid],
        queryFn: () => attendeesApi.fetchAttendee(ulid),
    });

    useEffect(() => {
        if (data && !form) {
            setForm({
                full_name: data.full_name,
                full_name_bn: data.full_name_bn,
                father_name: data.father_name,
                mobile: data.mobile,
                email: data.email,
                occupation: data.occupation,
                current_address: data.current_address,
                participant_type: data.participant_type,
                ssc_batch_year: data.ssc_batch_year,
                is_verified: data.is_verified,
                notes: data.notes,
            });
        }
    }, [data, form]);

    const updateMutation = useMutation({
        mutationFn: (payload: UpdateAttendeePayload) => attendeesApi.updateAttendee(ulid, payload),
        onSuccess: () => {
            push('success', 'Attendee updated.');
            void queryClient.invalidateQueries({ queryKey: ['attendee', ulid] });
            void queryClient.invalidateQueries({ queryKey: ['attendees'] });
        },
        onError: (e: Error) => push('critical', e.message),
    });

    const deleteMutation = useMutation({
        mutationFn: () => attendeesApi.deleteAttendee(ulid),
        onSuccess: () => {
            push('success', 'Attendee deleted.');
            void queryClient.invalidateQueries({ queryKey: ['attendees'] });
            onClose();
        },
        onError: (e: Error) => push('critical', e.message),
    });

    const canEdit = can('attendee.update');
    const canDelete = can('attendee.delete');

    return (
        <Dialog open onClose={onClose} title={data?.full_name ?? 'Attendee'} description={data?.mobile} className="max-w-lg">
            {isLoading || !form ? (
                <div className="space-y-3">
                    {Array.from({ length: 5 }).map((_, i) => <Skeleton key={i} className="h-9 w-full" />)}
                </div>
            ) : (
                <div className="space-y-4">
                    <div className="flex items-center gap-4 rounded-xl border border-border bg-surface-2 px-4 py-3">
                        <Avatar src={data?.profile_photo_thumb_url} name={data?.full_name ?? ''} size={72} />
                        {/* Driven by `form`, not `data`, so the summary tracks
                            edits in progress instead of contradicting the
                            controls directly beneath it. */}
                        <div className="min-w-0 space-y-1.5">
                            {form.full_name_bn && (
                                <div lang="bn" className="truncate text-[15px] font-semibold text-text">{form.full_name_bn}</div>
                            )}
                            <div className="flex flex-wrap items-center gap-1.5">
                                <Badge tone="neutral">{participantLabel(form.participant_type ?? 'other')}</Badge>
                                {form.is_verified
                                    ? <Badge tone="success">Verified</Badge>
                                    : <Badge tone="neutral">Unverified</Badge>}
                                {form.ssc_batch_year && <Badge tone="neutral">SSC {form.ssc_batch_year}</Badge>}
                            </div>
                        </div>
                    </div>

                    <div className="grid grid-cols-2 gap-3">
                        <div>
                            <Label htmlFor="full_name">Full name</Label>
                            <Input
                                id="full_name"
                                value={form.full_name ?? ''}
                                disabled={!canEdit}
                                onChange={(e) => setForm({ ...form, full_name: e.target.value })}
                            />
                        </div>
                        <div>
                            <Label htmlFor="full_name_bn">Full name (বাংলা)</Label>
                            <Input
                                id="full_name_bn"
                                value={form.full_name_bn ?? ''}
                                disabled={!canEdit}
                                onChange={(e) => setForm({ ...form, full_name_bn: e.target.value || null })}
                            />
                        </div>
                        <div>
                            <Label htmlFor="father_name">Father's name</Label>
                            <Input
                                id="father_name"
                                value={form.father_name ?? ''}
                                disabled={!canEdit}
                                onChange={(e) => setForm({ ...form, father_name: e.target.value || null })}
                            />
                        </div>
                        <div>
                            <Label htmlFor="occupation">Occupation</Label>
                            <Input
                                id="occupation"
                                value={form.occupation ?? ''}
                                disabled={!canEdit}
                                onChange={(e) => setForm({ ...form, occupation: e.target.value || null })}
                            />
                        </div>
                        <div>
                            <Label htmlFor="mobile">Mobile</Label>
                            <Input
                                id="mobile"
                                value={form.mobile ?? ''}
                                disabled={!canEdit}
                                onChange={(e) => setForm({ ...form, mobile: e.target.value })}
                            />
                        </div>
                        <div>
                            <Label htmlFor="email">Email</Label>
                            <Input
                                id="email"
                                type="email"
                                value={form.email ?? ''}
                                disabled={!canEdit}
                                onChange={(e) => setForm({ ...form, email: e.target.value || null })}
                            />
                        </div>
                        <div>
                            <Label htmlFor="participant_type">Participant type</Label>
                            <Select
                                id="participant_type"
                                value={form.participant_type}
                                disabled={!canEdit}
                                onChange={(e) => setForm({ ...form, participant_type: e.target.value as ParticipantType })}
                            >
                                {PARTICIPANT_TYPES.map((p) => (
                                    <option key={p.value} value={p.value}>{p.label}</option>
                                ))}
                            </Select>
                        </div>
                        <div>
                            <Label htmlFor="ssc_batch_year">SSC batch year</Label>
                            <Select
                                id="ssc_batch_year"
                                value={form.ssc_batch_year ?? ''}
                                disabled={!canEdit}
                                onChange={(e) => setForm({ ...form, ssc_batch_year: e.target.value ? Number(e.target.value) : null })}
                            >
                                <option value="">Not set</option>
                                {SSC_BATCH_YEARS.map((year) => (
                                    <option key={year} value={year}>{year}</option>
                                ))}
                            </Select>
                        </div>
                    </div>

                    <div>
                        <Label htmlFor="current_address">Current address</Label>
                        <Textarea
                            id="current_address"
                            rows={2}
                            value={form.current_address ?? ''}
                            disabled={!canEdit}
                            onChange={(e) => setForm({ ...form, current_address: e.target.value || null })}
                        />
                    </div>

                    <div>
                        <Label htmlFor="notes">Notes</Label>
                        <Textarea
                            id="notes"
                            rows={2}
                            value={form.notes ?? ''}
                            disabled={!canEdit}
                            onChange={(e) => setForm({ ...form, notes: e.target.value || null })}
                        />
                    </div>

                    <label className="flex items-center gap-2 text-[13px] text-text">
                        <input
                            type="checkbox"
                            checked={form.is_verified ?? false}
                            disabled={!canEdit}
                            onChange={(e) => setForm({ ...form, is_verified: e.target.checked })}
                        />
                        <ShieldCheck size={15} className="text-text-faint" />
                        Verified attendee
                    </label>

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
                                    onClick={() => void updateMutation.mutateAsync(form)}
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
                title="Delete attendee?"
                description="This permanently removes the attendee record. Attendees with paid/confirmed registrations or issued tickets cannot be deleted."
                confirmLabel="Delete attendee"
            />
        </Dialog>
    );
}

/**
 * Every column id here is also the API's `sort` field name — that id is what
 * the table sends. A column the server cannot order by is marked
 * `enableSorting: false` rather than left to fail silently.
 */
const columns: ColumnDef<Attendee, unknown>[] = [
    {
        accessorKey: 'full_name',
        header: 'Name',
        cell: (ctx) => (
            <div className="flex items-center gap-3">
                <Avatar src={ctx.row.original.profile_photo_thumb_url} name={ctx.row.original.full_name} size={36} />
                <div className="min-w-0">
                    <div className="truncate font-medium text-text">{ctx.row.original.full_name}</div>
                    <div className="text-[12px] text-text-faint">{ctx.row.original.mobile}</div>
                </div>
            </div>
        ),
    },
    {
        accessorKey: 'participant_type',
        header: 'Type',
        cell: (ctx) => <Badge tone="neutral">{participantLabel(ctx.row.original.participant_type)}</Badge>,
    },
    {
        accessorKey: 'ssc_batch_year',
        header: 'Batch',
        cell: (ctx) => <span className="tnum">{ctx.row.original.ssc_batch_year ?? '—'}</span>,
    },
    {
        accessorKey: 'is_verified',
        header: 'Verified',
        cell: (ctx) => (
            ctx.row.original.is_verified
                ? <Badge tone="success">Verified</Badge>
                : <Badge tone="neutral">Unverified</Badge>
        ),
    },
    {
        accessorKey: 'created_at',
        header: 'Added',
        // The column the table sorts by out of the box. It is shown for that
        // reason as much as its own: a default order with no column carrying
        // it leaves the operator no way to see or to return to it.
        sortDescFirst: true,
        cell: (ctx) => <span className="tnum text-text-muted">{shortDate(ctx.row.original.created_at)}</span>,
    },
];

/**
 * Export controls for the current filter set.
 *
 * Placed in the filter row, and wired to the same state the table reads, so
 * "export" unambiguously means "what I am looking at" — the backend applies
 * the identical filters through AttendeeListFilters.
 *
 * There is no TanStack Query cache entry for this: a download is a one-off
 * side effect, not server state, and caching it would hand the operator a
 * stale file after they changed a filter.
 */
function ExportButtons({ filters }: { filters: attendeesApi.AttendeeFilters }) {
    const { push } = useToast();
    const [pending, setPending] = useState<attendeesApi.ExportFormat | null>(null);

    async function run(format: attendeesApi.ExportFormat) {
        setPending(format);
        try {
            await attendeesApi.exportAttendees(filters, format);
        } catch (e) {
            push('critical', e instanceof Error ? e.message : 'Export failed.');
        } finally {
            setPending(null);
        }
    }

    return (
        <div className="ml-auto flex items-end gap-2">
            <Button
                variant="outline"
                onClick={() => void run('xlsx')}
                disabled={pending !== null}
                title="Download the filtered list as an Excel workbook"
            >
                <FileSpreadsheet size={15} />
                {pending === 'xlsx' ? 'Preparing…' : 'Excel'}
            </Button>
            <Button
                variant="outline"
                onClick={() => void run('pdf')}
                disabled={pending !== null}
                title="Download the filtered list as a PDF"
            >
                <FileText size={15} />
                {pending === 'pdf' ? 'Preparing…' : 'PDF'}
            </Button>
        </div>
    );
}

export default function AttendeesPage() {
    const { can } = useAuth();
    const [search, setSearch] = useState('');
    const [participantType, setParticipantType] = useState<ParticipantType | ''>('');
    const [batchYear, setBatchYear] = useState('');
    const [pageIndex, setPageIndex] = useState(0);
    const [selected, setSelected] = useState<string | null>(null);
    const pageSize = 20;

    const debouncedSearch = useDebounced(search);
    const resetPage = useCallback(() => setPageIndex(0), []);
    const { sorting, setSorting, sortParams } = useTableSorting(undefined, resetPage);

    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['attendees', debouncedSearch, participantType, batchYear, sortParams, pageIndex],
        queryFn: () =>
            attendeesApi.fetchAttendees({
                search: debouncedSearch,
                participant_type: participantType,
                ssc_batch_year: batchYear ? Number(batchYear) : '',
                ...sortParams,
                page: pageIndex + 1,
                per_page: pageSize,
            }),
    });

    return (
        <div className="space-y-6">
            <div>
                <h1 className="text-[26px] font-bold tracking-tight text-text">Attendees</h1>
                <p className="mt-1 text-[14px] text-text-muted">Search and manage every attendee record.</p>
            </div>

            <Card>
                <CardHeader title="All attendees" />
                <div className="flex flex-wrap items-end gap-3 px-5 pb-4 pt-4">
                    <div className="min-w-[220px] flex-1">
                        <Label htmlFor="search">Search</Label>
                        <div className="relative">
                            <Search size={15} className="absolute left-3 top-1/2 -translate-y-1/2 text-text-faint" />
                            <Input
                                id="search"
                                className="pl-9"
                                placeholder="Name, mobile, or email"
                                value={search}
                                onChange={(e) => { setSearch(e.target.value); setPageIndex(0); }}
                            />
                        </div>
                    </div>
                    <div className="w-48">
                        <Label htmlFor="participant_type_filter">Participant type</Label>
                        <Select
                            id="participant_type_filter"
                            value={participantType}
                            onChange={(e) => { setParticipantType(e.target.value as ParticipantType | ''); setPageIndex(0); }}
                        >
                            <option value="">All types</option>
                            {PARTICIPANT_TYPES.map((p) => (
                                <option key={p.value} value={p.value}>{p.label}</option>
                            ))}
                        </Select>
                    </div>
                    <div className="w-36">
                        <Label htmlFor="batch_year_filter">Batch year</Label>
                        <Select
                            id="batch_year_filter"
                            value={batchYear}
                            onChange={(e) => { setBatchYear(e.target.value); setPageIndex(0); }}
                        >
                            <option value="">All years</option>
                            {SSC_BATCH_YEARS.map((year) => (
                                <option key={year} value={year}>{year}</option>
                            ))}
                        </Select>
                    </div>
                    {can('attendee.export') && (
                        <ExportButtons
                            filters={{
                                search: debouncedSearch,
                                participant_type: participantType,
                                ssc_batch_year: batchYear ? Number(batchYear) : '',
                                ...sortParams,
                            }}
                        />
                    )}
                </div>

                <DataTable
                    columns={columns}
                    data={data?.data ?? []}
                    getRowId={(r) => r.ulid}
                    isLoading={isLoading}
                    isError={isError}
                    onRetry={() => void refetch()}
                    onRowClick={(row) => setSelected(row.ulid)}
                    emptyTitle="No attendees found"
                    emptyDescription="Try adjusting your search or filters."
                    pageIndex={pageIndex}
                    pageSize={pageSize}
                    totalRows={data ? totalOf(data) : 0}
                    onPageChange={setPageIndex}
                    sorting={sorting}
                    onSortingChange={setSorting}
                />
            </Card>

            {selected && <AttendeeDetail ulid={selected} onClose={() => setSelected(null)} />}
        </div>
    );
}
