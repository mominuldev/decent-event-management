export type ReportKey = 'registrations_by_batch' | 'sales_by_type' | 'revenue_summary';
export type ExportFormat = 'pdf' | 'xlsx' | 'csv';

export const REPORT_CATALOGUE: { key: ReportKey; label: string; description: string; permission: string }[] = [
    {
        key: 'sales_by_type',
        label: 'Sales by ticket type',
        description: 'Quantity sold, reserved, and total capacity per tier.',
        permission: 'report.view_registrations',
    },
    {
        key: 'revenue_summary',
        label: 'Revenue summary',
        description: 'Total revenue and refunds recorded across succeeded payments.',
        permission: 'report.view_revenue',
    },
    {
        key: 'registrations_by_batch',
        label: 'Registrations by batch',
        description: 'Confirmed registrations grouped by SSC batch year.',
        permission: 'report.view_batch_breakdown',
    },
];

export function exportPermissionFor(format: ExportFormat): string {
    return format === 'pdf' ? 'report.export_pdf' : format === 'xlsx' ? 'report.export_excel' : 'report.export_csv';
}

export type ReportRow = Record<string, unknown>;

export interface ReportExport {
    ulid: string;
    report_key: string;
    format: ExportFormat;
    status: string;
    row_count: number | null;
    started_at: string | null;
    completed_at: string | null;
    expires_at: string | null;
    download_url?: string | null;
    created_at: string;
}

/**
 * The daily takings report. Unlike the three catalogue reports above, this one
 * has its own endpoint and a fixed response shape, so it gets real types
 * rather than going through `formatGenericValue`.
 */
/**
 * The daily sales export formats. Deliberately not `ExportFormat` above: that
 * one belongs to the older queued catalogue export, and the two lists are free
 * to diverge.
 */
export type DailySalesExportFormat = 'csv' | 'xlsx' | 'pdf';

/** The permission a given format needs, on top of `report.view_revenue`. */
export function dailySalesExportPermissionFor(format: DailySalesExportFormat): string {
    return format === 'pdf' ? 'report.export_pdf' : format === 'xlsx' ? 'report.export_excel' : 'report.export_csv';
}

export interface DailySalesFilters {
    /** Inclusive, `YYYY-MM-DD`, in the reporting timezone. */
    from?: string;
    to?: string;
    ssc_batch_year?: string;
    ticket_type_ulid?: string;
}

/** Every numeric key a day row carries. `totals` carries exactly these too. */
export interface DailySalesTotals {
    online_payments: number;
    online_paisa: number;
    online_persons: number;
    offline_payments: number;
    offline_paisa: number;
    offline_persons: number;
    total_payments: number;
    total_paisa: number;
    total_persons: number;
    refund_count: number;
    refunded_paisa: number;
    /** Takings that day less refunds processed that day — can be negative. */
    net_paisa: number;
}

export interface DailySalesDay extends DailySalesTotals {
    date: string;
}

export interface DailySalesMethod {
    method: string;
    channel: string;
    kind: 'online' | 'offline';
    payments: number;
    paisa: number;
}

export interface DailySalesReport {
    /** What the server actually applied, after defaults — the date inputs are
     *  seeded from this, so a blank form still shows the window it answered. */
    filters: {
        from: string;
        to: string;
        timezone: string;
        ssc_batch_year: number | null;
        ticket_type_ulid: string | null;
    };
    totals: DailySalesTotals;
    methods: DailySalesMethod[];
    /** Newest day first, matching every other admin list. */
    days: DailySalesDay[];
}
