import axios from 'axios';
import { api, toApiError } from '@/lib/api';
import { unwrap } from '@/lib/pagination';
import type { DailySalesExportFormat, DailySalesFilters, DailySalesReport, ExportFormat, ReportExport, ReportKey, ReportRow } from './types';

export async function fetchReport(reportKey: ReportKey): Promise<ReportRow[] | ReportRow> {
    const { data } = await api.get(`/admin/reports/${reportKey}`);
    return unwrap<ReportRow[] | ReportRow>(data);
}

export async function exportReport(reportKey: ReportKey, format: ExportFormat): Promise<ReportExport> {
    try {
        const { data } = await api.post(`/admin/reports/${reportKey}/export`, { format });
        return unwrap<ReportExport>(data);
    } catch (e) {
        throw new Error(toApiError(e).message);
    }
}

export async function fetchDailySales(filters: DailySalesFilters): Promise<DailySalesReport> {
    const { data } = await api.get('/admin/reports/daily-sales', {
        params: {
            // `|| undefined` so a cleared control is absent rather than an
            // empty string, which the server would have to treat as absent
            // anyway — one representation of "no filter", not two.
            from: filters.from || undefined,
            to: filters.to || undefined,
            ssc_batch_year: filters.ssc_batch_year || undefined,
            ticket_type_ulid: filters.ticket_type_ulid || undefined,
        },
    });
    return unwrap<DailySalesReport>(data);
}

/**
 * Downloads the daily sales report as a file.
 *
 * Three things this has to get right, all of them learned by the attendee
 * export first:
 *
 *  - **The filters are the ones on screen.** The server rebuilds the same
 *    report from them, so the file and the table can never disagree.
 *  - **An error arrives as a Blob too.** With `responseType: 'blob'`, axios
 *    does not parse the API's `{code, message}` envelope on a 422/403 — it
 *    hands the JSON back as bytes, so a refusal would otherwise surface as an
 *    unreadable `[object Blob]`.
 *  - **The filename comes from the server** via Content-Disposition, because
 *    it carries the window and the generation time, which is what makes one
 *    takings sheet distinguishable from the next in a folder of them.
 */
export async function exportDailySales(filters: DailySalesFilters, format: DailySalesExportFormat): Promise<void> {
    try {
        const response = await api.get('/admin/reports/daily-sales/export', {
            params: {
                format,
                from: filters.from || undefined,
                to: filters.to || undefined,
                ssc_batch_year: filters.ssc_batch_year || undefined,
                ticket_type_ulid: filters.ticket_type_ulid || undefined,
            },
            responseType: 'blob',
        });

        const blob = response.data as Blob;
        const url = URL.createObjectURL(blob);
        const anchor = document.createElement('a');

        anchor.href = url;
        anchor.download = filenameFromDisposition(response.headers['content-disposition']) ?? `daily-sales.${format}`;
        document.body.appendChild(anchor);
        anchor.click();
        anchor.remove();

        // Revoked on the next tick rather than immediately: Safari aborts the
        // download if the object URL is released in the same task as click().
        setTimeout(() => URL.revokeObjectURL(url), 0);
    } catch (e) {
        throw new Error(await blobAwareErrorMessage(e));
    }
}

function filenameFromDisposition(disposition: unknown): string | null {
    if (typeof disposition !== 'string') return null;
    const match = /filename="?([^";]+)"?/i.exec(disposition);
    return match ? match[1] : null;
}

/** Reads the API error envelope back out of a Blob response body. */
async function blobAwareErrorMessage(e: unknown): Promise<string> {
    const body = axios.isAxiosError(e) ? e.response?.data : undefined;

    if (body instanceof Blob) {
        try {
            const parsed = JSON.parse(await body.text()) as { message?: string };
            if (parsed.message) return parsed.message;
        } catch {
            // Not JSON — fall through to the generic message below.
        }
    }

    return toApiError(e).message;
}
