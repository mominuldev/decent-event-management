import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Download } from 'lucide-react';
import { Badge, Button, Card, CardHeader, Select, Skeleton } from '@/components/ui';
import { useAuth } from '@/features/auth/AuthProvider';
import { useToast } from '@/components/Toast';
import { formatGenericValue, titleCase } from '@/lib/format';
import * as reportsApi from './api';
import { REPORT_CATALOGUE, exportPermissionFor, type ExportFormat, type ReportExport, type ReportKey, type ReportRow } from './types';

function SummaryGrid({ data }: { data: ReportRow }) {
    const entries = Object.entries(data);
    if (entries.length === 0) return <p className="px-5 pb-5 text-[13px] text-text-muted">No data yet.</p>;
    return (
        <div className="grid grid-cols-2 gap-3 px-5 pb-5 pt-4 sm:grid-cols-3">
            {entries.map(([key, value]) => (
                <div key={key} className="rounded-xl border border-border bg-surface-2/50 p-3">
                    <div className="text-[11px] uppercase tracking-wide text-text-faint">{titleCase(key)}</div>
                    <div className="tnum mt-1 text-[15px] font-semibold text-text">{formatGenericValue(key, value)}</div>
                </div>
            ))}
        </div>
    );
}

function RowsTable({ rows }: { rows: ReportRow[] }) {
    if (rows.length === 0) return <p className="px-5 pb-5 text-[13px] text-text-muted">No rows for this report yet.</p>;
    const columns = Object.keys(rows[0]);
    return (
        <div className="overflow-x-auto px-2 pb-3">
            <table className="w-full min-w-[420px] text-left text-[13px]">
                <thead>
                    <tr className="border-y border-border text-[11px] uppercase tracking-wide text-text-faint">
                        {columns.map((c) => <th key={c} className="px-3 py-2.5 font-semibold">{titleCase(c)}</th>)}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row, i) => (
                        <tr key={i} className="border-b border-border last:border-0 hover:bg-table-row-hover">
                            {columns.map((c) => <td key={c} className="tnum px-3 py-2.5 text-text">{formatGenericValue(c, row[c])}</td>)}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function ExportControl({ reportKey }: { reportKey: ReportKey }) {
    const { can } = useAuth();
    const { push } = useToast();
    const [format, setFormat] = useState<ExportFormat>('pdf');
    const [queued, setQueued] = useState<ReportExport[]>([]);

    const exportMutation = useMutation({
        mutationFn: () => reportsApi.exportReport(reportKey, format),
        onSuccess: (result) => {
            setQueued((prev) => [result, ...prev]);
            push('success', `Export queued (${result.format.toUpperCase()}).`);
        },
        onError: (e: Error) => push('critical', e.message),
    });

    if (!can(exportPermissionFor(format))) return null;

    return (
        <div className="flex items-center gap-2">
            <Select value={format} onChange={(e) => setFormat(e.target.value as ExportFormat)} className="w-24">
                <option value="pdf">PDF</option>
                <option value="xlsx">Excel</option>
                <option value="csv">CSV</option>
            </Select>
            <Button variant="outline" size="sm" disabled={exportMutation.isPending} onClick={() => void exportMutation.mutateAsync()}>
                <Download size={14} /> {exportMutation.isPending ? 'Queuing…' : 'Export'}
            </Button>
            {queued.length > 0 && (
                <div className="flex flex-col gap-1">
                    {queued.slice(0, 2).map((q) => (
                        <span key={q.ulid} className="text-[11.5px] text-text-faint">
                            {q.format.toUpperCase()} export <Badge tone="warning" size="sm">{titleCase(q.status)}</Badge> — job runs async, no download link yet
                        </span>
                    ))}
                </div>
            )}
        </div>
    );
}

function ReportCard({ reportKey, label, description }: { reportKey: ReportKey; label: string; description: string }) {
    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['report', reportKey],
        queryFn: () => reportsApi.fetchReport(reportKey),
    });

    const rows = Array.isArray(data) ? data : data ? [data] : [];
    const isSummary = data !== undefined && !Array.isArray(data);

    return (
        <Card>
            <CardHeader title={label} subtitle={description} action={<ExportControl reportKey={reportKey} />} />
            {isLoading && (
                <div className="grid grid-cols-2 gap-3 px-5 pb-5 pt-4 sm:grid-cols-3">
                    {Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} className="h-14 w-full" />)}
                </div>
            )}
            {isError && (
                <div className="flex items-center justify-between px-5 pb-5 pt-4 text-[13px] text-critical-fg">
                    <span>Failed to load this report.</span>
                    <Button variant="outline" size="sm" onClick={() => void refetch()}>Retry</Button>
                </div>
            )}
            {!isLoading && !isError && (isSummary ? <SummaryGrid data={data as ReportRow} /> : <RowsTable rows={rows} />)}
        </Card>
    );
}

export default function ReportsPage() {
    const { can } = useAuth();
    const visible = REPORT_CATALOGUE.filter((r) => can(r.permission));

    return (
        <div className="space-y-6">
            <div>
                <h1 className="text-[26px] font-bold tracking-tight text-text">Reports</h1>
                <p className="mt-1 text-[14px] text-text-muted">
                    Live report data with async exports to PDF, Excel, or CSV.
                </p>
            </div>

            {visible.length === 0 ? (
                <Card className="grid place-items-center px-6 py-16 text-center">
                    <p className="text-[13.5px] text-text-muted">You don't have permission to view any reports.</p>
                </Card>
            ) : (
                <div className="space-y-4">
                    {visible.map((r) => <ReportCard key={r.key} reportKey={r.key} label={r.label} description={r.description} />)}
                </div>
            )}
        </div>
    );
}
