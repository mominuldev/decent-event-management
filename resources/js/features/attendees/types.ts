export type ParticipantType = 'current_student' | 'former_student' | 'teacher' | 'staff' | 'guardian' | 'guest' | 'sponsor' | 'other';

export interface Attendee {
    ulid: string;
    full_name: string;
    full_name_bn: string | null;
    father_name: string | null;
    mobile: string;
    email: string | null;
    gender: string | null;
    date_of_birth: string | null;
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
    country: string | null;
    blood_group: string | null;
    emergency_contact_name: string | null;
    emergency_contact_phone: string | null;
    notes: string | null;
    is_verified: boolean;
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
    participant_type?: ParticipantType;
    ssc_batch_year?: number | null;
    is_verified?: boolean;
    notes?: string | null;
}

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
