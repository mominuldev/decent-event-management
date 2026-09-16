import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, Banknote, Check, Plus, Trash2 } from 'lucide-react';
import { Button, Card, Field, FormSection, Input, Label, Select, Textarea } from '@/components/ui';
import { useToast } from '@/components/Toast';
import { useAuth } from '@/features/auth/AuthProvider';
import { ApiRequestError } from '@/lib/api';
import { cn, money } from '@/lib/cn';
import { randomId } from '@/lib/id';
import { fetchTicketTypes } from '@/features/tickets/api';
import type { TicketType } from '@/features/tickets/types';
import * as registrationsApi from './api';
import {
    AttendeeFields,
    EMPTY_ATTENDEE_FORM,
    isCurrentStudent,
    needsBatchYear,
    titleCase,
    type AttendeeFormState,
} from './AttendeeFields';
import { PARTICIPANT_TYPES, type CreateRegistrationPayload, type Registration, type RegistrationGuestPayload } from './types';

/**
 * Registering a walk-up at the desk and taking their cash — a page, not a
 * dialog, because the form is long (the counter collects the same twenty
 * fields the public form does) and a dialog that scrolls inside itself
 * hides its own Save button behind a large party.
 *
 * **Two server calls, deliberately, and the split is the design.** Step one
 * creates the registration and returns the authoritative total; step two
 * takes the money against it. The alternative — one call that does both —
 * would mean this page computing the price itself in order to show the
 * operator what to charge, and that formula (tiered base rate, extra
 * adults, extra children, free infants by age) already exists twice: on the
 * server in CreateRegistration, and mirrored on the public site. A third
 * copy behind a till is where it would first quietly disagree, and the
 * symptom would be undercharging real people.
 *
 * It also makes the abandoned case safe: if step two never happens, the
 * registration is sitting in the list as `pending_payment` and can be
 * collected against later — from its own page.
 */

type GuestDraft = RegistrationGuestPayload & { key: string };

function emptyGuest(): GuestDraft {
    return { key: randomId(), full_name: '', relation: 'spouse', age_group: 'adult', age: null };
}

function StepIndicator({ step }: { step: 1 | 2 }) {
    const steps = ['Details', 'Payment'];
    return (
        <ol className="flex items-center gap-2 text-[12.5px]">
            {steps.map((label, i) => {
                const n = (i + 1) as 1 | 2;
                const done = n < step;
                const current = n === step;
                return (
                    <li key={label} className="flex items-center gap-2">
                        <span
                            className={cn(
                                'grid h-6 w-6 place-items-center rounded-full text-[11px] font-semibold',
                                done || current ? 'bg-accent text-accent-fg' : 'bg-surface-2 text-text-faint',
                                current && 'ring-2 ring-accent/30',
                            )}
                        >
                            {done ? <Check size={12} /> : n}
                        </span>
                        <span className={cn(done || current ? 'font-medium text-text' : 'text-text-faint')}>{label}</span>
                        {i < steps.length - 1 && <span className="h-px w-8 bg-border" />}
                    </li>
                );
            })}
        </ol>
    );
}

