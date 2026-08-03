import { api, toApiError } from '@/lib/api';
import type { PaginatedResponse } from '@/lib/pagination';
import { unwrap } from '@/lib/pagination';
import type { Attendee, ParticipantType, UpdateAttendeePayload } from './types';

export interface AttendeeFilters {
    search?: string;
    participant_type?: ParticipantType | '';
    ssc_batch_year?: number | '';
    page?: number;
    per_page?: number;
}

export async function fetchAttendees(filters: AttendeeFilters): Promise<PaginatedResponse<Attendee>> {
    const { data } = await api.get('/admin/attendees', {
        params: {
            search: filters.search || undefined,
            participant_type: filters.participant_type || undefined,
            ssc_batch_year: filters.ssc_batch_year || undefined,
            page: filters.page,
            per_page: filters.per_page,
        },
    });
    return data as PaginatedResponse<Attendee>;
}

export async function fetchAttendee(ulid: string): Promise<Attendee> {
    const { data } = await api.get(`/admin/attendees/${ulid}`);
    return unwrap<Attendee>(data);
}

export async function updateAttendee(ulid: string, payload: UpdateAttendeePayload): Promise<Attendee> {
    try {
        const { data } = await api.patch(`/admin/attendees/${ulid}`, payload);
        return unwrap<Attendee>(data);
    } catch (e) {
        throw new Error(toApiError(e).message);
    }
}

export async function deleteAttendee(ulid: string): Promise<void> {
    try {
        await api.delete(`/admin/attendees/${ulid}`);
    } catch (e) {
        throw new Error(toApiError(e).message);
    }
}
