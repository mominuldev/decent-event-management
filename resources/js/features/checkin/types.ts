export type CheckInResult =
    | 'admitted'
    | 'manual_override'
    | 'duplicate'
    | 'invalid_format'
    | 'revoked'
    | 'unpaid'
    | 'expired'
    | 'wrong_gate'
    | 'wrong_session'
    | 'over_capacity';

export interface CheckIn {
    ulid: string;
    result: CheckInResult;
    rejection_detail: string | null;
    admitted_count: number;
    scan_mode: string;
    is_manual_override: boolean;
    override_reason: string | null;
    override_by?: string | null;
    conflict_flag: boolean;
    conflict_resolved_at: string | null;
    conflict_resolved_by?: string | null;
    scanned_at: string;
    synced_at: string | null;
    device_clock_skew_ms: number | null;
    latitude: number | null;
    longitude: number | null;
    gate?: { ulid: string; code: string; name: string } | null;
    ticket?: { ulid: string; ticket_number: string } | null;
    registration?: { ulid: string; registration_number: string } | null;
    attendee?: { ulid: string; full_name: string } | null;
    device?: { ulid: string; device_code: string; device_name: string } | null;
    scanned_by?: string | null;
}

export interface EventSession {
    ulid: string;
    code: string;
    name: string;
    venue: string;
    starts_at: string;
    ends_at: string;
    checkin_opens_at: string;
    checkin_closes_at: string;
    capacity: number;
    is_active: boolean;
}

export interface Gate {
    ulid: string;
    code: string;
    name: string;
    allowed_ticket_type_ids: number[] | null;
    location_note: string | null;
    admitted_count: number;
    is_active: boolean;
    event_session?: EventSession | null;
}

export interface GatePayload {
    code: string;
    name: string;
    event_session_ulid?: string | null;
    allowed_ticket_type_ulids?: string[] | null;
    location_note?: string | null;
    is_active?: boolean;
}

export interface Device {
    ulid: string;
    device_code: string;
    device_name: string;
    platform: string;
    app_version: string;
    os_version: string;
    status: string;
    enrolled_at: string | null;
    revoked_at: string | null;
    manifest_version: number;
    last_sync_at: string | null;
    last_seen_at: string | null;
    pending_scan_count: number;
    battery_level: number | null;
    total_scans: number;
    volunteer?: { ulid: string; volunteer_code: string; name: string | null } | null;
}

export interface DeviceSyncStatus {
    status: string;
    manifest_version: number;
    last_sync_at: string | null;
    last_seen_at: string | null;
    pending_scan_count: number;
    battery_level: number | null;
    total_scans: number;
}

export interface Volunteer {
    ulid: string;
    volunteer_code: string;
    team: string | null;
    shift_starts_at: string | null;
    shift_ends_at: string | null;
    is_active: boolean;
    revoked_at: string | null;
    total_scans: number;
    user?: { ulid: string; name: string; email: string; phone: string | null } | null;
    gate_assignments?: { gate: { ulid: string; code: string; name: string } | null; event_session_ulid: string | null }[];
}

export interface VolunteerCreatePayload {
    name: string;
    email: string;
    phone?: string | null;
    password: string;
    volunteer_code: string;
    team?: string | null;
    shift_starts_at?: string | null;
    shift_ends_at?: string | null;
}

export interface VolunteerUpdatePayload {
    team?: string | null;
    shift_starts_at?: string | null;
    shift_ends_at?: string | null;
    is_active?: boolean;
}

export interface LiveDashboard {
    gates: Gate[];
    recent_check_ins: CheckIn[];
}

export interface ManualOverridePayload {
    ticket_ulid: string;
    gate_ulid: string;
    party_size: number;
    reason: string;
}

export interface EnrolmentToken {
    enrolment_token: string;
    expires_at: string;
}
