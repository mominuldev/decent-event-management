import { api, toApiError } from '@/lib/api';
import type { PaginatedResponse } from '@/lib/pagination';
import { unwrap } from '@/lib/pagination';
import type {
    CheckIn,
    Device,
    DeviceSyncStatus,
    EnrolmentToken,
    Gate,
    GatePayload,
    LiveDashboard,
    ManualOverridePayload,
    Volunteer,
    VolunteerCreatePayload,
    VolunteerUpdatePayload,
} from './types';

/* ------------------------------------------------------------------ Scans */

export interface CheckInFilters {
    gate_ulid?: string;
    result?: string;
    date_from?: string;
    date_to?: string;
    page?: number;
    per_page?: number;
}

export async function fetchCheckIns(filters: CheckInFilters): Promise<PaginatedResponse<CheckIn>> {
    const { data } = await api.get('/admin/check-ins', {
        params: {
            gate_ulid: filters.gate_ulid || undefined,
            result: filters.result || undefined,
            date_from: filters.date_from || undefined,
            date_to: filters.date_to || undefined,
            page: filters.page,
            per_page: filters.per_page,
        },
    });
    return data as PaginatedResponse<CheckIn>;
}

export async function fetchCheckIn(ulid: string): Promise<CheckIn> {
    const { data } = await api.get(`/admin/check-ins/${ulid}`);
    return unwrap<CheckIn>(data);
}

export async function fetchLiveDashboard(): Promise<LiveDashboard> {
    const { data } = await api.get('/admin/check-ins/live-dashboard');
    return data as LiveDashboard;
}

export async function manualOverride(payload: ManualOverridePayload): Promise<CheckIn> {
    try {
        const { data } = await api.post('/admin/check-ins/manual-override', payload);
        return unwrap<CheckIn>(data);
    } catch (e) {
        throw new Error(toApiError(e).message);
    }
}

export async function resolveConflict(ulid: string, note?: string): Promise<CheckIn> {
    try {
        const { data } = await api.post(`/admin/check-ins/${ulid}/resolve-conflict`, { note: note || undefined });
        return unwrap<CheckIn>(data);
    } catch (e) {
        throw new Error(toApiError(e).message);
    }
}

/* -------------------------------------------------------------------- Gates */

export interface GateFilters {
    event_session_ulid?: string;
    is_active?: boolean;
}

export async function fetchGates(filters: GateFilters = {}): Promise<Gate[]> {
    const { data } = await api.get('/admin/gates', {
        params: {
            event_session_ulid: filters.event_session_ulid || undefined,
            is_active: filters.is_active,
            per_page: 100,
        },
    });
    return (data as PaginatedResponse<Gate>).data;
}

export async function createGate(payload: GatePayload): Promise<Gate> {
    try {
        const { data } = await api.post('/admin/gates', payload);
        return unwrap<Gate>(data);
    } catch (e) {
        throw new Error(toApiError(e).message);
    }
}

export async function updateGate(ulid: string, payload: Partial<GatePayload>): Promise<Gate> {
    try {
        const { data } = await api.patch(`/admin/gates/${ulid}`, payload);
        return unwrap<Gate>(data);
    } catch (e) {
        throw new Error(toApiError(e).message);
    }
}

export async function deleteGate(ulid: string): Promise<void> {
    try {
        await api.delete(`/admin/gates/${ulid}`);
    } catch (e) {
        throw new Error(toApiError(e).message);
    }
}

/* ------------------------------------------------------------------ Devices */

export interface DeviceFilters {
    status?: string;
    volunteer_ulid?: string;
    page?: number;
    per_page?: number;
}

export async function fetchDevices(filters: DeviceFilters): Promise<PaginatedResponse<Device>> {
    const { data } = await api.get('/admin/devices', {
        params: {
            status: filters.status || undefined,
            volunteer_ulid: filters.volunteer_ulid || undefined,
            page: filters.page,
            per_page: filters.per_page,
        },
    });
    return data as PaginatedResponse<Device>;
}

export async function fetchDeviceSyncStatus(ulid: string): Promise<DeviceSyncStatus> {
    const { data } = await api.get(`/admin/devices/${ulid}/sync-status`);
    return data as DeviceSyncStatus;
}

export async function revokeDevice(ulid: string): Promise<Device> {
    try {
        const { data } = await api.post(`/admin/devices/${ulid}/revoke`);
        return unwrap<Device>(data);
    } catch (e) {
        throw new Error(toApiError(e).message);
    }
}

/* --------------------------------------------------------------- Volunteers */

export interface VolunteerFilters {
    is_active?: boolean;
    team?: string;
    page?: number;
    per_page?: number;
}

export async function fetchVolunteers(filters: VolunteerFilters): Promise<PaginatedResponse<Volunteer>> {
    const { data } = await api.get('/admin/volunteers', {
        params: {
            is_active: filters.is_active,
            team: filters.team || undefined,
            page: filters.page,
            per_page: filters.per_page,
        },
    });
    return data as PaginatedResponse<Volunteer>;
}

export async function fetchVolunteer(ulid: string): Promise<Volunteer> {
    const { data } = await api.get(`/admin/volunteers/${ulid}`);
    return unwrap<Volunteer>(data);
}

export async function createVolunteer(payload: VolunteerCreatePayload): Promise<Volunteer> {
    try {
        const { data } = await api.post('/admin/volunteers', payload);
        return unwrap<Volunteer>(data);
    } catch (e) {
        throw new Error(toApiError(e).message);
    }
}

export async function updateVolunteer(ulid: string, payload: VolunteerUpdatePayload): Promise<Volunteer> {
    try {
        const { data } = await api.patch(`/admin/volunteers/${ulid}`, payload);
        return unwrap<Volunteer>(data);
    } catch (e) {
        throw new Error(toApiError(e).message);
    }
}

export async function assignVolunteerGate(ulid: string, gateUlid: string, eventSessionUlid?: string): Promise<Volunteer> {
    try {
        const { data } = await api.post(`/admin/volunteers/${ulid}/assign-gate`, {
            gate_ulid: gateUlid,
            event_session_ulid: eventSessionUlid || undefined,
        });
        return unwrap<Volunteer>(data);
    } catch (e) {
        throw new Error(toApiError(e).message);
    }
}

export async function revokeVolunteerAccess(ulid: string, reason?: string): Promise<Volunteer> {
    try {
        const { data } = await api.post(`/admin/volunteers/${ulid}/revoke-access`, { reason: reason || undefined });
        return unwrap<Volunteer>(data);
    } catch (e) {
        throw new Error(toApiError(e).message);
    }
}

export async function issueEnrolmentToken(ulid: string): Promise<EnrolmentToken> {
    try {
        const { data } = await api.post(`/admin/volunteers/${ulid}/enrolment-token`);
        return data as EnrolmentToken;
    } catch (e) {
        throw new Error(toApiError(e).message);
    }
}
