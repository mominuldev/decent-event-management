export type ParticipantType = 'current_student' | 'former_student' | 'teacher' | 'staff' | 'guardian' | 'guest' | 'sponsor' | 'other';

export interface Attendee {
    ulid: string;
    full_name: string;
    full_name_bn: string | null;
    father_name: string | null;
    mobile: string;
    email: string | null;
    whatsapp_number: string | null;
    gender: string | null;
    date_of_birth: string | null;
    /**
     * Absent — not null — for a caller the API will not show it to: the
     * unauthenticated public registration reader. The admin console always
     * holds an `admin` token, so it always gets it.
     */
    nid_number?: string | null;
    /** Whether one is on file, readable even where the number itself is not. */
    nid_number_set: boolean;
    occupation: string | null;
    designation: string | null;
    organization: string | null;
    participant_type: ParticipantType;
    ssc_batch_year: number | null;
    current_class: string | null;
    tshirt_required: boolean;
    tshirt_size: string | null;
    address_district: string | null;
    current_address: string | null;
    post_office: string | null;
    upazila: string | null;
    country: string | null;
    blood_group: string | null;
    emergency_contact_name: string | null;
    emergency_contact_phone: string | null;
    notes: string | null;
    is_verified: boolean;
    /** When staff confirmed the identity; null while unverified. */
    verified_at: string | null;
    profile_photo_url?: string | null;
    /** Small rendition for avatars; falls back server-side to the full photo. */
    profile_photo_thumb_url?: string | null;
    created_at: string;
}

export interface UpdateAttendeePayload {
    full_name?: string;
    full_name_bn?: string | null;
    father_name?: string | null;
    mobile?: string;
    email?: string | null;
    occupation?: string | null;
    current_address?: string | null;
    post_office?: string | null;
    upazila?: string | null;
    address_district?: string | null;
    /** ISO date (YYYY-MM-DD) — the API refuses a future one. */
    date_of_birth?: string | null;
    /** 10, 13 or 17 digits; punctuation is stripped server-side. */
    nid_number?: string | null;
    blood_group?: string | null;
    participant_type?: ParticipantType;
    ssc_batch_year?: number | null;
    /** One of CURRENT_CLASSES; omit the key to leave a legacy free-text value as recorded. */
    current_class?: string | null;
    whatsapp_number?: string | null;
    designation?: string | null;
    organization?: string | null;
    tshirt_required?: boolean;
    tshirt_size?: TshirtSize | null;
    /** ISO 3166-1 alpha-2 — the column is CHAR(2) NOT NULL, so never blank. */
    country?: string;
    emergency_contact_name?: string | null;
    emergency_contact_phone?: string | null;
    is_verified?: boolean;
    notes?: string | null;
}

/**
 * Mirrors `Attendee::CURRENT_CLASSES` — the classes a current student may be
 * in. The value stored is the bare number; the label is for the screen.
 */
export const CURRENT_CLASSES: { value: string; label: string }[] = [
    { value: '6', label: 'Class Six' },
    { value: '7', label: 'Class Seven' },
    { value: '8', label: 'Class Eight' },
    { value: '9', label: 'Class Nine' },
    { value: 'new_10', label: 'New Ten' },
    { value: '10', label: 'Class Ten' },
];

export function isCatalogueClass(value: string): boolean {
    return CURRENT_CLASSES.some((c) => c.value === value);
}

export function currentClassLabel(value: string | null | undefined): string | null {
    if (!value) return null;
    return CURRENT_CLASSES.find((c) => c.value === value)?.label ?? value;
}

/** Mirrors `Attendee::TSHIRT_SIZES`. */
export const TSHIRT_SIZES = ['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL'] as const;

export type TshirtSize = (typeof TSHIRT_SIZES)[number];

export const SSC_BATCH_YEAR_MIN = 1971;
/** Floored at 2026 so the list never shrinks below the range the event was launched with. */
export const SSC_BATCH_YEAR_MAX = Math.max(2026, new Date().getFullYear());

/** Newest batch first — most attendees are recent batches, so they sort to the top of the picker. */
export const SSC_BATCH_YEARS: number[] = Array.from(
    { length: SSC_BATCH_YEAR_MAX - SSC_BATCH_YEAR_MIN + 1 },
    (_, i) => SSC_BATCH_YEAR_MAX - i,
);

export const PARTICIPANT_TYPES: { value: ParticipantType; label: string }[] = [
    { value: 'current_student', label: 'Current student' },
    { value: 'former_student', label: 'Former student' },
    { value: 'teacher', label: 'Teacher' },
    { value: 'staff', label: 'Staff' },
    { value: 'guardian', label: 'Guardian' },
    { value: 'guest', label: 'Guest' },
    { value: 'sponsor', label: 'Sponsor' },
    { value: 'other', label: 'Other' },
];

/**
 * The blood groups the API accepts, mirroring `Attendee::BLOOD_GROUPS`.
 * A fixed list rather than free text because this is read in an emergency —
 * `O positive` and `O+` are one answer to a person and two unsearchable
 * strings to whoever is filtering for a donor.
 */
export const BLOOD_GROUPS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'] as const;

export type BloodGroup = (typeof BLOOD_GROUPS)[number];
