import { api, toApiError } from '@/lib/api';
import type { PaginatedResponse } from '@/lib/pagination';
import { unwrap } from '@/lib/pagination';
import type { Payment, PaymentMethod, RefundResult } from './types';

export interface PaymentFilters {
    status?: string;
    method?: PaymentMethod | '';
    date_from?: string;
    date_to?: string;
    page?: number;
    per_page?: number;
}

export async function fetchPayments(filters: PaymentFilters): Promise<PaginatedResponse<Payment>> {
    const { data } = await api.get('/admin/payments', {
        params: {
            status: filters.status || undefined,
            method: filters.method || undefined,
            date_from: filters.date_from || undefined,
            date_to: filters.date_to || undefined,
            page: filters.page,
            per_page: filters.per_page,
        },
    });
    return data as PaginatedResponse<Payment>;
}

export async function fetchPayment(ulid: string): Promise<Payment> {
    const { data } = await api.get(`/admin/payments/${ulid}`);
    return unwrap<Payment>(data);
}

export async function verifyManual(ulid: string, verificationNote: string): Promise<Payment> {
    try {
        const { data } = await api.post(`/admin/payments/${ulid}/verify-manual`, { verification_note: verificationNote });
        return unwrap<Payment>(data);
    } catch (e) {
        throw new Error(toApiError(e).message);
    }
}

export async function rejectManual(ulid: string, rejectionReason: string): Promise<Payment> {
    try {
        const { data } = await api.post(`/admin/payments/${ulid}/reject-manual`, { rejection_reason: rejectionReason });
        return unwrap<Payment>(data);
    } catch (e) {
        throw new Error(toApiError(e).message);
    }
}

export async function refundPayment(
    ulid: string,
    payload: { reason: string; type: 'full' | 'partial'; amount_paisa?: number },
): Promise<RefundResult> {
    try {
        const { data } = await api.post(`/admin/payments/${ulid}/refund`, payload);
        return unwrap<RefundResult>(data);
    } catch (e) {
        throw new Error(toApiError(e).message);
    }
}
