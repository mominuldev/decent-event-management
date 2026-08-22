import { api, ApiRequestError, toApiError } from '@/lib/api';
import type { PaginatedResponse } from '@/lib/pagination';
import { unwrap } from '@/lib/pagination';
import type {
    CostRow,
    KillSwitches,
    NotificationChannel,
    NotificationRecord,
    NotificationTemplateSummary,
    SaveTemplateInput,
    TemplatePreview,
} from './types';

export interface NotificationFilters {
    channel?: string;
    status?: string;
    template_key?: string;
    date_from?: string;
    date_to?: string;
    page?: number;
    per_page?: number;
}

export async function fetchNotifications(filters: NotificationFilters): Promise<PaginatedResponse<NotificationRecord>> {
    const { data } = await api.get('/admin/notifications', {
        params: {
            channel: filters.channel || undefined,
            status: filters.status || undefined,
            template_key: filters.template_key || undefined,
            date_from: filters.date_from || undefined,
            date_to: filters.date_to || undefined,
            page: filters.page,
            per_page: filters.per_page,
        },
    });
    return data as PaginatedResponse<NotificationRecord>;
}

export async function fetchNotification(ulid: string): Promise<NotificationRecord> {
    const { data } = await api.get(`/admin/notifications/${ulid}`);
    return unwrap<NotificationRecord>(data);
}

export async function resendNotification(ulid: string): Promise<NotificationRecord> {
    try {
        const { data } = await api.post(`/admin/notifications/${ulid}/resend`);
        return unwrap<NotificationRecord>(data);
    } catch (e) {
        throw new Error(toApiError(e).message);
    }
}

export async function fetchNotificationCosts(filters: { date_from?: string; date_to?: string } = {}): Promise<CostRow[]> {
    const { data } = await api.get('/admin/notifications/costs', {
        params: { date_from: filters.date_from || undefined, date_to: filters.date_to || undefined },
    });
    return (data as { data: CostRow[] }).data;
}

export async function fetchKillSwitches(): Promise<KillSwitches> {
    const { data } = await api.get('/admin/notifications/kill-switches');
    return (data as { data: KillSwitches }).data;
}

export async function updateKillSwitch(channel: NotificationChannel, enabled: boolean): Promise<{ channel: NotificationChannel; enabled: boolean }> {
    try {
        const { data } = await api.patch('/admin/notifications/kill-switches', { channel, enabled });
        return (data as { data: { channel: NotificationChannel; enabled: boolean } }).data;
    } catch (e) {
        throw new Error(toApiError(e).message);
    }
}

export async function fetchNotificationTemplates(key?: string): Promise<NotificationTemplateSummary[]> {
    const { data } = await api.get('/admin/notifications/templates', { params: { key: key || undefined } });
    return (data as { data: NotificationTemplateSummary[] }).data;
}

export async function saveNotificationTemplate(
    ulid: string | null,
    input: SaveTemplateInput,
): Promise<NotificationTemplateSummary> {
    try {
        const { data } = ulid
            ? await api.patch(`/admin/notifications/templates/${ulid}`, input)
            : await api.post('/admin/notifications/templates', input);
        return (data as { data: NotificationTemplateSummary }).data;
    } catch (e) {
        // ApiRequestError rather than a flattened message: a template save
        // fails per field (a duplicate key, a body over the limit) and the
        // editor needs to put each message beside its own control.
        throw new ApiRequestError(e);
    }
}

/**
 * What a draft body would cost as an SMS, before it is saved. The server
 * measures it rather than the browser so the number here, the number on
 * the list, and the number that is actually billed all come from one
 * implementation of the GSM-7 rules.
 */
export async function previewTemplate(body: string, recipients?: number): Promise<TemplatePreview> {
    const { data } = await api.post('/admin/notifications/templates/preview', { body, recipients });
    return data as TemplatePreview;
}
