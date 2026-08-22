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
}

export interface UpdateRegistrationPayload {
    status?: RegistrationStatus;
    special_notes?: string | null;
}
