import { useEffect, useMemo, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, Banknote, Save, Trash2 } from 'lucide-react';
import { Badge, Button, Card, ErrorState, Field, FormSection, Select, Skeleton, Textarea } from '@/components/ui';
import { ConfirmDialog } from '@/components/Dialog';
import { useToast } from '@/components/Toast';
import { useAuth } from '@/features/auth/AuthProvider';
import { updateAttendee } from '@/features/attendees/api';
import { isCatalogueClass, type ParticipantType, type UpdateAttendeePayload } from '@/features/attendees/types';
import { ApiRequestError } from '@/lib/api';
import { money } from '@/lib/cn';
import { fullDate, shortDate } from '@/lib/format';
import * as registrationsApi from './api';
import { AttendeeFields, attendeeFormFrom, isCurrentStudent, needsBatchYear, type AttendeeFormState } from './AttendeeFields';
import { PriceBreakdownBlock, StatusTimeline, partySummary, statusTone, titleCase } from './RegistrationParts';
import { EDITABLE_STATUSES, type Registration, type RegistrationStatus, type UpdateRegistrationPayload } from './types';

/**
 * A registration's own page: everything about it, and the corrections an
 * operator makes at the desk — a misspelt name, a wrong mobile number, a
 * status move, a note for the gate.
 *
 * **Two records, one Save.** The attendee's details belong to the attendee
 * (`PATCH /admin/attendees/{ulid}`) and the status and notes to the
 * registration (`PATCH /admin/registrations/{ulid}`); each half is sent only
 * when it changed and only when the operator holds that permission, and a
 * field-level 422 from either lands on the control that caused it. Two
 * requests rather than one combined endpoint because the attendee is shared
 * across every registration they ever make, and an edit here is an edit to
 * the person, not to this one booking.
 */

/** The attendee fields this page may send, as a diff against what was loaded. */
function attendeeChanges(form: AttendeeFormState, original: AttendeeFormState): UpdateAttendeePayload {
    const text = (v: string) => v.trim() || null;
    const student = isCurrentStudent(form);
    const changes: UpdateAttendeePayload = {};

    if (form.full_name !== original.full_name) changes.full_name = form.full_name.trim();
    if (form.father_name !== original.father_name) changes.father_name = text(form.father_name);
    if (form.mobile !== original.mobile) changes.mobile = form.mobile.trim();
    if (form.email !== original.email) changes.email = text(form.email);
    if (form.date_of_birth !== original.date_of_birth) changes.date_of_birth = form.date_of_birth || null;
    if (form.nid_number !== original.nid_number) changes.nid_number = text(form.nid_number);
    if (form.blood_group !== original.blood_group) changes.blood_group = form.blood_group || null;
    if (form.occupation !== original.occupation) changes.occupation = text(form.occupation);
    if (form.designation !== original.designation) changes.designation = text(form.designation);
    if (form.organization !== original.organization) changes.organization = text(form.organization);
    if (form.current_address !== original.current_address) changes.current_address = text(form.current_address);
    if (form.post_office !== original.post_office) changes.post_office = text(form.post_office);
    if (form.upazila !== original.upazila) changes.upazila = text(form.upazila);
    if (form.address_district !== original.address_district) changes.address_district = text(form.address_district);

    if (form.participant_type !== original.participant_type) {
        changes.participant_type = form.participant_type as ParticipantType;
    }

    // Placement follows the type: a batch year is a former student's fact
    // and class/section/roll a current one's, so switching type clears the
    // other set rather than leaving a stale value on the row.
    const batchYear = needsBatchYear(form) && form.ssc_batch_year ? Number(form.ssc_batch_year) : null;
    const originalBatchYear = original.ssc_batch_year ? Number(original.ssc_batch_year) : null;
    if (batchYear !== originalBatchYear) changes.ssc_batch_year = batchYear;

    // A class is only ever a catalogue value or blank. A legacy row can hold
    // free text the API refuses — leave the key out and it stays as recorded.
    const cls = student ? form.current_class : '';
    if (cls !== original.current_class && (cls === '' || isCatalogueClass(cls))) changes.current_class = cls || null;

    const section = student ? text(form.current_section) : null;
    if (section !== text(original.current_section)) changes.current_section = section;
    const roll = student ? text(form.current_roll) : null;
    if (roll !== text(original.current_roll)) changes.current_roll = roll;

    return changes;
}

