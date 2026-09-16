import { api } from '@/lib/api';
import type { PaginatedResponse } from '@/lib/pagination';
import type { Registration, ReportRow, TicketType } from './types';

export async function fetchRegistrations(params: { per_page: number; page?: number; status?: string }): Promise<PaginatedResponse<Registration>> {
    const { data } = await api.get('/admin/registrations', { params });
    return data as PaginatedResponse<Registration>;
}

/**
 * A count is a one-row page's `meta.total` — every admin list is paginated
 * server-side, and there is no dedicated count endpoint, so this is the
 * cheapest honest way to ask "how many".
 */
async function countOf(path: string, params: Record<string, unknown> = {}): Promise<number> {
    const { data } = await api.get(path, { params: { ...params, per_page: 1 } });
    const page = data as PaginatedResponse<unknown>;
    return page.meta?.total ?? page.data.length;
}

export const fetchRegistrationsCount = (status?: string) => countOf('/admin/registrations', status ? { status } : {});
export const fetchAttendeesCount = () => countOf('/admin/attendees');
export const fetchTicketsCount = () => countOf('/admin/tickets');
export const fetchPaymentsSucceededCount = () => countOf('/admin/payments', { status: 'succeeded' });

export async function fetchTicketTypes(): Promise<TicketType[]> {
    const { data } = await api.get('/admin/ticket-types');
    return (data as { data: TicketType[] }).data;
}

/** Row/summary shapes vary per report key and aren't pinned down by the spec — rendered generically. */
export async function fetchReport(reportKey: string): Promise<ReportRow[] | ReportRow> {
    const { data } = await api.get(`/admin/reports/${reportKey}`);
    return (data as { data: ReportRow[] | ReportRow }).data;
}
