export type SigningKeyStatus = 'pending' | 'active' | 'retired';

export interface SigningKey {
    ulid: string;
    key_id: string;
    public_key: string;
    status: SigningKeyStatus;
    published_at: string | null;
    activated_at: string | null;
    retired_at: string | null;
    published_by?: string | null;
    activated_by?: string | null;
}

export interface OutstandingDevice {
    device_code: string;
    device_name: string;
    last_sync_at: string | null;
}

export interface FleetReadiness {
    total: number;
    synced: number;
    outstanding: OutstandingDevice[];
}

export interface SigningKeyIndex {
    keys: SigningKey[];
    active_key_id: string | null;
    /** Private key material this server holds that has not been published yet. */
    unpublished_key_ids: string[];
    /** Null when no rotation is in flight. */
    readiness: FleetReadiness | null;
}
