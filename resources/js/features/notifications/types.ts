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
    /** Public identifier — the auto-increment id never crosses the API. */
    ulid: string;
    key: string;
    channel: string;
    locale: string;
    version: number;
    subject: string | null;
    /** The message itself. */
    body: string;
    /**
     * The `{{placeholders}}` this template may use. Shown in the editor
     * because a template written against a variable the dispatching code
     * does not pass renders the `{{key}}` verbatim into a real message
     * rather than failing — which is exactly how a broken template ships.
     */
    variables: string[];
    /** SMS only; null for email and WhatsApp, which are not billed per segment. */
    estimated_segments: number | null;
    whatsapp_template_name: string | null;
    whatsapp_template_status: string | null;
    is_active: boolean;
    updated_at: string | null;
}

/** `POST /admin/notifications/templates/preview` — what a draft would cost. */
export interface TemplatePreview {
    rendered: string;
    characters: number;
    encoding: 'GSM-7' | 'Unicode';
    characters_per_segment: number;
    segments: number;
    cost_paisa_each: number;
    cost_paisa_total: number;
    recipients: number;
}

export interface SaveTemplateInput {
    key?: string;
    channel?: string;
    locale?: string;
    subject?: string | null;
    body: string;
    is_active?: boolean;
    variables?: string[];
}

export interface CostRow {
    channel: string;
    date: string;
    total_cost_paisa: number;
    total_segments: number;
    message_count: number;
}

export type KillSwitches = Record<NotificationChannel, boolean>;
