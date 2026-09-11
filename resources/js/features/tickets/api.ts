import { ApiRequestError, api, toApiError } from '@/lib/api';
import { randomId } from '@/lib/id';
import type { PaginatedResponse } from '@/lib/pagination';
import { unwrap } from '@/lib/pagination';
import type { SortParams } from '@/lib/sorting';
import type { Ticket, TicketType, TicketTypePayload } from './types';

export interface TicketFilters extends SortParams {
    status?: string;
    ticket_type_id?: number | '';
    search?: string;
    page?: number;
    per_page?: number;
}

export async function fetchTickets(filters: TicketFilters): Promise<PaginatedResponse<Ticket>> {
    const { data } = await api.get('/admin/tickets', {
        params: {
            status: filters.status || undefined,
            ticket_type_id: filters.ticket_type_id || undefined,
            search: filters.search || undefined,
            sort: filters.sort,
            direction: filters.direction,
            page: filters.page,
            per_page: filters.per_page,
        },
    });
    return data as PaginatedResponse<Ticket>;
}

export async function fetchTicket(ulid: string): Promise<Ticket> {
    const { data } = await api.get(`/admin/tickets/${ulid}`);
    return unwrap<Ticket>(data);
}

export async function voidTicket(ulid: string, voidReason: string): Promise<Ticket> {
    try {
        const { data } = await api.post(`/admin/tickets/${ulid}/void`, { void_reason: voidReason });
        return unwrap<Ticket>(data);
    } catch (e) {
        throw new Error(toApiError(e).message);
    }
}

export async function reissueTicket(ulid: string): Promise<Ticket> {
    try {
        const { data } = await api.post(`/admin/tickets/${ulid}/reissue`);
        return unwrap<Ticket>(data);
    } catch (e) {
        throw new Error(toApiError(e).message);
    }
}

export async function fetchTicketTypes(): Promise<TicketType[]> {
    const { data } = await api.get('/admin/ticket-types');
    return (data as { data: TicketType[] }).data;
}

export async function createTicketType(payload: TicketTypePayload): Promise<TicketType> {
    try {
        const { data } = await api.post('/admin/ticket-types', payload);
        return unwrap<TicketType>(data);
    } catch (e) {
        throw new Error(toApiError(e).message);
    }
}

export async function updateTicketType(ulid: string, payload: Partial<TicketTypePayload>): Promise<TicketType> {
    try {
        const { data } = await api.patch(`/admin/ticket-types/${ulid}`, payload);
        return unwrap<TicketType>(data);
    } catch (e) {
        throw new Error(toApiError(e).message);
    }
}

export async function deleteTicketType(ulid: string): Promise<void> {
    try {
        await api.delete(`/admin/ticket-types/${ulid}`);
    } catch (e) {
        throw new Error(toApiError(e).message);
    }
}

/* ------------------------------------------------------- resend a ticket */

export interface ResendResult {
    /** channel => queued | no_recipient | no_template | duplicate */
    outcomes: Record<string, string>;
    /** Requested channels whose kill switch is off — the row is queued, then cancelled at send time. */
    channels_disabled: string[];
}

export async function resendTicket(ulid: string, channels: string[]): Promise<ResendResult> {
    try {
        const { data } = await api.post(
            `/admin/tickets/${ulid}/resend`,
            { channels },
            // A resent SMS is billed, so the server refuses an unkeyed call.
            { headers: { 'Idempotency-Key': randomId() } },
        );
        return unwrap<ResendResult>(data);
    } catch (e) {
        throw new ApiRequestError(e);
    }
}

export interface ResendPreview {
    tickets: number;
    with_email: number;
    with_mobile: number;
    sms_segments_each: number;
    sms_cost_paisa_total: number;
    channels_disabled: string[];
}

export async function fetchResendPreview(filters: Pick<TicketFilters, 'status' | 'ticket_type_id' | 'search'>): Promise<ResendPreview> {
    const { data } = await api.get('/admin/tickets/resend-preview', {
        params: {
            status: filters.status || undefined,
            ticket_type_id: filters.ticket_type_id || undefined,
            search: filters.search || undefined,
        },
    });
    return unwrap<ResendPreview>(data);
}

export interface BulkResendResult {
    tickets: number;
    channels: string[];
    channels_disabled: string[];
}

export async function resendAllTickets(
    filters: Pick<TicketFilters, 'status' | 'ticket_type_id' | 'search'>,
    channels: string[],
    expectedCount: number,
): Promise<BulkResendResult> {
    try {
        const { data } = await api.post(
            '/admin/tickets/resend-all',
            {
                channels,
                // Echoed back from the preview: if the roster moved while the
                // dialog was open the server answers 409 rather than sending
                // to more people than were agreed to.
                expected_count: expectedCount,
                status: filters.status || undefined,
                ticket_type_id: filters.ticket_type_id || undefined,
                search: filters.search || undefined,
            },
            { headers: { 'Idempotency-Key': randomId() } },
        );
        return unwrap<BulkResendResult>(data);
    } catch (e) {
        throw new ApiRequestError(e);
    }
}
