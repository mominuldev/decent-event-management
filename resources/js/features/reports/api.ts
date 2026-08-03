import { api, toApiError } from '@/lib/api';
import { unwrap } from '@/lib/pagination';
import type { ExportFormat, ReportExport, ReportKey, ReportRow } from './types';

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
