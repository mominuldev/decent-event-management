import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';
import { Ban, CheckCircle2, RotateCcw } from 'lucide-react';
import { Badge, Button, Card, CardHeader, Input, Label, Select, Skeleton, Textarea, type Tone } from '@/components/ui';
import { Dialog, ConfirmDialog } from '@/components/Dialog';
import { DataTable } from '@/components/DataTable';
import { useAuth } from '@/features/auth/AuthProvider';
import { useToast } from '@/components/Toast';
import { cn, money } from '@/lib/cn';
import { totalOf } from '@/lib/pagination';
import * as financeApi from './api';
import { PAYMENT_METHODS, type Payment, type PaymentMethod } from './types';

const knownStatusTone: Record<string, Tone> = {
    pending: 'neutral',
    initiated: 'warning',
    processing: 'warning',
    awaiting_verification: 'warning',
    succeeded: 'success',
    partially_refunded: 'info',
    refunded: 'info',
    failed: 'critical',
    rejected: 'critical',
    cancelled: 'critical',
    expired: 'critical',
};

function titleCase(s: string) {
    return s.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function statusBadge(status: string) {
    return <Badge tone={knownStatusTone[status] ?? 'neutral'}>{titleCase(status)}</Badge>;
}

function RefundDialog({ payment, onClose }: { payment: Payment; onClose: () => void }) {
    const { push } = useToast();
    const queryClient = useQueryClient();
    const [type, setType] = useState<'full' | 'partial'>('full');
    const [amount, setAmount] = useState('');
    const [reason, setReason] = useState('');
    const [error, setError] = useState<string | null>(null);

    const refundMutation = useMutation({
        mutationFn: () =>
            financeApi.refundPayment(payment.ulid, {
                reason: reason.trim(),
                type,
                amount_paisa: type === 'partial' ? Math.round(Number(amount) * 100) : undefined,
            }),
        onSuccess: (result) => {
            push('success', `Refund ${result.refund_number} recorded (${money(result.amount_paisa)}).`);
            void queryClient.invalidateQueries({ queryKey: ['payment', payment.ulid] });
            void queryClient.invalidateQueries({ queryKey: ['payments'] });
            onClose();
        },
        onError: (e: Error) => setError(e.message),
    });

    const refundable = payment.amount_paid_paisa - payment.refunded_paisa;
    const canSubmit = reason.trim().length >= 3 && (type === 'full' || (Number(amount) > 0 && Math.round(Number(amount) * 100) <= refundable));

    return (
        <Dialog
            open
            onClose={onClose}
            title="Refund payment"
            description={`${payment.payment_number} — up to ${money(refundable)} refundable`}
        >
            <div className="space-y-4">
                <div>
                    <Label htmlFor="refund_type">Refund type</Label>
                    <Select id="refund_type" value={type} onChange={(e) => setType(e.target.value as 'full' | 'partial')}>
                        <option value="full">Full refund ({money(refundable)})</option>
                        <option value="partial">Partial refund</option>
                    </Select>
                </div>
                {type === 'partial' && (
                    <div>
                        <Label htmlFor="refund_amount">Amount (BDT)</Label>
                        <Input
                            id="refund_amount"
                            type="number"
                            min={1}
                            max={refundable / 100}
                            value={amount}
                            onChange={(e) => setAmount(e.target.value)}
                        />
                    </div>
                )}
                <div>
                    <Label htmlFor="refund_reason">Reason</Label>
                    <Textarea id="refund_reason" rows={3} value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Why is this being refunded?" />
                </div>
                {error && <p className="text-[13px] text-critical-fg">{error}</p>}
                <div className="flex justify-end gap-2 border-t border-border pt-4">
                    <Button variant="outline" size="sm" onClick={onClose} disabled={refundMutation.isPending}>Cancel</Button>
                    <Button
                        variant="danger"
                        size="sm"
                        disabled={!canSubmit || refundMutation.isPending}
                        onClick={() => void refundMutation.mutateAsync()}
                    >
                        {refundMutation.isPending ? 'Processing…' : 'Issue refund'}
                    </Button>
                </div>
            </div>
        </Dialog>
    );
}

function PaymentDetail({ ulid, onClose }: { ulid: string; onClose: () => void }) {
    const { can } = useAuth();
    const { push } = useToast();
    const queryClient = useQueryClient();
    const [confirmVerify, setConfirmVerify] = useState(false);
    const [confirmReject, setConfirmReject] = useState(false);
    const [showRefund, setShowRefund] = useState(false);

    const { data, isLoading } = useQuery({
        queryKey: ['payment', ulid],
        queryFn: () => financeApi.fetchPayment(ulid),
    });

    const invalidate = () => {
        void queryClient.invalidateQueries({ queryKey: ['payment', ulid] });
        void queryClient.invalidateQueries({ queryKey: ['payments'] });
    };

    const verifyMutation = useMutation({
        mutationFn: (note: string) => financeApi.verifyManual(ulid, note ?? ''),
        onSuccess: () => { push('success', 'Payment verified.'); invalidate(); },
    });
    const rejectMutation = useMutation({
        mutationFn: (reason: string) => financeApi.rejectManual(ulid, reason ?? ''),
        onSuccess: () => { push('success', 'Payment rejected.'); invalidate(); },
    });

    const canVerify = can('payment.verify_manual');
    const canReject = can('payment.reject_manual');
    const canRefund = can('payment.refund');
    const isAwaitingVerification = data?.status === 'awaiting_verification' || data?.status === 'pending';
    const isRefundable = data && (data.status === 'succeeded' || data.status === 'partially_refunded') && data.refunded_paisa < data.amount_paid_paisa;

    return (
        <Dialog open onClose={onClose} title={data?.payment_number ?? 'Payment'} className="max-w-lg">
            {isLoading || !data ? (
                <div className="space-y-3">
                    {Array.from({ length: 5 }).map((_, i) => <Skeleton key={i} className="h-9 w-full" />)}
                </div>
            ) : (
                <div className="space-y-5">
                    <div className="flex items-center gap-2">
                        {statusBadge(data.status)}
                        <Badge tone="neutral">{PAYMENT_METHODS.find((m) => m.value === data.method)?.label ?? data.method}</Badge>
                    </div>

                    <div className="grid grid-cols-2 gap-3 text-[13px]">
                        <div><div className="text-text-faint">Amount due</div><div className="tnum font-medium text-text">{money(data.amount_due_paisa)}</div></div>
                        <div><div className="text-text-faint">Amount paid</div><div className="tnum font-medium text-text">{money(data.amount_paid_paisa)}</div></div>
                        <div><div className="text-text-faint">Fee</div><div className="tnum font-medium text-text">{money(data.fee_paisa)}</div></div>
                        <div><div className="text-text-faint">Net</div><div className="tnum font-medium text-text">{money(data.net_paisa)}</div></div>
                        <div><div className="text-text-faint">Refunded</div><div className="tnum font-medium text-text">{money(data.refunded_paisa)}</div></div>
                        <div><div className="text-text-faint">Payer</div><div className="font-medium text-text">{data.payer_msisdn ?? '—'}</div></div>
                        {data.manual_trx_id && (
                            <div className="col-span-2"><div className="text-text-faint">Manual TrxID</div><div className="font-medium text-text">{data.manual_trx_id}</div></div>
                        )}
                        {data.gateway_transaction_id && (
                            <div className="col-span-2"><div className="text-text-faint">Gateway transaction ID</div><div className="font-medium text-text">{data.gateway_transaction_id}</div></div>
                        )}
                        {data.verified_by_name && (
                            <div className="col-span-2"><div className="text-text-faint">Verified by</div><div className="font-medium text-text">{data.verified_by_name}</div></div>
                        )}
                    </div>

                    <div className="flex flex-wrap justify-end gap-2 border-t border-border pt-4">
                        <Button variant="outline" size="sm" onClick={onClose}>Close</Button>
                        {isAwaitingVerification && canReject && (
                            <Button variant="outline" size="sm" className="text-critical-fg" onClick={() => setConfirmReject(true)}>
                                <Ban size={14} /> Reject
                            </Button>
                        )}
                        {isAwaitingVerification && canVerify && (
                            <Button size="sm" onClick={() => setConfirmVerify(true)}>
                                <CheckCircle2 size={14} /> Verify
                            </Button>
                        )}
                        {isRefundable && canRefund && (
                            <Button variant="danger" size="sm" onClick={() => setShowRefund(true)}>
                                <RotateCcw size={14} /> Refund
                            </Button>
                        )}
                    </div>
                </div>
            )}

            <ConfirmDialog
                open={confirmVerify}
                onClose={() => setConfirmVerify(false)}
                onConfirm={async (note) => { await verifyMutation.mutateAsync(note ?? ''); }}
                title="Verify manual payment?"
                description="This marks the payment as succeeded and confirms the linked registration. This cannot be undone."
                confirmLabel="Verify payment"
                tone="primary"
                reasonLabel="Verification note"
                reasonPlaceholder="e.g. TrxID confirmed against bank statement"
            />
            <ConfirmDialog
                open={confirmReject}
                onClose={() => setConfirmReject(false)}
                onConfirm={async (reason) => { await rejectMutation.mutateAsync(reason ?? ''); }}
                title="Reject manual payment?"
                description="This cancels the linked registration and releases its ticket-type reservation."
                confirmLabel="Reject payment"
                reasonLabel="Rejection reason"
                reasonPlaceholder="e.g. TrxID does not match any bank deposit"
            />
            {showRefund && data && <RefundDialog payment={data} onClose={() => setShowRefund(false)} />}
        </Dialog>
    );
}

const columns: ColumnDef<Payment, unknown>[] = [
    {
        accessorKey: 'payment_number',
        header: 'Payment',
        cell: (ctx) => <span className="font-medium text-text">{ctx.row.original.payment_number}</span>,
    },
    {
        accessorKey: 'method',
        header: 'Method',
        cell: (ctx) => <Badge tone="neutral">{PAYMENT_METHODS.find((m) => m.value === ctx.row.original.method)?.label ?? ctx.row.original.method}</Badge>,
    },
    {
        accessorKey: 'status',
        header: 'Status',
        cell: (ctx) => statusBadge(ctx.row.original.status),
    },
    {
        accessorKey: 'amount_paid_paisa',
        header: 'Paid',
        cell: (ctx) => <span className="tnum">{money(ctx.row.original.amount_paid_paisa)}</span>,
    },
    {
        accessorKey: 'created_at',
        header: 'Created',
        cell: (ctx) => new Date(ctx.row.original.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }),
    },
];

