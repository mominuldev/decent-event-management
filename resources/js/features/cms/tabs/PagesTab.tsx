import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';
import { Plus } from 'lucide-react';
import { Badge, Button, Card, CardHeader, Input, Label, Select, type Tone } from '@/components/ui';
import { DataTable } from '@/components/DataTable';
import { useAuth } from '@/features/auth/AuthProvider';
import { totalOf } from '@/lib/pagination';
import * as cmsApi from '../api';
import { PAGE_STATUSES, type ContentPageSummary, type PageStatus } from '../types';

const statusTone: Record<PageStatus, Tone> = {
    draft: 'neutral',
    in_review: 'warning',
    published: 'success',
    archived: 'neutral',
};

const statusLabel: Record<PageStatus, string> = {
    draft: 'Draft',
    in_review: 'In review',
    published: 'Published',
    archived: 'Archived',
};

function columns(): ColumnDef<ContentPageSummary, unknown>[] {
    return [
        {
            id: 'title',
            header: 'Page',
            cell: (ctx) => (
                <div>
                    <div className="font-medium text-text">{ctx.row.original.title}</div>
                    <div className="text-[12px] text-text-faint">/{ctx.row.original.slug}</div>
                </div>
            ),
        },
        {
            id: 'title_bn',
            header: 'Bangla title',
            cell: (ctx) =>
                ctx.row.original.title_bn
                    ? <span className="text-text-muted">{ctx.row.original.title_bn}</span>
                    : <span className="text-text-faint">Not translated</span>,
        },
        { id: 'template', header: 'Template', cell: (ctx) => <span className="text-text-muted">{ctx.row.original.template}</span> },
        {
            id: 'status',
            header: 'Status',
            cell: (ctx) => {
                const row = ctx.row.original;
                return (
                    <div className="flex items-center gap-1.5">
                        <Badge tone={statusTone[row.status]} size="sm">{statusLabel[row.status]}</Badge>
                        {/* Published but not yet live means a future publish date. */}
                        {row.status === 'published' && !row.is_live && <Badge tone="info" size="sm">Scheduled</Badge>}
                    </div>
                );
            },
        },
        { id: 'revision', header: 'Rev', cell: (ctx) => <span className="tnum text-text-muted">{ctx.row.original.revision_number}</span> },
        {
            id: 'updated_at',
            header: 'Last edited',
            cell: (ctx) => (
                <div className="text-[12.5px]">
                    <div className="tnum text-text-muted">
                        {ctx.row.original.updated_at ? new Date(ctx.row.original.updated_at).toLocaleDateString() : '—'}
                    </div>
                    {ctx.row.original.updated_by && <div className="text-text-faint">{ctx.row.original.updated_by}</div>}
                </div>
            ),
        },
    ];
}

export default function PagesTab() {
    const navigate = useNavigate();
    const { can } = useAuth();
    const [status, setStatus] = useState('');
    const [q, setQ] = useState('');
    const [pageIndex, setPageIndex] = useState(0);
    const pageSize = 20;

    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['cms-pages', status, q, pageIndex],
        queryFn: () => cmsApi.fetchPages({ status, q, page: pageIndex + 1, per_page: pageSize }),
    });

    return (
        <Card>
            <CardHeader
                title="Pages"
                subtitle="Marketing pages on the public site. Every save is versioned."
                action={
                    can('content.create') && (
                        <Button size="sm" onClick={() => navigate('/cms/pages/new')}>
                            <Plus size={15} /> New page
                        </Button>
                    )
                }
            />

            <div className="flex flex-wrap gap-3 px-5 pb-4 pt-3">
                <div className="w-44">
                    <Label htmlFor="page-status">Status</Label>
                    <Select id="page-status" value={status} onChange={(e) => { setStatus(e.target.value); setPageIndex(0); }}>
                        <option value="">All statuses</option>
                        {PAGE_STATUSES.map((s) => <option key={s} value={s}>{statusLabel[s]}</option>)}
                    </Select>
                </div>
                <div className="w-64">
                    <Label htmlFor="page-search">Search</Label>
                    <Input
                        id="page-search"
                        value={q}
                        placeholder="Slug or title"
                        onChange={(e) => { setQ(e.target.value); setPageIndex(0); }}
                    />
                </div>
            </div>

            <DataTable
                columns={columns()}
                data={data?.data ?? []}
                getRowId={(row) => row.ulid}
                isLoading={isLoading}
                isError={isError}
                errorMessage="Failed to load pages."
                onRetry={() => void refetch()}
                emptyTitle="No pages"
                emptyDescription="Create one to start building the public site."
                pageIndex={pageIndex}
                pageSize={pageSize}
                totalRows={data ? totalOf(data) : 0}
                onPageChange={setPageIndex}
                onRowClick={(row) => navigate(`/cms/pages/${row.ulid}`)}
            />
        </Card>
    );
}
