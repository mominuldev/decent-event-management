export type PaymentMethod = 'bkash' | 'nagad' | 'rocket' | 'sslcommerz' | 'manual';

export interface Payment {
    ulid: string;
    payment_number: string;
    method: PaymentMethod;
    channel: string | null;
    status: string;
    amount_due_paisa: number;
    amount_paid_paisa: number;
    fee_paisa: number;
    net_paisa: number;
    refunded_paisa: number;
    currency: string;
    gateway_transaction_id: string | null;
    payer_msisdn: string | null;
    manual_trx_id: string | null;
    reconciliation_status: string | null;
    initiated_at: string | null;
    paid_at: string | null;
    expires_at: string | null;
    failed_at: string | null;
    verified_at: string | null;
    created_at: string;
    verified_by_name?: string | null;
}

export interface RefundResult {
    ulid: string;
    refund_number: string;
    amount_paisa: number;
    status: string;
}

export const PAYMENT_METHODS: { value: PaymentMethod; label: string }[] = [
    { value: 'bkash', label: 'bKash' },
    { value: 'nagad', label: 'Nagad' },
    { value: 'rocket', label: 'Rocket' },
    { value: 'sslcommerz', label: 'SSLCommerz' },
    { value: 'manual', label: 'Manual' },
];