export default function FinancePage() {
    const [tab, setTab] = useState<'all' | 'queue'>('all');
    const [method, setMethod] = useState<PaymentMethod | ''>('');
    const [status, setStatus] = useState('');
    const [dateFrom, setDateFrom] = useState('');
    const [dateTo, setDateTo] = useState('');
    const [pageIndex, setPageIndex] = useState(0);
    const [selected, setSelected] = useState<string | null>(null);
    const pageSize = 20;

    const effectiveStatus = tab === 'queue' ? 'awaiting_verification' : status;

    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['payments', tab, effectiveStatus, method, dateFrom, dateTo, pageIndex],
        queryFn: () =>
            financeApi.fetchPayments({
                status: effectiveStatus,
                method,
                date_from: dateFrom,
                date_to: dateTo,
                page: pageIndex + 1,
                per_page: pageSize,
            }),
    });

    return (
        <div className="space-y-6">
            <div>
                <h1 className="text-[26px] font-bold tracking-tight text-text">Finance</h1>
                <p className="mt-1 text-[14px] text-text-muted">Payments, manual verification, and refunds.</p>
            </div>

            <div className="flex gap-1 rounded-xl border border-border bg-surface p-1 w-fit">
                {(['all', 'queue'] as const).map((t) => (
                    <button
                        key={t}
                        onClick={() => { setTab(t); setPageIndex(0); }}
                        className={cn(
                            'rounded-lg px-3.5 py-1.5 text-[13px] font-medium transition-colors',
                            tab === t ? 'bg-accent text-accent-fg' : 'text-text-muted hover:text-text',
                        )}
                    >
                        {t === 'all' ? 'All payments' : 'Verification queue'}
                    </button>
                ))}
            </div>

            <Card>
                <CardHeader title={tab === 'queue' ? 'Awaiting manual verification' : 'All payments'} />
                <div className="flex flex-wrap items-end gap-3 px-5 pb-4 pt-4">
                    {tab === 'all' && (
                        <div className="w-44">
                            <Label htmlFor="status_filter">Status</Label>
                            <Select id="status_filter" value={status} onChange={(e) => { setStatus(e.target.value); setPageIndex(0); }}>
                                <option value="">All statuses</option>
                                {Object.keys(knownStatusTone).map((s) => (
                                    <option key={s} value={s}>{titleCase(s)}</option>
                                ))}
                            </Select>
                        </div>
                    )}
                    <div className="w-40">
                        <Label htmlFor="method_filter">Method</Label>
                        <Select id="method_filter" value={method} onChange={(e) => { setMethod(e.target.value as PaymentMethod | ''); setPageIndex(0); }}>
                            <option value="">All methods</option>
                            {PAYMENT_METHODS.map((m) => (
                                <option key={m.value} value={m.value}>{m.label}</option>
                            ))}
                        </Select>
                    </div>
                    <div className="w-40">
                        <Label htmlFor="date_from">From</Label>
                        <Input id="date_from" type="date" value={dateFrom} onChange={(e) => { setDateFrom(e.target.value); setPageIndex(0); }} />
                    </div>
                    <div className="w-40">
                        <Label htmlFor="date_to">To</Label>
                        <Input id="date_to" type="date" value={dateTo} onChange={(e) => { setDateTo(e.target.value); setPageIndex(0); }} />
                    </div>
                </div>

                <DataTable
                    columns={columns}
                    data={data?.data ?? []}
                    getRowId={(r) => r.ulid}
                    isLoading={isLoading}
                    isError={isError}
                    onRetry={() => void refetch()}
                    onRowClick={(row) => setSelected(row.ulid)}
                    emptyTitle="No payments found"
                    emptyDescription="Try adjusting your filters."
                    pageIndex={pageIndex}
                    pageSize={pageSize}
                    totalRows={data ? totalOf(data) : 0}
                    onPageChange={setPageIndex}
                />
            </Card>

            {selected && <PaymentDetail ulid={selected} onClose={() => setSelected(null)} />}
        </div>
    );
}
