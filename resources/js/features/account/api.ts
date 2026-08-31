import { api, ApiRequestError } from '@/lib/api';
import type { Session } from '@/features/auth/types';
import type { ChangePasswordPayload, ChangePasswordResult, UpdateProfilePayload } from './types';

/**
 * Both throw ApiRequestError rather than a bare Error, so the page can put
 * the server's message on the field it belongs to — "another staff account
 * already uses that email" is useless as an unattached banner.
 *
 * `/admin/auth/me` answers with a bare object, not a { data } envelope, so
 * there is nothing to unwrap here.
 */
export async function updateProfile(payload: UpdateProfilePayload): Promise<Session> {
    try {
        const { data } = await api.patch('/admin/auth/me', payload);
        return data as Session;
    } catch (e) {
        throw new ApiRequestError(e);
    }
}

export async function changePassword(payload: ChangePasswordPayload): Promise<ChangePasswordResult> {
    try {
        const { data } = await api.post('/admin/auth/password', payload);
        return data as ChangePasswordResult;
    } catch (e) {
        throw new ApiRequestError(e);
    }
}
