export type ParticipantType = 'current_student' | 'former_student' | 'teacher' | 'staff' | 'guardian' | 'guest' | 'sponsor' | 'other';

export interface Attendee {
    ulid: string;
    full_name: string;
    full_name_bn: string | null;
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
    country: string | null;
    blood_group: string | null;
    emergency_contact_name: string | null;
    emergency_contact_phone: string | null;
    notes: string | null;
    is_verified: boolean;
    profile_photo_url?: string | null;
    created_at: string;
}

export interface UpdateAttendeePayload {
    full_name?: string;
    full_name_bn?: string | null;
    mobile?: string;
    email?: string | null;
    participant_type?: ParticipantType;
    ssc_batch_year?: number | null;
    is_verified?: boolean;
    notes?: string | null;
}

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
