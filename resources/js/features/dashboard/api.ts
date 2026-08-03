import { api } from '@/lib/api';
import type { PaginatedResponse } from '@/lib/pagination';
import type { Registration, ReportRow, TicketType } from './types';

export async function fetchRegistrations(params: { per_page: number; page?: number }): Promise<PaginatedResponse<Registration>> {
    const { data } = await api.get('/admin/registrations', { params });
    return data as PaginatedResponse<Registration>;
}

export async function fetchAttendeesCount(): Promise<number> {
    const { data } = await api.get('/admin/attendees', { params: { per_page: 1 } });
    const page = data as PaginatedResponse<unknown>;
    return page.meta?.total ?? page.data.length;
}

export async function fetchTicketsCount(): Promise<number> {
    const { data } = await api.get('/admin/tickets', { params: { per_page: 1 } });
    const page = data as PaginatedResponse<unknown>;
    return page.meta?.total ?? page.data.length;
}

export async function fetchPaymentsSucceededCount(): Promise<number> {
    const { data } = await api.get('/admin/payments', { params: { per_page: 1, status: 'succeeded' } });
    const page = data as PaginatedResponse<unknown>;
    return page.meta?.total ?? page.data.length;
}

export async function fetchTicketTypes(): Promise<TicketType[]> {
    const { data } = await api.get('/admin/ticket-types');
    return (data as { data: TicketType[] }).data;
}

/** Row/summary shapes vary per report key and aren't pinned down by the spec — rendered generically. */
export async function fetchReport(reportKey: string): Promise<ReportRow[] | ReportRow> {
    const { data } = await api.get(`/admin/reports/${reportKey}`);
    return (data as { data: ReportRow[] | ReportRow }).data;
}
