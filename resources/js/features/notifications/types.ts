export type NotificationChannel = 'email' | 'sms' | 'whatsapp';

export interface NotificationEventEntry {
    event: string;
    provider_status: string | null;
    detail: string | null;
    occurred_at: string;
}

export interface NotificationRecord {
    ulid: string;
    notifiable_type: string;
    notifiable_id: number;
    template_key: string;
    channel: NotificationChannel;
    locale: string;
    recipient: string;
    subject: string | null;
    status: string;
    priority: number;
    attempts: number;
    max_attempts: number;
    provider: string | null;
    provider_message_id: string | null;
    segment_count: number | null;
    cost_paisa: number | null;
    last_error: string | null;
    scheduled_for: string | null;
    sent_at: string | null;
    delivered_at: string | null;
    failed_at: string | null;
    created_at: string;
    events?: NotificationEventEntry[];
}

export interface NotificationTemplateSummary {
    key: string;
    channel: string;
    locale: string;
    version: number;
    subject: string | null;
    whatsapp_template_name: string | null;
    whatsapp_template_status: string | null;
    is_active: boolean;
}

export interface CostRow {
    channel: string;
    date: string;
    total_cost_paisa: number;
    total_segments: number;
    message_count: number;
}

export type KillSwitches = Record<NotificationChannel, boolean>;
