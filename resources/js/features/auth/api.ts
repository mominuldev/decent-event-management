import { api } from '@/lib/api';
import type { LoginResult, Session } from './types';

export async function login(email: string, password: string, totpCode?: string): Promise<LoginResult> {
    const { data } = await api.post('/admin/auth/login', {
        email,
        password,
        totp_code: totpCode || undefined,
        device_name: 'admin-console-web',
    });
    return data as LoginResult;
}

export async function fetchMe(): Promise<Session> {
    const { data } = await api.get('/admin/auth/me');
    return data as Session;
}

export async function logout(): Promise<void> {
    await api.post('/admin/auth/logout');
}

export interface TwoFactorSetup {
    secret: string;
    qr_code_svg: string;
}

export async function setupTwoFactor(): Promise<TwoFactorSetup> {
    const { data } = await api.post('/admin/auth/2fa/setup');
    return data as TwoFactorSetup;
}

export interface TwoFactorConfirmResult {
    message: string;
    recovery_codes: string[];
    token: string;
    expires_at: string;
}

export async function confirmTwoFactor(code: string): Promise<TwoFactorConfirmResult> {
    const { data } = await api.post('/admin/auth/2fa/confirm', { code });
    return data as TwoFactorConfirmResult;
}
