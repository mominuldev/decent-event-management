import axios from 'axios';
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

export type ExportFormat = 'xlsx' | 'pdf';

/**
 * Downloads the filtered attendee list and hands it to the browser.
 *
 * The response is a binary attachment rather than JSON, so this is the one
 * call in the feature that overrides `responseType`. Two consequences worth
 * keeping in mind before editing it:
 *
 *  - **An error arrives as a Blob too.** With `responseType: 'blob'`, axios
 *    does not parse the API's `{code, message}` envelope on a 422 — it hands
 *    back the JSON as bytes. Without the text() read below, a "narrow your
 *    filters" refusal would surface as an unreadable `[object Blob]`.
 *  - **The filename comes from the server** via Content-Disposition, so the
 *    timestamp in it matches when the file was actually generated.
 */
export async function exportAttendees(filters: AttendeeFilters, format: ExportFormat): Promise<void> {
    try {
        const response = await api.get('/admin/attendees/export', {
            params: {
                format,
                search: filters.search || undefined,
                participant_type: filters.participant_type || undefined,
                ssc_batch_year: filters.ssc_batch_year || undefined,
            },
            responseType: 'blob',
        });

        const blob = response.data as Blob;
        const url = URL.createObjectURL(blob);
        const anchor = document.createElement('a');

        anchor.href = url;
        anchor.download = filenameFromDisposition(response.headers['content-disposition']) ?? `attendees.${format}`;
        document.body.appendChild(anchor);
        anchor.click();
        anchor.remove();

        // Revoked on the next tick rather than immediately: Safari aborts the
        // download if the object URL is released in the same task as click().
        setTimeout(() => URL.revokeObjectURL(url), 0);
    } catch (e) {
        throw new Error(await blobAwareErrorMessage(e));
    }
}

function filenameFromDisposition(disposition: unknown): string | null {
    if (typeof disposition !== 'string') return null;
    const match = /filename="?([^";]+)"?/i.exec(disposition);
    return match ? match[1] : null;
}

/** Reads the API error envelope back out of a Blob response body. */
async function blobAwareErrorMessage(e: unknown): Promise<string> {
    const body = axios.isAxiosError(e) ? e.response?.data : undefined;

    if (body instanceof Blob) {
        try {
            const parsed = JSON.parse(await body.text()) as { message?: string };
            if (parsed.message) return parsed.message;
        } catch {
            // Not JSON — fall through to the generic message below.
        }
    }

    return toApiError(e).message;
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
