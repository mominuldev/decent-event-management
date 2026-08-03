export interface Ticket {
    ulid: string;
    ticket_number: string;
    status: string;
    admits_total: number;
    admitted_count: number;
    price_paid_paisa: number;
    currency: string;
    holder_name: string | null;
    holder_batch_year: number | null;
    holder_type_label: string | null;
    issued_at: string | null;
    voided_at: string | null;
    void_reason: string | null;
    first_admitted_at: string | null;
    last_admitted_at: string | null;
    manifest_version: number;
    created_at: string;
    ticket_type?: { ulid?: string; name?: string; code?: string } | null;
    qr_code_payload?: string | null;
    replaces?: Ticket | null;
}

export interface TicketType {
    ulid: string;
    code: string;
    name: string;
    name_bn: string | null;
    description: string | null;
    base_price_paisa: number;
    additional_adult_price_paisa: number;
    additional_child_price_paisa: number;
    currency: string;
    base_admits: number;
    max_admits: number;
    allowed_participant_types: string[] | null;
    quantity_total: number | null;
    quantity_sold: number;
    quantity_reserved: number;
    quantity_available: number;
    requires_approval: boolean;
    includes_tshirt: boolean;
    includes_meal: boolean;
    sale_starts_at: string | null;
    sale_ends_at: string | null;
    is_active: boolean;
    is_public: boolean;
    badge_color: string | null;
    sort_order: number;
}

export interface TicketTypePayload {
    code: string;
    name: string;
    name_bn?: string | null;
    description?: string | null;
    base_price_paisa: number;
    additional_adult_price_paisa: number;
    additional_child_price_paisa: number;
    base_admits: number;
    max_admits: number;
    quantity_total?: number | null;
    requires_approval?: boolean;
    includes_tshirt?: boolean;
    includes_meal?: boolean;
    is_active?: boolean;
    is_public?: boolean;
    sale_starts_at?: string | null;
    sale_ends_at?: string | null;
    badge_color?: string | null;
    sort_order?: number;
}
