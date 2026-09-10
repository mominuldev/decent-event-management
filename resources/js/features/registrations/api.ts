import { ApiRequestError, api, toApiError } from '@/lib/api';
import { randomId } from '@/lib/id';
import type { PaginatedResponse } from '@/lib/pagination';
import { unwrap } from '@/lib/pagination';
import type { SortParams } from '@/lib/sorting';
import type {
    CollectCashPayload,
    CreateRegistrationPayload,
    Registration,
    RegistrationPayment,
    RegistrationStatus,
    UpdateRegistrationPayload,
} from './types';

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

/**
 * Registers somebody at the desk. Creates the record and a `pending` cash
 * payment; it does **not** take the money — `collectCash()` does that, and
 * that is what issues the ticket and sends the confirmation.
 *
 * The Idempotency-Key is generated here, once per submission, so a
 * double-tapped button replays the first response rather than creating a
 * second registration and a second cash liability.
 *
 * Throws ApiRequestError rather than a bare Error, because this endpoint's
 * 422s are the ones worth reading: `already_registered` names the existing
 * registration, and a validation failure names the field.
 */
export async function createRegistration(payload: CreateRegistrationPayload): Promise<Registration> {
    try {
        const { data } = await api.post('/admin/registrations', payload, {
            headers: { 'Idempotency-Key': randomId() },
        });
        return unwrap<Registration>(data);
    } catch (e) {
        throw new ApiRequestError(e);
    }
}

/**
 * Records cash handed over and settles the payment, which queues the
 * ticket and its email/SMS/WhatsApp confirmation.
 */
export async function collectCash(paymentUlid: string, payload: CollectCashPayload): Promise<RegistrationPayment> {
    try {
        const { data } = await api.post(`/admin/payments/${paymentUlid}/collect-cash`, payload);
        return unwrap<RegistrationPayment>(data);
    } catch (e) {
        throw new ApiRequestError(e);
    }
}
