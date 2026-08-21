import { api, toApiError } from '@/lib/api';
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
