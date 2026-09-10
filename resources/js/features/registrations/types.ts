export type RegistrationStatus =
    | 'draft'
    | 'pending_payment'
    | 'pending_approval'
    | 'approved'
    | 'confirmed'
    | 'rejected'
    | 'cancelled';

/** Statuses an admin can set via PATCH — `confirmed` is system-driven (post-payment), never admin-set. */
export const EDITABLE_STATUSES: RegistrationStatus[] = [
    'draft',
    'pending_payment',
    'pending_approval',
    'approved',
    'rejected',
    'cancelled',
];

export interface RegistrationGuest {
    ulid: string;
    full_name: string;
    relation: string | null;
    age_group: string | null;
    age: number | null;
    gender: string | null;
    tshirt_required: boolean;
    tshirt_size: string | null;
    sort_order: number;
}

export interface Registration {
    ulid: string;
    registration_number: string;
    status: RegistrationStatus;
    participation_type: string;
    adults_count: number;
    /** The *billable* children only — the server stores the count split. */
    children_count: number;
    /** Children under the ticket type's `child_free_under_age`: never
     *  priced, always admitted. Part of the party, absent from the total. */
    infants_count: number;
    subtotal_paisa: number;
    discount_paisa: number;
    total_paisa: number;
    currency: string;
    discount_code: string | null;
    special_notes: string | null;
    source: string | null;
    submitted_at: string | null;
    confirmed_at: string | null;
    cancelled_at: string | null;
    created_at: string;
    attendee?: { ulid?: string; full_name?: string; mobile?: string; participant_type?: string | null } | null;
    /**
     * The nested resource is the full TicketTypeResource, price columns
     * included — the detail endpoint eager-loads `ticketType`. The prices
     * are optional here because the list endpoint's rows are read through
     * the same type, and a breakdown must not render half-built from a row
     * that never carried them.
     */
    ticket_type?: {
        ulid?: string;
        name?: string;
        code?: string;
        base_price_paisa?: number;
        additional_adult_price_paisa?: number;
        additional_child_price_paisa?: number;
        current_student_price_paisa?: number | null;
        base_admits?: number;
    } | null;
    guests?: RegistrationGuest[];
    event_session?: { ulid?: string; name?: string; venue?: string } | null;
    /**
     * Only ever present on admin responses — `AdminRegistrationResource`
     * adds it, and the shared resource behind the public and attendee
     * endpoints deliberately does not, because payment rows carry money and
     * contact columns and a registration ULID is the only thing guarding
     * the unauthenticated public route.
     */
    payments?: RegistrationPayment[];
}

/** The subset of PaymentResource the registrations screen reads. */
export interface RegistrationPayment {
    ulid: string;
    payment_number: string;
    method: string;
    channel: string | null;
    status: string;
    amount_due_paisa: number;
    amount_paid_paisa: number;
    currency: string;
    paid_at: string | null;
}

export interface RegistrationGuestPayload {
    full_name: string;
    relation: 'spouse' | 'child' | 'parent' | 'sibling' | 'other';
    age_group: 'adult' | 'child';
    /** Load-bearing on a child: the server decides which children are free
     *  infants from this, never from a client-supplied count. A child sent
     *  without an age is billed. */
    age?: number | null;
    gender?: 'male' | 'female';
}

/**
 * A counter registration. Mirrors StoreAdminRegistrationRequest — which
 * requires the same four profile fields the public form does, so a record
 * taken at a desk prints correctly on the ticket and in the directory PDF.
 *
 * No password and no payment method: staff never set an attendee's
 * credential, and a counter sale is always cash.
 */
export interface CreateRegistrationPayload {
    full_name: string;
    full_name_bn: string;
    father_name: string;
    mobile: string;
    email?: string | null;
    gender: 'male' | 'female';
    date_of_birth?: string | null;
    occupation: string;
    designation?: string | null;
    organization?: string | null;
    current_address: string;
    participant_type: string;
    ssc_batch_year?: number | null;
    current_class?: string | null;
    ticket_type_ulid: string;
    event_session_ulid?: string | null;
    participation_type: 'single' | 'couple' | 'family';
    adults_count: number;
    /** Every child attending, infants included. */
    children_count: number;
    guests?: RegistrationGuestPayload[];
    tshirt_required?: boolean;
    tshirt_size?: string | null;
    special_notes?: string | null;
}

export interface CollectCashPayload {
    /** Must equal the payment's `amount_due_paisa`. A counter sale settles
     *  in full or not at all. */
    amount_received_paisa: number;
    receipt_reference?: string | null;
    note?: string | null;
}

export const PARTICIPANT_TYPES = [
    'current_student',
    'former_student',
    'teacher',
    'staff',
    'guardian',
    'guest',
    'sponsor',
    'other',
] as const;

export type ParticipantType = (typeof PARTICIPANT_TYPES)[number];

/** `ssc_batch_year` is `required_if` these two, server-side. */
export const BATCH_YEAR_PARTICIPANT_TYPES: string[] = ['current_student', 'former_student'];

export interface UpdateRegistrationPayload {
    status?: RegistrationStatus;
    special_notes?: string | null;
}
