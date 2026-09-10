import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Banknote, Plus, Trash2 } from 'lucide-react';
import { Button, Field, FormSection, Input, Label, Select, Textarea } from '@/components/ui';
import { Dialog } from '@/components/Dialog';
import { useToast } from '@/components/Toast';
import { ApiRequestError } from '@/lib/api';
import { money } from '@/lib/cn';
import { randomId } from '@/lib/id';
import { fetchTicketTypes } from '@/features/tickets/api';
import type { TicketType } from '@/features/tickets/types';
import * as registrationsApi from './api';
import {
    BATCH_YEAR_PARTICIPANT_TYPES,
    PARTICIPANT_TYPES,
    type CreateRegistrationPayload,
    type Registration,
    type RegistrationGuestPayload,
} from './types';

/**
 * Registering a walk-up at the desk and taking their cash.
 *
 * **Two server calls, deliberately, and the split is the design.** Step one
 * creates the registration and returns the authoritative total; step two
 * takes the money against it. The alternative — one call that does both —
 * would mean this dialog computing the price itself in order to show the
 * operator what to charge, and that formula (tiered base rate, extra
 * adults, extra children, free infants by age) already exists twice: on the
 * server in CreateRegistration, and mirrored on the public site. A third
 * copy behind a till is where it would first quietly disagree, and the
 * symptom would be undercharging real people.
 *
 * It also makes the abandoned case safe: if step two never happens, the
 * registration is sitting in the list as `pending_payment` and can be
 * collected against later — including by the same button on the row.
 */

