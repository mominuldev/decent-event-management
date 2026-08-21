import { api } from '@/lib/api';
import type { SigningKey, SigningKeyIndex } from './types';

export async function fetchSigningKeys(): Promise<SigningKeyIndex> {
    const { data } = await api.get('/admin/qr-signing/keys');
    return { keys: data.data, ...data.meta };
}

/**
 * Confirms the operator's own credentials for the next few minutes. Every
 * mutation below is refused without it — see docs/06 §6.5.
 */
export async function reauthenticate(password: string, totpCode?: string): Promise<number> {
    const { data } = await api.post('/admin/auth/reauth', {
        password,
        ...(totpCode ? { totp_code: totpCode } : {}),
    });
    return data.expires_in_minutes;
}

export async function publishKey(keyId: string): Promise<SigningKey> {
    const { data } = await api.post('/admin/qr-signing/keys', { key_id: keyId });
    return data.data;
}

export async function activateKey(ulid: string, force = false): Promise<SigningKey> {
    const { data } = await api.post(`/admin/qr-signing/keys/${ulid}/activate`, { force });
    return data.data;
}

export async function retireKey(ulid: string): Promise<SigningKey> {
    const { data } = await api.post(`/admin/qr-signing/keys/${ulid}/retire`);
    return data.data;
}