export default function NewRegistrationPage() {
    const navigate = useNavigate();
    const { can } = useAuth();
    const { push } = useToast();
    const queryClient = useQueryClient();
    const canCollectCash = can('payment.collect_cash');

    const [form, setForm] = useState<AttendeeFormState>(EMPTY_ATTENDEE_FORM);
    const [ticketTypeUlid, setTicketTypeUlid] = useState('');
    const [specialNotes, setSpecialNotes] = useState('');
    const [guests, setGuests] = useState<GuestDraft[]>([]);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
    const [created, setCreated] = useState<Registration | null>(null);
    const [receiptReference, setReceiptReference] = useState('');

    const ticketTypesQuery = useQuery({ queryKey: ['ticket-types'], queryFn: fetchTicketTypes });

    const sellableTypes = useMemo(
        () => (ticketTypesQuery.data ?? []).filter((t) => t.is_active),
        [ticketTypesQuery.data],
    );

    const selectedType: TicketType | undefined = useMemo(
        () => sellableTypes.find((t) => t.ulid === ticketTypeUlid),
        [sellableTypes, ticketTypeUlid],
    );

    // The ticket type decides its own audience. Enforced server-side too —
    // this only stops the operator picking a combination that will be
    // refused, rather than being the check itself.
    const allowedParticipantTypes = useMemo<readonly string[]>(() => {
        const allowed = selectedType?.allowed_participant_types;
        return allowed && allowed.length > 0 ? allowed : PARTICIPANT_TYPES;
    }, [selectedType]);

    useEffect(() => {
        if (!allowedParticipantTypes.includes(form.participant_type)) {
            setForm((f) => ({ ...f, participant_type: allowedParticipantTypes[0] ?? 'other' }));
        }
    }, [allowedParticipantTypes, form.participant_type]);

    const adultsCount = 1 + guests.filter((g) => g.age_group === 'adult').length;
    const childrenCount = guests.filter((g) => g.age_group === 'child').length;
    const participationType = guests.length === 0 ? 'single' : guests.length === 1 ? 'couple' : 'family';

    const clearError = (key: string) =>
        setFieldErrors((e) => {
            if (!e[key]) return e;
            const next = { ...e };
            delete next[key];
            return next;
        });

    const set = <K extends keyof AttendeeFormState>(key: K, value: AttendeeFormState[K]) => {
        setForm((f) => ({ ...f, [key]: value }));
        clearError(key);
    };

    const createMutation = useMutation({
        mutationFn: (payload: CreateRegistrationPayload) => registrationsApi.createRegistration(payload),
        onSuccess: (registration) => {
            setCreated(registration);
            setFieldErrors({});
            void queryClient.invalidateQueries({ queryKey: ['registrations'] });
            window.scrollTo({ top: 0 });
        },
        onError: (e: Error) => {
            const errors = e instanceof ApiRequestError ? (e.errors ?? {}) : {};
            setFieldErrors(errors);
            push('critical', Object.keys(errors).length > 0 ? 'Fix the highlighted fields and try again.' : e.message);
        },
    });

    const collectMutation = useMutation({
        mutationFn: ({ paymentUlid, amount }: { paymentUlid: string; amount: number }) =>
            registrationsApi.collectCash(paymentUlid, {
                amount_received_paisa: amount,
                receipt_reference: receiptReference.trim() || null,
            }),
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: ['registrations'] });
            push('success', `${created?.registration_number} paid. The ticket has been issued and the confirmation sent.`);
            navigate(created ? `/registrations/${created.ulid}` : '/registrations');
        },
        onError: (e: Error) => push('critical', e.message),
    });

    const submitDetails = () => {
        const student = isCurrentStudent(form);
        const payload: CreateRegistrationPayload = {
            full_name: form.full_name.trim(),
            father_name: form.father_name.trim(),
            mobile: form.mobile.trim(),
            email: form.email.trim() || null,
            gender: form.gender,
            date_of_birth: form.date_of_birth,
            // Optional at the desk — an under-18 student has no NID (they may
            // give their birth registration number instead), and a guessed
            // blood group is worse than a blank one.
            nid_number: form.nid_number.trim() || null,
            blood_group: form.blood_group || null,
            occupation: form.occupation.trim(),
            designation: form.designation.trim() || null,
            organization: form.organization.trim() || null,
            current_address: form.current_address.trim(),
            post_office: form.post_office.trim(),
            upazila: form.upazila.trim(),
            address_district: form.address_district.trim(),
            participant_type: form.participant_type,
            ssc_batch_year: needsBatchYear(form) && form.ssc_batch_year ? Number(form.ssc_batch_year) : null,
            current_class: student && form.current_class ? form.current_class : null,
            current_section: student ? form.current_section.trim() || null : null,
            current_roll: student ? form.current_roll.trim() || null : null,
            ticket_type_ulid: ticketTypeUlid,
            participation_type: participationType,
            adults_count: adultsCount,
            // Every child attending, infants included. Which of them are
            // free is decided by the server from each guest's own age.
            children_count: childrenCount,
            guests: guests.map(({ key: _key, ...g }) => ({ ...g, age: g.age === null ? null : Number(g.age) })),
            special_notes: specialNotes.trim() || null,
        };

        createMutation.mutate(payload);
    };

    const pendingPayment = created?.payments?.find((p) => p.status === 'pending' || p.status === 'awaiting_verification');
    const err = (field: string) => fieldErrors[field]?.[0];
    const amountDue = pendingPayment?.amount_due_paisa ?? created?.total_paisa ?? 0;

    return (
        <div className="space-y-6">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <button
                        onClick={() => navigate('/registrations')}
                        className="mb-1 inline-flex items-center gap-1 text-[12.5px] text-text-muted hover:text-text"
                    >
                        <ArrowLeft size={14} /> All registrations
                    </button>
                    <h1 className="text-[26px] font-bold tracking-tight text-text">
                        {created ? 'Take payment' : 'New registration'}
                    </h1>
                    <p className="mt-1 text-[14px] text-text-muted">
                        {created
                            ? `${created.registration_number} is created and holding a seat. Take the cash to issue the ticket.`
                            : 'Register an attendee at the counter. You will take the cash on the next step.'}
                    </p>
                </div>
                <StepIndicator step={created ? 2 : 1} />
            </div>

            {created ? (
                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
                    <Card className="p-6">
                        <div className="space-y-5">
                            <div className="rounded-xl border border-border bg-surface-2 p-5">
                                <p className="text-[12px] font-semibold uppercase tracking-wider text-text-faint">Amount due</p>
                                <p className="mt-1 text-[32px] font-semibold tabular-nums text-text">{money(amountDue)}</p>
                                <p className="mt-1 text-[13px] text-text-muted">
                                    {created.attendee?.full_name} · {adultsCount} adult{adultsCount === 1 ? '' : 's'}
                                    {created.children_count > 0 && `, ${created.children_count} child`}
                                    {created.infants_count > 0 && `, ${created.infants_count} infant (free)`}
                                </p>
                            </div>

                            <Field
                                id="receipt-reference"
                                label="Receipt number"
                                optional
                                hint="From the paper receipt book, if the desk keeps one."
                            >
                                <Input
                                    id="receipt-reference"
                                    value={receiptReference}
                                    onChange={(e) => setReceiptReference(e.target.value)}
                                    placeholder="RCPT-0042"
                                />
                            </Field>

                            <p className="text-[12.5px] text-text-muted">
                                Recording the cash issues the ticket and sends the confirmation email and SMS with the QR
                                code. The full amount must be taken — this system does not record part payments.
                            </p>

                            {!canCollectCash && (
                                <p className="text-[12.5px] font-medium text-critical-fg">
                                    You do not have permission to record payments. The registration is saved and holding a
                                    seat — someone with the cash-collection permission can settle it from its page.
                                </p>
                            )}

                            <div className="flex flex-wrap items-center justify-end gap-2 border-t border-border pt-4">
                                <Button variant="ghost" onClick={() => navigate(`/registrations/${created.ulid}`)}>
                                    Take payment later
                                </Button>
                                <Button
                                    disabled={!pendingPayment || !canCollectCash || collectMutation.isPending}
                                    onClick={() =>
                                        pendingPayment &&
                                        collectMutation.mutate({ paymentUlid: pendingPayment.ulid, amount: pendingPayment.amount_due_paisa })
                                    }
                                >
                                    <Banknote size={14} />
                                    {collectMutation.isPending ? 'Recording…' : `Cash received — ${money(amountDue)}`}
                                </Button>
                            </div>
                        </div>
                    </Card>

                    <Card className="h-fit p-5">
                        <h3 className="text-[12px] font-semibold uppercase tracking-wider text-text-faint">Registration</h3>
                        <dl className="mt-3 space-y-2 text-[13px]">
                            <div className="flex justify-between gap-3">
                                <dt className="text-text-faint">Number</dt>
                                <dd className="font-medium text-text">{created.registration_number}</dd>
                            </div>
                            <div className="flex justify-between gap-3">
                                <dt className="text-text-faint">Ticket type</dt>
                                <dd className="text-right font-medium text-text">{created.ticket_type?.name ?? '—'}</dd>
                            </div>
                            <div className="flex justify-between gap-3">
                                <dt className="text-text-faint">Participant</dt>
                                <dd className="font-medium text-text">
                                    {created.attendee?.participant_type ? titleCase(created.attendee.participant_type) : '—'}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-3">
                                <dt className="text-text-faint">Mobile</dt>
                                <dd className="font-medium text-text">{created.attendee?.mobile ?? '—'}</dd>
                            </div>
                        </dl>
                    </Card>
                </div>
            ) : (
                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
                    <Card className="p-6">
                        <div className="space-y-8">
                            <FormSection title="Ticket">
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <Field id="ticket-type" label="Ticket type" error={err('ticket_type_ulid')}>
                                        <Select
                                            id="ticket-type"
                                            value={ticketTypeUlid}
                                            aria-invalid={Boolean(err('ticket_type_ulid'))}
                                            onChange={(e) => {
                                                setTicketTypeUlid(e.target.value);
                                                clearError('ticket_type_ulid');
                                            }}
                                        >
                                            <option value="">Select…</option>
                                            {sellableTypes.map((t) => (
                                                <option key={t.ulid} value={t.ulid}>
                                                    {t.name} — {money(t.base_price_tk)}
                                                </option>
                                            ))}
                                        </Select>
                                    </Field>
                                </div>
                            </FormSection>

                            <AttendeeFields
                                mode="create"
                                form={form}
                                set={set}
                                err={err}
                                allowedParticipantTypes={allowedParticipantTypes}
                            />

                            <FormSection
                                title="Family"
                                description="Anyone coming with them. A child under the ticket type's free age is not charged but is still admitted — which is why the age matters."
                            >
                                {guests.length === 0 && (
                                    <p className="text-[12.5px] text-text-muted">Nobody added — registering as a party of one.</p>
                                )}
                                <div className="space-y-2">
                                    {guests.map((guest, i) => (
                                        <div key={guest.key} className="grid items-end gap-2 sm:grid-cols-[1fr_auto_auto_auto]">
                                            <div>
                                                <Label htmlFor={`guest-name-${guest.key}`}>Name</Label>
                                                <Input
                                                    id={`guest-name-${guest.key}`}
                                                    value={guest.full_name}
                                                    onChange={(e) =>
                                                        setGuests((g) => g.map((x, j) => (i === j ? { ...x, full_name: e.target.value } : x)))
                                                    }
                                                />
                                            </div>
                                            <div>
                                                <Label htmlFor={`guest-relation-${guest.key}`}>Relation</Label>
                                                <Select
                                                    id={`guest-relation-${guest.key}`}
                                                    value={guest.relation}
                                                    onChange={(e) =>
                                                        setGuests((g) =>
                                                            g.map((x, j) =>
                                                                i === j ? { ...x, relation: e.target.value as GuestDraft['relation'] } : x,
                                                            ),
                                                        )
                                                    }
                                                >
                                                    <option value="spouse">Spouse</option>
                                                    <option value="child">Child</option>
                                                    <option value="parent">Parent</option>
                                                    <option value="sibling">Sibling</option>
                                                    <option value="other">Other</option>
                                                </Select>
                                            </div>
                                            <div>
                                                <Label htmlFor={`guest-age-group-${guest.key}`}>Age group</Label>
                                                <Select
                                                    id={`guest-age-group-${guest.key}`}
                                                    value={guest.age_group}
                                                    onChange={(e) =>
                                                        setGuests((g) =>
                                                            g.map((x, j) =>
                                                                i === j ? { ...x, age_group: e.target.value as GuestDraft['age_group'] } : x,
                                                            ),
                                                        )
                                                    }
                                                >
                                                    <option value="adult">Adult</option>
                                                    <option value="child">Child</option>
                                                </Select>
                                            </div>
                                            <div className="flex items-end gap-2">
                                                {guest.age_group === 'child' && (
                                                    <div className="w-20">
                                                        <Label htmlFor={`guest-age-${guest.key}`}>Age</Label>
                                                        <Input
                                                            id={`guest-age-${guest.key}`}
                                                            type="number"
                                                            min={0}
                                                            value={guest.age ?? ''}
                                                            onChange={(e) =>
                                                                setGuests((g) =>
                                                                    g.map((x, j) =>
                                                                        i === j
                                                                            ? { ...x, age: e.target.value === '' ? null : Number(e.target.value) }
                                                                            : x,
                                                                    ),
                                                                )
                                                            }
                                                        />
                                                    </div>
                                                )}
                                                <Button
                                                    variant="ghost"
                                                    aria-label="Remove guest"
                                                    onClick={() => setGuests((g) => g.filter((_, j) => j !== i))}
                                                >
                                                    <Trash2 size={14} />
                                                </Button>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                                <Button variant="ghost" onClick={() => setGuests((g) => [...g, emptyGuest()])}>
                                    <Plus size={14} />
                                    Add family member
                                </Button>
                            </FormSection>

                            <FormSection title="Notes">
                                <Field
                                    id="special-notes"
                                    label="Special notes"
                                    optional
                                    error={err('special_notes')}
                                    hint="Dietary needs, accessibility requirements, anything the gate should know."
                                >
                                    <Textarea
                                        id="special-notes"
                                        rows={2}
                                        value={specialNotes}
                                        onChange={(e) => {
                                            setSpecialNotes(e.target.value);
                                            clearError('special_notes');
                                        }}
                                    />
                                </Field>
                            </FormSection>
                        </div>
                    </Card>

                    {/* Sticky, so the party summary and the action are in
                        view however far down the form the operator is. */}
                    <div className="lg:sticky lg:top-20 lg:self-start">
                        <Card className="p-5">
                            <h3 className="text-[12px] font-semibold uppercase tracking-wider text-text-faint">Summary</h3>
                            <dl className="mt-3 space-y-2 text-[13px]">
                                <div className="flex justify-between gap-3">
                                    <dt className="text-text-faint">Ticket type</dt>
                                    <dd className="text-right font-medium text-text">{selectedType?.name ?? 'Not chosen'}</dd>
                                </div>
                                <div className="flex justify-between gap-3">
                                    <dt className="text-text-faint">Participant</dt>
                                    <dd className="font-medium text-text">{titleCase(form.participant_type)}</dd>
                                </div>
                                <div className="flex justify-between gap-3">
                                    <dt className="text-text-faint">Party</dt>
                                    <dd className="font-medium text-text">
                                        {adultsCount} adult{adultsCount === 1 ? '' : 's'}
                                        {childrenCount > 0 && `, ${childrenCount} child${childrenCount === 1 ? '' : 'ren'}`}
                                    </dd>
                                </div>
                            </dl>
                            <p className="mt-4 text-[12px] text-text-faint">
                                The amount due is worked out by the server from the ticket type's rates and the party, and shown
                                on the next step.
                            </p>
                            <div className="mt-5 flex flex-col gap-2">
                                <Button disabled={!ticketTypeUlid || createMutation.isPending} onClick={submitDetails}>
                                    {createMutation.isPending ? 'Creating…' : 'Continue to payment'}
                                </Button>
                                <Button variant="ghost" onClick={() => navigate('/registrations')}>
                                    Cancel
                                </Button>
                            </div>
                        </Card>
                    </div>
                </div>
            )}
        </div>
    );
}
