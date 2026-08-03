export interface Registration {
    ulid: string;
    registration_number: string;
    status: string;
    participation_type: string;
    adults_count: number;
    children_count: number;
    subtotal_paisa: number;
    discount_paisa: number;
    total_paisa: number;
    currency: string;
    submitted_at: string | null;
    confirmed_at: string | null;
    created_at: string;
    attendee?: { full_name?: string } | null;
    ticket_type?: { name?: string } | null;
}

export interface TicketType {
    ulid: string;
    code: string;
    name: string;
    base_price_paisa: number;
    currency: string;
    quantity_total: number | null;
    quantity_sold: number;
    quantity_reserved: number;
    quantity_available: number;
    is_active: boolean;
    badge_color: string | null;
    sort_order: number;
}

/** Row/summary shapes are not pinned down by the spec — see lib/format.ts. */
export type ReportRow = Record<string, unknown>;