function titleCase(s: string) {
    return s.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

type GuestDraft = RegistrationGuestPayload & { key: string };

function emptyGuest(): GuestDraft {
    return { key: randomId(), full_name: '', relation: 'spouse', age_group: 'adult', age: null };
}

const EMPTY_FORM = {
    full_name: '',
    full_name_bn: '',
    father_name: '',
    mobile: '',
    email: '',
    gender: 'male' as 'male' | 'female',
    occupation: '',
    designation: '',
    organization: '',
    current_address: '',
    participant_type: 'former_student',
    ssc_batch_year: '',
    ticket_type_ulid: '',
    special_notes: '',
};

export function NewRegistrationDialog({
    open,
    onClose,
    canCollectCash,
}: {
    open: boolean;
    onClose: () => void;
    /** Whether this operator may take the money as well as create the record. */
    canCollectCash: boolean;
}) {
    const { push } = useToast();
    const queryClient = useQueryClient();

    const [form, setForm] = useState(EMPTY_FORM);
    const [guests, setGuests] = useState<GuestDraft[]>([]);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
    const [created, setCreated] = useState<Registration | null>(null);
    const [receiptReference, setReceiptReference] = useState('');

    const ticketTypesQuery = useQuery({ queryKey: ['ticket-types'], queryFn: fetchTicketTypes, enabled: open });

    // Reset on every open, so a previous walk-up's details can never be
    // saved against the next person in the queue.
    useEffect(() => {
        if (!open) return;
        setForm(EMPTY_FORM);
        setGuests([]);
        setFieldErrors({});
        setCreated(null);
        setReceiptReference('');
    }, [open]);

    const sellableTypes = useMemo(
        () => (ticketTypesQuery.data ?? []).filter((t) => t.is_active),
        [ticketTypesQuery.data],
    );

    const selectedType: TicketType | undefined = useMemo(
        () => sellableTypes.find((t) => t.ulid === form.ticket_type_ulid),
        [sellableTypes, form.ticket_type_ulid],
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
    const needsBatchYear = BATCH_YEAR_PARTICIPANT_TYPES.includes(form.participant_type);

    const set = (key: keyof typeof EMPTY_FORM, value: string) => {
        setForm((f) => ({ ...f, [key]: value }));
        setFieldErrors((e) => {
            if (!e[key]) return e;
            const next = { ...e };
            delete next[key];
            return next;
        });
    };

    const createMutation = useMutation({
        mutationFn: (payload: CreateRegistrationPayload) => registrationsApi.createRegistration(payload),
        onSuccess: (registration) => {
            setCreated(registration);
            setFieldErrors({});
            queryClient.invalidateQueries({ queryKey: ['registrations'] });
        },
        onError: (e: Error) => {
            setFieldErrors(e instanceof ApiRequestError ? (e.errors ?? {}) : {});
            push('critical', e.message);
        },
    });

    const collectMutation = useMutation({
        mutationFn: ({ paymentUlid, amount }: { paymentUlid: string; amount: number }) =>
            registrationsApi.collectCash(paymentUlid, {
                amount_received_paisa: amount,
                receipt_reference: receiptReference.trim() || null,
            }),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['registrations'] });
            push(
                'success',
                `${created?.registration_number} paid. The ticket has been issued and the confirmation sent.`,
            );
            onClose();
        },
        onError: (e: Error) => push('critical', e.message),
    });

    const submitDetails = () => {
        const payload: CreateRegistrationPayload = {
            full_name: form.full_name.trim(),
            full_name_bn: form.full_name_bn.trim(),
            father_name: form.father_name.trim(),
            mobile: form.mobile.trim(),
            email: form.email.trim() || null,
            gender: form.gender,
            occupation: form.occupation.trim(),
            designation: form.designation.trim() || null,
            organization: form.organization.trim() || null,
            current_address: form.current_address.trim(),
            participant_type: form.participant_type,
            ssc_batch_year: needsBatchYear && form.ssc_batch_year ? Number(form.ssc_batch_year) : null,
            ticket_type_ulid: form.ticket_type_ulid,
            participation_type: participationType,
            adults_count: adultsCount,
            // Every child attending, infants included. Which of them are
            // free is decided by the server from each guest's own age.
            children_count: childrenCount,
            guests: guests.map(({ key: _key, ...g }) => ({ ...g, age: g.age === null ? null : Number(g.age) })),
            special_notes: form.special_notes.trim() || null,
        };

        createMutation.mutate(payload);
    };

    const pendingPayment = created?.payments?.find((p) => p.status === 'pending' || p.status === 'awaiting_verification');
    const err = (field: string) => fieldErrors[field]?.[0];

    return (
        <Dialog
            open={open}
            onClose={onClose}
            title={created ? 'Take payment' : 'New registration'}
            description={
                created
                    ? `${created.registration_number} — created and holding a seat. Take the cash to issue the ticket.`
                    : 'Register an attendee at the counter. You will take the cash on the next step.'
            }
            className="max-w-2xl"
            footer={
                created ? (
                    <div className="flex items-center justify-end gap-2">
                        <Button variant="ghost" onClick={onClose}>
                            Take payment later
                        </Button>
                        <Button
                            disabled={!pendingPayment || !canCollectCash || collectMutation.isPending}
                            onClick={() =>
                                pendingPayment &&
                                collectMutation.mutate({
                                    paymentUlid: pendingPayment.ulid,
                                    amount: pendingPayment.amount_due_paisa,
                                })
                            }
                        >
                            <Banknote size={14} />
                            {collectMutation.isPending
                                ? 'Recording…'
                                : `Cash received — ${money(pendingPayment?.amount_due_paisa ?? created.total_paisa)}`}
                        </Button>
                    </div>
                ) : (
                    <div className="flex items-center justify-end gap-2">
                        <Button variant="ghost" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button
                            disabled={!form.ticket_type_ulid || createMutation.isPending}
                            onClick={submitDetails}
                        >
                            {createMutation.isPending ? 'Creating…' : 'Continue to payment'}
                        </Button>
                    </div>
                )
            }
        >
            {created ? (
                <div className="space-y-4">
                    <div className="rounded-xl border border-border bg-surface-2 p-4">
                        <p className="text-[12px] font-semibold uppercase tracking-wider text-text-faint">Amount due</p>
                        <p className="mt-1 text-[28px] font-semibold tabular-nums text-text">
                            {money(pendingPayment?.amount_due_paisa ?? created.total_paisa)}
                        </p>
                        <p className="mt-1 text-[12.5px] text-text-muted">
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
                            seat — someone with the cash-collection permission can settle it from the list.
                        </p>
                    )}
                </div>
            ) : (
                <div className="space-y-6">
                    <FormSection title="Ticket">
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field id="ticket-type" label="Ticket type" error={err('ticket_type_ulid')}>
                                <Select
                                    id="ticket-type"
                                    value={form.ticket_type_ulid}
                                    onChange={(e) => set('ticket_type_ulid', e.target.value)}
                                >
                                    <option value="">Select…</option>
                                    {sellableTypes.map((t) => (
                                        <option key={t.ulid} value={t.ulid}>
                                            {t.name} — {money(t.base_price_paisa)}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                            <Field id="participant-type" label="Participant type" error={err('participant_type')}>
                                <Select
                                    id="participant-type"
                                    value={form.participant_type}
                                    onChange={(e) => set('participant_type', e.target.value)}
                                >
                                    {allowedParticipantTypes.map((p) => (
                                        <option key={p} value={p}>
                                            {titleCase(p)}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                        </div>
                    </FormSection>

                    <FormSection title="Attendee">
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field id="full-name" label="Full name" error={err('full_name')}>
                                <Input
                                    id="full-name"
                                    value={form.full_name}
                                    onChange={(e) => set('full_name', e.target.value)}
                                />
                            </Field>
                            {/* The name the ticket PDF and the confirmation
                                email actually print. */}
                            <Field id="full-name-bn" label="Name in Bangla" error={err('full_name_bn')}>
                                <Input
                                    id="full-name-bn"
                                    lang="bn"
                                    value={form.full_name_bn}
                                    onChange={(e) => set('full_name_bn', e.target.value)}
                                />
                            </Field>
                            <Field id="father-name" label="Father's name" error={err('father_name')}>
                                <Input
                                    id="father-name"
                                    value={form.father_name}
                                    onChange={(e) => set('father_name', e.target.value)}
                                />
                            </Field>
                            <Field
                                id="mobile"
                                label="Mobile"
                                error={err('mobile')}
                                hint="Identifies the attendee, and is how they sign in."
                            >
                                <Input
                                    id="mobile"
                                    value={form.mobile}
                                    onChange={(e) => set('mobile', e.target.value)}
                                    placeholder="01712345678"
                                />
                            </Field>
                            <Field id="email" label="Email" optional error={err('email')} hint="Where the ticket is sent.">
                                <Input
                                    id="email"
                                    type="email"
                                    value={form.email}
                                    onChange={(e) => set('email', e.target.value)}
                                />
                            </Field>
                            <Field id="gender" label="Gender" error={err('gender')}>
                                <Select id="gender" value={form.gender} onChange={(e) => set('gender', e.target.value)}>
                                    <option value="male">Male</option>
                                    <option value="female">Female</option>
                                </Select>
                            </Field>
                            <Field id="occupation" label="Occupation" error={err('occupation')}>
                                <Input
                                    id="occupation"
                                    value={form.occupation}
                                    onChange={(e) => set('occupation', e.target.value)}
                                />
                            </Field>
                            {needsBatchYear && (
                                <Field id="batch-year" label="SSC batch year" error={err('ssc_batch_year')}>
                                    <Input
                                        id="batch-year"
                                        type="number"
                                        value={form.ssc_batch_year}
                                        onChange={(e) => set('ssc_batch_year', e.target.value)}
                                    />
                                </Field>
                            )}
                            <Field id="designation" label="Designation" optional error={err('designation')}>
                                <Input
                                    id="designation"
                                    value={form.designation}
                                    onChange={(e) => set('designation', e.target.value)}
                                />
                            </Field>
                            <Field id="organization" label="Organization" optional error={err('organization')}>
                                <Input
                                    id="organization"
                                    value={form.organization}
                                    onChange={(e) => set('organization', e.target.value)}
                                />
                            </Field>
                        </div>
                        {/* Textarea, not an input: a Bangladeshi address runs
                            to two or three lines and a box that scrolls
                            sideways hides what was already typed. */}
                        <Field id="current-address" label="Current address" error={err('current_address')}>
                            <Textarea
                                id="current-address"
                                rows={2}
                                value={form.current_address}
                                onChange={(e) => set('current_address', e.target.value)}
                            />
                        </Field>
                    </FormSection>

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
                                                setGuests((g) =>
                                                    g.map((x, j) => (i === j ? { ...x, full_name: e.target.value } : x)),
                                                )
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
                                                        i === j
                                                            ? { ...x, relation: e.target.value as GuestDraft['relation'] }
                                                            : x,
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
                                                        i === j
                                                            ? { ...x, age_group: e.target.value as GuestDraft['age_group'] }
                                                            : x,
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
                                                                    ? {
                                                                          ...x,
                                                                          age:
                                                                              e.target.value === ''
                                                                                  ? null
                                                                                  : Number(e.target.value),
                                                                      }
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
                                value={form.special_notes}
                                onChange={(e) => set('special_notes', e.target.value)}
                            />
                        </Field>
                    </FormSection>
                </div>
            )}
        </Dialog>
    );
}
