import { useCallback, useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';
import { ChevronDown, FileSpreadsheet, FileText, Mail, Phone, Search, ShieldCheck, Trash2 } from 'lucide-react';
import { Avatar, Badge, Button, Card, CardHeader, DetailRow, ErrorState, Field, FormSection, Input, Label, Select, Skeleton, Switch, Textarea } from '@/components/ui';
import { Dialog, ConfirmDialog } from '@/components/Dialog';
import { DataTable } from '@/components/DataTable';
import { useAuth } from '@/features/auth/AuthProvider';
import { useToast } from '@/components/Toast';
import { ApiRequestError } from '@/lib/api';
import { cn } from '@/lib/cn';
import { totalOf } from '@/lib/pagination';
import { fullDate, shortDate, titleCase } from '@/lib/format';
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

/* -------------------------------------------------------------- Attendee record */

/**
 * The dialog's own form shape: every field a string or boolean, never null.
 *
 * Controlled inputs need a defined value or React switches them to uncontrolled
 * mid-edit, and the API's nullable columns arrive as null. Converting once on
 * the way in and once on the way out keeps `?? ''` out of every control and
 * puts the blank-means-null rule in a single function.
 */
interface AttendeeForm {
    full_name: string;
    full_name_bn: string;
    father_name: string;
    mobile: string;
    email: string;
    occupation: string;
    current_address: string;
    participant_type: ParticipantType;
    ssc_batch_year: string;
    is_verified: boolean;
    notes: string;
}

const FORM_KEYS = [
    'full_name',
    'full_name_bn',
    'father_name',
    'mobile',
    'email',
    'occupation',
    'current_address',
    'participant_type',
    'ssc_batch_year',
    'is_verified',
    'notes',
] as const satisfies readonly (keyof AttendeeForm)[];

function toForm(a: Attendee): AttendeeForm {
    return {
        full_name: a.full_name,
        full_name_bn: a.full_name_bn ?? '',
        father_name: a.father_name ?? '',
        mobile: a.mobile,
        email: a.email ?? '',
        occupation: a.occupation ?? '',
        current_address: a.current_address ?? '',
        participant_type: a.participant_type,
        ssc_batch_year: a.ssc_batch_year ? String(a.ssc_batch_year) : '',
        is_verified: a.is_verified,
        notes: a.notes ?? '',
    };
}

/** Blank is "not known", which the API stores as null — an empty string would be a value. */
function toPayload(f: AttendeeForm): UpdateAttendeePayload {
    const text = (v: string) => v.trim() || null;

    return {
        full_name: f.full_name.trim(),
        full_name_bn: text(f.full_name_bn),
        father_name: text(f.father_name),
        mobile: f.mobile.trim(),
        email: text(f.email),
        occupation: text(f.occupation),
        current_address: text(f.current_address),
        participant_type: f.participant_type,
        ssc_batch_year: f.ssc_batch_year ? Number(f.ssc_batch_year) : null,
        is_verified: f.is_verified,
        notes: text(f.notes),
    };
}

function hasChanges(a: AttendeeForm, b: AttendeeForm): boolean {
    return FORM_KEYS.some((key) => a[key] !== b[key]);
}

/**
 * The record header — who this is, at a glance, before any field is read.
 *
 * The mobile number and email are links, not text. The most common reason to
 * open an attendee is to get in touch with them or to check who they are, and
 * an admin who has to select-and-copy a phone number to dial it is being asked
 * to do the interface's job. Both are also the two columns with a uniqueness
 * constraint behind them, which is why they sit together here and in the form.
 */
function RecordHeader({ attendee, form }: { attendee: Attendee; form: AttendeeForm }) {
    const chip = 'inline-flex items-center gap-1.5 rounded-lg border border-border px-2 py-1 text-[12px] text-text-muted transition-colors hover:border-border-strong hover:text-text';

    return (
        <div className="flex min-w-0 items-start gap-3.5">
            <Avatar src={attendee.profile_photo_thumb_url} name={form.full_name} size={56} />
            <div className="min-w-0 space-y-2">
                <div className="min-w-0">
                    <h2 className="truncate text-[17px] font-bold leading-tight text-text">{form.full_name}</h2>
                    {form.full_name_bn && (
                        <p lang="bn" className="truncate text-[14px] leading-snug text-text-muted">
                            {form.full_name_bn}
                        </p>
                    )}
                </div>

                <div className="flex flex-wrap items-center gap-1.5">
                    <Badge tone="neutral" size="sm">{participantLabel(form.participant_type)}</Badge>
                    {form.ssc_batch_year && <Badge tone="neutral" size="sm">SSC {form.ssc_batch_year}</Badge>}
                    {form.is_verified
                        ? <Badge tone="success" size="sm"><ShieldCheck size={11} /> Verified</Badge>
                        : <Badge tone="warning" size="sm">Not verified</Badge>}
                </div>

                <div className="flex flex-wrap items-center gap-1.5">
                    {form.mobile && (
                        <a href={`tel:${form.mobile}`} className={chip} title={`Call ${form.mobile}`}>
                            <Phone size={12} /> <span className="tnum">{form.mobile}</span>
                        </a>
                    )}
                    {form.email && (
                        <a href={`mailto:${form.email}`} className={cn(chip, 'max-w-[15rem]')} title={`Email ${form.email}`}>
                            <Mail size={12} /> <span className="truncate">{form.email}</span>
                        </a>
                    )}
                </div>
            </div>
        </div>
    );
}

/**
 * The fields an admin cannot edit here but often needs to read — shirt size on
 * a fulfilment call, blood group at the gate, who to ring in an emergency.
 *
 * A native `<details>` rather than a state-driven panel: it collapses, it is
 * keyboard-operable, and it is announced correctly without any of that being
 * re-implemented. Collapsed by default because it is reference, not the task.
 */
function RecordDetails({ attendee }: { attendee: Attendee }) {
    const tshirt = attendee.tshirt_required
        ? (attendee.tshirt_size ?? 'Requested, size not set')
        : 'Not requested';

    return (
        <details className="group rounded-xl border border-border">
            <summary className="flex cursor-pointer list-none items-center justify-between gap-2 px-3.5 py-2.5 text-[12.5px] font-semibold text-text-muted hover:text-text">
                Everything else on file
                <ChevronDown size={15} className="transition-transform group-open:rotate-180" />
            </summary>
            <dl className="divide-y divide-border border-t border-border px-3.5 py-1">
                <DetailRow label="Gender" value={attendee.gender ? titleCase(attendee.gender) : null} />
                <DetailRow label="Date of birth" value={attendee.date_of_birth ? fullDate(attendee.date_of_birth) : null} />
                <DetailRow label="Designation" value={attendee.designation} />
                <DetailRow label="Organization" value={attendee.organization} />
                <DetailRow label="Current class" value={attendee.current_class} />
                <DetailRow label="T-shirt" value={tshirt} />
                <DetailRow label="District" value={attendee.address_district} />
                <DetailRow label="Country" value={attendee.country} />
                <DetailRow label="Blood group" value={attendee.blood_group} />
                <DetailRow label="Emergency contact" value={attendee.emergency_contact_name} />
                <DetailRow label="Emergency phone" value={attendee.emergency_contact_phone} />
                <DetailRow label="On file since" value={fullDate(attendee.created_at)} />
            </dl>
        </details>
    );
}

function AttendeeDetail({ ulid, onClose }: { ulid: string; onClose: () => void }) {
    const { can } = useAuth();
    const { push } = useToast();
    const queryClient = useQueryClient();

    const [form, setForm] = useState<AttendeeForm | null>(null);
    // What the server last confirmed. Everything "is this saved yet" is a
    // comparison against this, rather than a boolean somebody has to remember
    // to set on every edit path.
    const [saved, setSaved] = useState<AttendeeForm | null>(null);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [confirmDiscard, setConfirmDiscard] = useState(false);

    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['attendee', ulid],
        queryFn: () => attendeesApi.fetchAttendee(ulid),
    });

    useEffect(() => {
        if (data && !form) {
            const initial = toForm(data);
            setForm(initial);
            setSaved(initial);
        }
    }, [data, form]);

    const updateMutation = useMutation({
        mutationFn: (payload: UpdateAttendeePayload) => attendeesApi.updateAttendee(ulid, payload),
        // Re-seed from the server's response rather than keeping what was
        // typed: the API normalises a mobile number before storing it, so
        // "+880 1711-223344" comes back as "+8801711223344" and the dialog
        // would otherwise keep showing a value the database does not hold.
        onSuccess: (updated) => {
            const fresh = toForm(updated);
            setForm(fresh);
            setSaved(fresh);
            setFieldErrors({});
            push('success', 'Changes saved.');
            void queryClient.invalidateQueries({ queryKey: ['attendee', ulid] });
            void queryClient.invalidateQueries({ queryKey: ['attendees'] });
        },
        onError: (e: Error) => {
            const fields = e instanceof ApiRequestError ? e.errors : undefined;

            if (!fields) {
                // Nothing field-specific to show — a 403 or a network failure.
                // The server's own sentence is all there is to say.
                push('critical', e.message);
                return;
            }

            const flat = Object.fromEntries(
                Object.entries(fields).map(([field, messages]) => [field, messages[0] ?? '']),
            );
            setFieldErrors(flat);
            // Take the operator to the first thing needing their attention. A
            // rejected save with the offending field scrolled out of sight
            // reads as nothing having happened at all.
            document.getElementById(`attendee-${Object.keys(flat)[0]}`)?.focus();
            // The toast says what happened and where to look; the field says
            // what is wrong with it. Repeating the field's sentence here would
            // put the same message in two places and leave the operator
            // checking whether they are two different problems.
            const count = Object.keys(flat).length;
            push('critical', `Nothing was saved. Check the highlighted ${count === 1 ? 'field' : `${count} fields`}.`);
        },
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
    const dirty = form !== null && saved !== null && hasChanges(form, saved);

    /** Editing a field clears its error — the message described the old value. */
    function set<K extends keyof AttendeeForm>(key: K, value: AttendeeForm[K]) {
        setForm((prev) => (prev ? { ...prev, [key]: value } : prev));
        setFieldErrors(({ [key]: _cleared, ...rest }) => rest);
    }

    /**
     * The one exit. Esc, the backdrop and the X all arrive here, so unsaved
     * work cannot be lost by a mis-aimed click on the overlay — which is the
     * easiest of the three to do by accident.
     *
     * A confirm sitting on top owns the interaction. Both dialogs listen for
     * Escape on window, so without this an Escape meant to back out of "Delete
     * this attendee?" would dismiss the record behind it as well.
     */
    function requestClose() {
        if (confirmDelete || confirmDiscard) return;

        if (dirty) {
            setConfirmDiscard(true);
            return;
        }

        onClose();
    }

    const title = data?.full_name ?? 'Attendee';

    return (
        <Dialog
            open
            onClose={requestClose}
            title={title}
            className="max-w-2xl"
            header={form && data ? <RecordHeader attendee={data} form={form} /> : <h2 className="text-[16px] font-semibold text-text">{title}</h2>}
            footer={
                form && (
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        {canDelete ? (
                            <Button variant="ghost" size="sm" className="text-critical-fg" onClick={() => setConfirmDelete(true)}>
                                <Trash2 size={15} /> Delete
                            </Button>
                        ) : <span />}
                        <div className="flex items-center gap-3">
                            {dirty && <span className="text-[12px] text-text-faint">Unsaved changes</span>}
                            <div className="flex gap-2">
                                <Button variant="outline" size="sm" onClick={requestClose}>
                                    {canEdit ? 'Cancel' : 'Close'}
                                </Button>
                                {canEdit && (
                                    <Button
                                        size="sm"
                                        disabled={!dirty || updateMutation.isPending}
                                        onClick={() => void updateMutation.mutateAsync(toPayload(form))}
                                    >
                                        {updateMutation.isPending ? 'Saving…' : 'Save changes'}
                                    </Button>
                                )}
                            </div>
                        </div>
                    </div>
                )
            }
        >
            {isError && <ErrorState message="This attendee could not be loaded." onRetry={() => void refetch()} />}

            {!isError && (isLoading || !form || !data) && (
                <div className="space-y-3">
                    {Array.from({ length: 6 }).map((_, i) => <Skeleton key={i} className="h-9 w-full" />)}
                </div>
            )}

            {!isError && form && data && (
                <div className="space-y-6">
                    {!canEdit && (
                        <p className="rounded-xl border border-border bg-surface-2 px-3.5 py-2.5 text-[12.5px] text-text-muted">
                            You can read this record but not change it. Editing needs the
                            <span className="font-semibold text-text"> attendee.update </span>
                            permission.
                        </p>
                    )}

                    <FormSection title="Identity" description="The names that print on the ticket and the directory.">
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field id="attendee-full_name" label="Full name" error={fieldErrors.full_name}>
                                <Input
                                    id="attendee-full_name"
                                    value={form.full_name}
                                    maxLength={150}
                                    disabled={!canEdit}
                                    aria-invalid={Boolean(fieldErrors.full_name)}
                                    onChange={(e) => set('full_name', e.target.value)}
                                />
                            </Field>
                            <Field id="attendee-full_name_bn" label="Full name (বাংলা)" optional error={fieldErrors.full_name_bn}>
                                <Input
                                    id="attendee-full_name_bn"
                                    lang="bn"
                                    value={form.full_name_bn}
                                    maxLength={150}
                                    disabled={!canEdit}
                                    aria-invalid={Boolean(fieldErrors.full_name_bn)}
                                    onChange={(e) => set('full_name_bn', e.target.value)}
                                />
                            </Field>
                            <Field id="attendee-father_name" label="Father's name" optional error={fieldErrors.father_name} className="sm:col-span-2">
                                <Input
                                    id="attendee-father_name"
                                    value={form.father_name}
                                    maxLength={150}
                                    disabled={!canEdit}
                                    aria-invalid={Boolean(fieldErrors.father_name)}
                                    onChange={(e) => set('father_name', e.target.value)}
                                />
                            </Field>
                        </div>
                    </FormSection>

                    <FormSection title="Contact" description="Each of these belongs to exactly one attendee.">
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field id="attendee-mobile" label="Mobile" error={fieldErrors.mobile} hint="Matches returning registrants.">
                                <Input
                                    id="attendee-mobile"
                                    type="tel"
                                    inputMode="tel"
                                    value={form.mobile}
                                    maxLength={20}
                                    disabled={!canEdit}
                                    aria-invalid={Boolean(fieldErrors.mobile)}
                                    onChange={(e) => set('mobile', e.target.value)}
                                />
                            </Field>
                            <Field id="attendee-email" label="Email" optional error={fieldErrors.email}>
                                <Input
                                    id="attendee-email"
                                    type="email"
                                    value={form.email}
                                    maxLength={254}
                                    disabled={!canEdit}
                                    aria-invalid={Boolean(fieldErrors.email)}
                                    onChange={(e) => set('email', e.target.value)}
                                />
                            </Field>
                            <Field id="attendee-current_address" label="Current address" optional error={fieldErrors.current_address} className="sm:col-span-2">
                                <Textarea
                                    id="attendee-current_address"
                                    rows={2}
                                    value={form.current_address}
                                    maxLength={255}
                                    disabled={!canEdit}
                                    aria-invalid={Boolean(fieldErrors.current_address)}
                                    onChange={(e) => set('current_address', e.target.value)}
                                />
                            </Field>
                        </div>
                    </FormSection>

                    <FormSection title="School and work">
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field id="attendee-participant_type" label="Participant type" error={fieldErrors.participant_type}>
                                <Select
                                    id="attendee-participant_type"
                                    value={form.participant_type}
                                    disabled={!canEdit}
                                    onChange={(e) => set('participant_type', e.target.value as ParticipantType)}
                                >
                                    {PARTICIPANT_TYPES.map((p) => (
                                        <option key={p.value} value={p.value}>{p.label}</option>
                                    ))}
                                </Select>
                            </Field>
                            <Field id="attendee-ssc_batch_year" label="SSC batch year" optional error={fieldErrors.ssc_batch_year}>
                                <Select
                                    id="attendee-ssc_batch_year"
                                    value={form.ssc_batch_year}
                                    disabled={!canEdit}
                                    onChange={(e) => set('ssc_batch_year', e.target.value)}
                                >
                                    <option value="">Not set</option>
                                    {SSC_BATCH_YEARS.map((year) => (
                                        <option key={year} value={year}>{year}</option>
                                    ))}
                                </Select>
                            </Field>
                            <Field id="attendee-occupation" label="Occupation" optional error={fieldErrors.occupation} className="sm:col-span-2">
                                <Input
                                    id="attendee-occupation"
                                    value={form.occupation}
                                    maxLength={100}
                                    disabled={!canEdit}
                                    aria-invalid={Boolean(fieldErrors.occupation)}
                                    onChange={(e) => set('occupation', e.target.value)}
                                />
                            </Field>
                        </div>
                    </FormSection>

                    <FormSection title="Staff use" description="Visible to the team, never to the attendee.">
                        <div className="space-y-3">
                            <div className="flex items-start justify-between gap-4 rounded-xl border border-border px-3.5 py-3">
                                <div className="min-w-0">
                                    <div className="text-[13px] font-semibold text-text">Verified attendee</div>
                                    <p className="mt-0.5 text-[12px] text-text-muted">
                                        Their identity has been confirmed by a member of staff.
                                    </p>
                                </div>
                                <Switch
                                    checked={form.is_verified}
                                    disabled={!canEdit}
                                    onChange={(next) => set('is_verified', next)}
                                    label="Verified attendee"
                                />
                            </div>
                            <Field id="attendee-notes" label="Notes" optional error={fieldErrors.notes}>
                                <Textarea
                                    id="attendee-notes"
                                    rows={3}
                                    value={form.notes}
                                    maxLength={1000}
                                    disabled={!canEdit}
                                    placeholder="Anything the next person handling this record should know."
                                    aria-invalid={Boolean(fieldErrors.notes)}
                                    onChange={(e) => set('notes', e.target.value)}
                                />
                            </Field>
                        </div>
                    </FormSection>

                    <RecordDetails attendee={data} />
                </div>
            )}

            <ConfirmDialog
                open={confirmDelete}
                onClose={() => setConfirmDelete(false)}
                onConfirm={() => deleteMutation.mutateAsync()}
                title="Delete this attendee?"
                description="This permanently removes the attendee record. An attendee with a paid or confirmed registration, or an issued ticket, cannot be deleted."
                confirmLabel="Delete attendee"
            />

            <ConfirmDialog
                open={confirmDiscard}
                onClose={() => setConfirmDiscard(false)}
                onConfirm={async () => { setConfirmDiscard(false); onClose(); }}
                title="Discard your changes?"
                description="This attendee has edits that have not been saved. Closing now leaves the record as it was."
                confirmLabel="Discard changes"
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
