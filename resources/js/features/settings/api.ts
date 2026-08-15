import { api, toApiError } from '@/lib/api';
import { unwrap } from '@/lib/pagination';
import type { EventSetting, SettingsByGroup } from './types';

export async function fetchSettings(): Promise<SettingsByGroup> {
    const { data } = await api.get('/admin/settings');
    return unwrap<SettingsByGroup>(data);
}

export async function updateSetting(key: string, value: unknown): Promise<EventSetting> {
    try {
        const { data } = await api.patch(`/admin/settings/${encodeURIComponent(key)}`, { value });
        return unwrap<EventSetting>(data);
    } catch (e) {
        const error = toApiError(e);
        // There is exactly one field, so its validation message is more useful
        // than the generic "The given data was invalid." envelope message.
        throw new Error(error.errors?.value?.[0] ?? error.message);
    }
}
