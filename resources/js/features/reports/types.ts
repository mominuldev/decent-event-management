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
