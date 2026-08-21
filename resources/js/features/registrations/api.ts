import { api, toApiError } from '@/lib/api';
import type { PaginatedResponse } from '@/lib/pagination';
import { unwrap } from '@/lib/pagination';
import type { SortParams } from '@/lib/sorting';
import type { Registration, RegistrationStatus, UpdateRegistrationPayload } from './types';

export interface RegistrationFilters extends SortParams {
    search?: string;
    status?: RegistrationStatus | '';
    ticket_type_id?: number | '';
    date_from?: string;
    date_to?: string;
    page?: number;
    per_page?: number;
}

export async function fetchRegistrations(filters: RegistrationFilters): Promise<PaginatedResponse<Registration>> {
    const { data } = await api.get('/admin/registrations', {
        params: {
            search: filters.search || undefined,
            status: filters.status || undefined,
            ticket_type_id: filters.ticket_type_id || undefined,
            date_from: filters.date_from || undefined,
            date_to: filters.date_to || undefined,
            sort: filters.sort,
            direction: filters.direction,
            page: filters.page,
            per_page: filters.per_page,
        },
    });
    return data as PaginatedResponse<Registration>;
}

export async function fetchRegistration(ulid: string): Promise<Registration> {
    const { data } = await api.get(`/admin/registrations/${ulid}`);
    return unwrap<Registration>(data);
}

export async function updateRegistration(ulid: string, payload: UpdateRegistrationPayload): Promise<Registration> {
    try {
        const { data } = await api.patch(`/admin/registrations/${ulid}`, payload);
        return unwrap<Registration>(data);
    } catch (e) {
        throw new Error(toApiError(e).message);
    }
}

export async function deleteRegistration(ulid: string): Promise<void> {
    try {
        await api.delete(`/admin/registrations/${ulid}`);
    } catch (e) {
        throw new Error(toApiError(e).message);
    }
}
