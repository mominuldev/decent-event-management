import { api, toApiError } from '@/lib/api';
import { unwrap } from '@/lib/pagination';
import type { EventSetting, SettingsByGroup } from './types';

export async function fetchSettings(): Promise<SettingsByGroup> {
    const { data } = await api.get('/admin/settings');
    return unwrap<SettingsByGroup>(data);
}

export async function updateSetting(key: string, value: unknown): Promise<EventSetting> {
    try {
        const { data } = await api.patch(`/admin/settings/${key}`, { value });
        return unwrap<EventSetting>(data);
    } catch (e) {
        throw new Error(toApiError(e).message);
    }
}
