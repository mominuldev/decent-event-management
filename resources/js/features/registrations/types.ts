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
    children_count: number;
    subtotal_paisa: number;
    discount_paisa: number;
    total_paisa: number;
    currency: string;
    discount_code: string | null;
    comments: string | null;
    special_notes: string | null;
    source: string | null;
    submitted_at: string | null;
    confirmed_at: string | null;
    cancelled_at: string | null;
    created_at: string;
    attendee?: { ulid?: string; full_name?: string; mobile?: string } | null;
    ticket_type?: { ulid?: string; name?: string; code?: string } | null;
    guests?: RegistrationGuest[];
    event_session?: { ulid?: string; name?: string; venue?: string } | null;
}

export interface UpdateRegistrationPayload {
    status?: RegistrationStatus;
    comments?: string | null;
    special_notes?: string | null;
}