function paymentTone(status: string): 'success' | 'warning' | 'critical' | 'neutral' {
    if (status === 'succeeded') return 'success';
    if (status === 'pending' || status === 'awaiting_verification' || status === 'initiated') return 'warning';
    if (status === 'failed' || status === 'expired' || status === 'refunded') return 'critical';
    return 'neutral';
}

export default function RegistrationDetailPage() {
    const { ulid = '' } = useParams<{ ulid: string }>();
    const navigate = useNavigate();
    const { can } = useAuth();
    const { push } = useToast();
    const queryClient = useQueryClient();

    const canEditRegistration = can('registration.update');
    const canEditAttendee = can('attendee.update');
    const canDelete = can('registration.delete');
    const canCollectCash = can('payment.collect_cash');

    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['registration', ulid],
        queryFn: () => registrationsApi.fetchRegistration(ulid),
        enabled: ulid !== '',
    });

    const [form, setForm] = useState<AttendeeFormState | null>(null);
    const [original, setOriginal] = useState<AttendeeFormState | null>(null);
    const [status, setStatus] = useState<RegistrationStatus | ''>('');
    const [specialNotes, setSpecialNotes] = useState('');
    const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
    const [confirmDelete, setConfirmDelete] = useState(false);

    // Seed the form once per loaded record. A refetch after Save reseeds
    // from the server's copy, so the form always shows what is stored.
    useEffect(() => {
        if (!data) return;
        const attendee = data.attendee;
        const seeded = attendee ? attendeeFormFrom(attendee) : null;
        setForm(seeded);
        setOriginal(seeded);
        setStatus(data.status);
        setSpecialNotes(data.special_notes ?? '');
    }, [data]);

    const set = <K extends keyof AttendeeFormState>(key: K, value: AttendeeFormState[K]) => {
        setForm((f) => (f ? { ...f, [key]: value } : f));
        setFieldErrors((e) => {
            if (!e[key]) return e;
            const next = { ...e };
            delete next[key];
            return next;
        });
    };

    const err = (field: string) => fieldErrors[field]?.[0];

    const attendeeDiff = useMemo(
        () => (form && original ? attendeeChanges(form, original) : {}),
        [form, original],
    );
    const attendeeDirty = Object.keys(attendeeDiff).length > 0;
    const registrationDirty = data ? status !== data.status || (specialNotes || null) !== (data.special_notes || null) : false;
    const dirty = (attendeeDirty && canEditAttendee) || (registrationDirty && canEditRegistration);

    const saveMutation = useMutation({
        mutationFn: async () => {
            if (!data) return;
            const attendee = data.attendee;

            if (attendeeDirty && canEditAttendee && attendee?.ulid) {
                await updateAttendee(attendee.ulid, attendeeDiff);
            }

            if (registrationDirty && canEditRegistration) {
                const payload: UpdateRegistrationPayload = { special_notes: specialNotes.trim() || null };
                if (status && status !== data.status) payload.status = status;
                await registrationsApi.updateRegistration(ulid, payload);
            }
        },
        onSuccess: () => {
            setFieldErrors({});
            push('success', 'Registration updated.');
            void queryClient.invalidateQueries({ queryKey: ['registration', ulid] });
            void queryClient.invalidateQueries({ queryKey: ['registrations'] });
            void queryClient.invalidateQueries({ queryKey: ['attendees'] });
        },
        onError: (e: Error) => {
            const errors = e instanceof ApiRequestError ? (e.errors ?? {}) : {};
            setFieldErrors(errors);
            push('critical', Object.keys(errors).length > 0 ? 'Fix the highlighted fields and save again.' : e.message);
            // The attendee half may have landed before the registration half
            // failed; refetch so the form shows what is actually stored.
            void queryClient.invalidateQueries({ queryKey: ['registration', ulid] });
        },
    });

    const deleteMutation = useMutation({
        mutationFn: () => registrationsApi.deleteRegistration(ulid),
        onSuccess: () => {
            push('success', 'Registration deleted.');
            void queryClient.invalidateQueries({ queryKey: ['registrations'] });
            navigate('/registrations');
        },
        onError: (e: Error) => push('critical', e.message),
    });

    /**
     * The one payment cash may settle. `initiated` is excluded server-side
     * too — a live gateway session means taking cash here could see the
     * attendee charged twice for one seat — so this only hides a button
     * that would be refused, rather than being the check itself.
     */
    const collectablePayment = data?.payments?.find((p) => p.status === 'pending' || p.status === 'awaiting_verification');

    const collectCashMutation = useMutation({
        mutationFn: (paymentUlid: string) =>
            registrationsApi.collectCash(paymentUlid, { amount_received_paisa: collectablePayment?.amount_due_paisa ?? 0 }),
        onSuccess: () => {
            push('success', 'Cash recorded. The ticket has been issued and the confirmation sent.');
            void queryClient.invalidateQueries({ queryKey: ['registration', ulid] });
            void queryClient.invalidateQueries({ queryKey: ['registrations'] });
        },
        onError: (e: Error) => push('critical', e.message),
    });

    if (isLoading) {
        return (
            <div className="space-y-4">
                <Skeleton className="h-10 w-64" />
                <Skeleton className="h-8 w-full" />
                <Skeleton className="h-96 w-full" />
            </div>
        );
    }

    if (isError || !data) {
        return <ErrorState message="That registration could not be loaded." onRetry={() => void refetch()} />;
    }

    const attendee = data.attendee;
    const registration: Registration = data;

    return (
        <div className="space-y-6 pb-24">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <button
                        onClick={() => navigate('/registrations')}
                        className="mb-1 inline-flex items-center gap-1 text-[12.5px] text-text-muted hover:text-text"
                    >
                        <ArrowLeft size={14} /> All registrations
                    </button>
                    <h1 className="text-[26px] font-bold tracking-tight text-text">{registration.registration_number}</h1>
                    <div className="mt-1.5 flex flex-wrap items-center gap-2 text-[13px] text-text-muted">
                        <Badge tone={statusTone[registration.status]} size="sm">
                            {titleCase(registration.status)}
                        </Badge>
                        <span>{attendee?.full_name ?? '—'}</span>
                        {attendee?.mobile && <span className="text-text-faint">· {attendee.mobile}</span>}
                        <span className="text-text-faint">· created {shortDate(registration.created_at)}</span>
                    </div>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    {canCollectCash && collectablePayment && (
                        <Button
                            disabled={collectCashMutation.isPending}
                            onClick={() => void collectCashMutation.mutateAsync(collectablePayment.ulid)}
                        >
                            <Banknote size={15} />
                            {collectCashMutation.isPending ? 'Recording…' : `Cash received — ${money(collectablePayment.amount_due_paisa)}`}
                        </Button>
                    )}
                    {canDelete && (
                        <Button variant="ghost" className="text-critical-fg" onClick={() => setConfirmDelete(true)}>
                            <Trash2 size={15} /> Delete
                        </Button>
                    )}
                </div>
            </div>

            <Card className="p-5">
                <StatusTimeline status={registration.status} />
            </Card>

            <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_360px]">
                <Card className="p-6">
                    {form ? (
                        <div className="space-y-8">
                            {!canEditAttendee && (
                                <p className="text-[12.5px] text-text-muted">
                                    You can view the attendee's details but not change them — that needs the attendee-update
                                    permission.
                                </p>
                            )}
                            <AttendeeFields
                                mode="edit"
                                form={form}
                                set={set}
                                err={err}
                                disabled={!canEditAttendee}
                                gender={attendee?.gender}
                            />
                        </div>
                    ) : (
                        <p className="text-[13px] text-text-muted">This registration has no attendee record attached.</p>
                    )}
                </Card>

                <div className="space-y-6 lg:sticky lg:top-20 lg:self-start">
                    <Card className="p-5">
                        <FormSection title="Registration">
                            <dl className="space-y-2 text-[13px]">
                                <div className="flex justify-between gap-3">
                                    <dt className="text-text-faint">Ticket type</dt>
                                    <dd className="text-right font-medium text-text">{registration.ticket_type?.name ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-3">
                                    <dt className="text-text-faint">Party</dt>
                                    <dd className="text-right font-medium text-text">{partySummary(registration)}</dd>
                                </div>
                                <div className="flex justify-between gap-3">
                                    <dt className="text-text-faint">Source</dt>
                                    <dd className="font-medium text-text">{registration.source ? titleCase(registration.source) : '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-3">
                                    <dt className="text-text-faint">Submitted</dt>
                                    <dd className="font-medium text-text">{fullDate(registration.submitted_at)}</dd>
                                </div>
                                {registration.confirmed_at && (
                                    <div className="flex justify-between gap-3">
                                        <dt className="text-text-faint">Confirmed</dt>
                                        <dd className="font-medium text-text">{fullDate(registration.confirmed_at)}</dd>
                                    </div>
                                )}
                            </dl>

                            <Field id="reg_status" label="Status" error={err('status')}>
                                <Select
                                    id="reg_status"
                                    value={status}
                                    disabled={!canEditRegistration}
                                    onChange={(e) => setStatus(e.target.value as RegistrationStatus)}
                                >
                                    {!EDITABLE_STATUSES.includes(registration.status) && (
                                        <option value={registration.status}>{titleCase(registration.status)} (system-set)</option>
                                    )}
                                    {EDITABLE_STATUSES.map((s) => (
                                        <option key={s} value={s}>
                                            {titleCase(s)}
                                        </option>
                                    ))}
                                </Select>
                            </Field>

                            <Field
                                id="reg_notes"
                                label="Special notes"
                                optional
                                error={err('special_notes')}
                                hint="Dietary needs, accessibility requirements, anything the gate should know."
                            >
                                <Textarea
                                    id="reg_notes"
                                    rows={3}
                                    disabled={!canEditRegistration}
                                    value={specialNotes}
                                    onChange={(e) => setSpecialNotes(e.target.value)}
                                />
                            </Field>
                        </FormSection>
                    </Card>

                    <Card className="p-5">
                        <PriceBreakdownBlock registration={registration} />
                    </Card>

                    {registration.guests && registration.guests.length > 0 && (
                        <Card className="p-5">
                            <div className="mb-1.5 text-[12px] font-semibold uppercase tracking-wide text-text-faint">Guests</div>
                            <div className="rounded-lg border border-border">
                                {registration.guests.map((g) => (
                                    <div
                                        key={g.ulid}
                                        className="flex items-baseline justify-between gap-3 border-b border-border px-3 py-1.5 text-[13px] last:border-0"
                                    >
                                        <span className="min-w-0 truncate text-text">{g.full_name}</span>
                                        <span className="shrink-0 text-[12px] text-text-faint">
                                            {[g.relation, g.age_group, g.age !== null ? `${g.age} yrs` : null].filter(Boolean).join(' · ') || '—'}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        </Card>
                    )}

                    {registration.payments && registration.payments.length > 0 && (
                        <Card className="p-5">
                            <div className="mb-1.5 text-[12px] font-semibold uppercase tracking-wide text-text-faint">Payments</div>
                            <div className="rounded-lg border border-border">
                                {registration.payments.map((p) => (
                                    <div key={p.ulid} className="border-b border-border px-3 py-2 text-[13px] last:border-0">
                                        <div className="flex items-baseline justify-between gap-3">
                                            <span className="font-medium text-text">{p.payment_number}</span>
                                            <Badge tone={paymentTone(p.status)} size="sm">
                                                {titleCase(p.status)}
                                            </Badge>
                                        </div>
                                        <div className="mt-0.5 flex items-baseline justify-between gap-3 text-[12px] text-text-faint">
                                            <span>
                                                {titleCase(p.method)}
                                                {p.paid_at && ` · paid ${shortDate(p.paid_at)}`}
                                            </span>
                                            <span className="tnum">{money(p.amount_due_paisa)}</span>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </Card>
                    )}
                </div>
            </div>

            {/* Pinned to the viewport, so Save is reachable from anywhere on
                a long form — the reason this is a page and not a dialog. */}
            {(canEditAttendee || canEditRegistration) && (
                <div className="fixed inset-x-0 bottom-0 z-10 border-t border-border bg-bg/90 backdrop-blur-md lg:left-64">
                    <div className="mx-auto flex max-w-[1400px] items-center justify-between gap-3 px-4 py-3 sm:px-6">
                        <span className="text-[12.5px] text-text-muted">
                            {dirty ? 'You have unsaved changes.' : 'No changes yet.'}
                        </span>
                        <div className="flex items-center gap-2">
                            <Button
                                variant="ghost"
                                disabled={!dirty || saveMutation.isPending}
                                onClick={() => {
                                    if (original) setForm(original);
                                    setStatus(registration.status);
                                    setSpecialNotes(registration.special_notes ?? '');
                                    setFieldErrors({});
                                }}
                            >
                                Discard
                            </Button>
                            <Button disabled={!dirty || saveMutation.isPending} onClick={() => saveMutation.mutate()}>
                                <Save size={15} />
                                {saveMutation.isPending ? 'Saving…' : 'Save changes'}
                            </Button>
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
        </div>
    );
}
