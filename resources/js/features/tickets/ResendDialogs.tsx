import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AlertTriangle, Mail, MessageSquare, Send } from 'lucide-react';
import { Button, Skeleton, Switch } from '@/components/ui';
import { Dialog } from '@/components/Dialog';
import { useToast } from '@/components/Toast';
import { money } from '@/lib/cn';
import * as ticketsApi from './api';
import type { TicketFilters } from './api';

/**
 * Email and SMS only. `whatsapp` still resolves to a fake driver, and an
 * operator pressing a button that fakes a send would tell the ticket-holder
 * it had gone — see ResendTicketNotification::CHANNELS.
 */
const CHANNELS = [
    { key: 'email', label: 'Email', hint: 'Carries the QR code as an inline image.', icon: Mail },
    { key: 'sms', label: 'SMS', hint: 'One GSM-7 segment, billed against the prepaid balance.', icon: MessageSquare },
] as const;

function ChannelPicker({
    selected,
    onChange,
    disabledChannels = [],
}: {
    selected: string[];
    onChange: (next: string[]) => void;
    disabledChannels?: string[];
}) {
    return (
        <div className="space-y-2">
            {CHANNELS.map(({ key, label, hint, icon: Icon }) => {
                const off = disabledChannels.includes(key);
                return (
                    <div key={key} className="flex items-start gap-3 rounded-md border border-border px-3 py-2.5">
                        <Icon size={15} className="mt-0.5 shrink-0 text-text-faint" />
                        <div className="min-w-0 flex-1">
                            <div className="text-[13px] font-medium text-text">{label}</div>
                            <div className="text-[12px] text-text-muted">{hint}</div>
                            {off && (
                                <div className="mt-1 text-[12px] text-warning-fg">
                                    This channel is switched off in Settings — the message will be queued and then cancelled.
                                </div>
                            )}
                        </div>
                        <Switch
                            label={`Resend by ${label}`}
                            checked={selected.includes(key)}
                            onChange={(on) => onChange(on ? [...selected, key] : selected.filter((c) => c !== key))}
                        />
                    </div>
                );
            })}
        </div>
    );
}

/** What the server said happened, per channel — including the reasons nothing was sent. */
const OUTCOME_COPY: Record<string, string> = {
    queued: 'Queued for delivery.',
    no_recipient: 'Nothing on file to send to — add an address or number to the attendee first.',
    no_template: 'No active template for this channel.',
    duplicate: 'An identical message is already queued.',
};

export function ResendTicketDialog({
    ulid,
    ticketNumber,
    onClose,
}: {
    ulid: string;
    ticketNumber: string;
    onClose: () => void;
}) {
    const { push } = useToast();
    const queryClient = useQueryClient();
    // Email pre-selected, SMS not: it is the one that carries the QR, and
    // it is the one that costs nothing to get wrong.
    const [channels, setChannels] = useState<string[]>(['email']);
    const [result, setResult] = useState<ticketsApi.ResendResult | null>(null);

    const mutation = useMutation({
        mutationFn: () => ticketsApi.resendTicket(ulid, channels),
        onSuccess: (data) => {
            setResult(data);
            const queued = Object.values(data.outcomes).filter((o) => o === 'queued').length;
            if (queued > 0) push('success', `Queued ${queued} message${queued === 1 ? '' : 's'} for ${ticketNumber}.`);
            else push('critical', 'Nothing was queued — see the reasons in the dialog.');
            void queryClient.invalidateQueries({ queryKey: ['notifications'] });
        },
        onError: (e: Error) => push('critical', e.message),
    });

    return (
        <Dialog
            open
            onClose={onClose}
            title="Resend ticket"
            description={`${ticketNumber} — sends the confirmation again to the holder on file.`}
            className="max-w-md"
            footer={
                <div className="flex justify-end gap-2">
                    <Button variant="outline" size="sm" onClick={onClose}>Close</Button>
                    <Button
                        size="sm"
                        disabled={channels.length === 0 || mutation.isPending}
                        onClick={() => mutation.mutate()}
                    >
                        <Send size={14} /> {mutation.isPending ? 'Sending…' : 'Resend'}
                    </Button>
                </div>
            }
        >
            <div className="space-y-4">
                <ChannelPicker selected={channels} onChange={setChannels} disabledChannels={result?.channels_disabled} />

                {result && (
                    <div className="space-y-1.5 rounded-md border border-border bg-surface-2 px-3 py-2.5">
                        {Object.entries(result.outcomes).map(([channel, outcome]) => (
                            <div key={channel} className="text-[12.5px]">
                                <span className="font-medium text-text">
                                    {CHANNELS.find((c) => c.key === channel)?.label ?? channel}:
                                </span>{' '}
                                <span className={outcome === 'queued' ? 'text-text-muted' : 'text-warning-fg'}>
                                    {OUTCOME_COPY[outcome] ?? outcome}
                                </span>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </Dialog>
    );
}

/**
 * The bulk send. Deliberately more work to press than the single one: it
 * spends the prepaid SMS balance, so the recipient count and the cost are
 * shown before the button does anything, and the count is echoed back to
 * the server so a roster that moved while this was open is refused rather
 * than silently sent to.
 */
export function ResendAllDialog({
    filters,
    onClose,
}: {
    filters: Pick<TicketFilters, 'status' | 'ticket_type_id' | 'search'>;
    onClose: () => void;
}) {
    const { push } = useToast();
    const [channels, setChannels] = useState<string[]>(['email']);

    const { data: preview, isLoading, isError } = useQuery({
        queryKey: ['ticket-resend-preview', filters],
        queryFn: () => ticketsApi.fetchResendPreview(filters),
        // No caching: the number the operator agrees to must be current, and
        // the server re-checks it anyway.
        staleTime: 0,
        gcTime: 0,
    });

    const mutation = useMutation({
        mutationFn: () => ticketsApi.resendAllTickets(filters, channels, preview?.tickets ?? -1),
        onSuccess: (data) => {
            push('success', `Queued a resend to ${data.tickets} ticket(s). Progress appears in Notifications.`);
            onClose();
        },
        onError: (e: Error) => push('critical', e.message),
    });

    const filtered = Boolean(filters.status || filters.search || filters.ticket_type_id);
    const smsCost = preview && channels.includes('sms') ? preview.sms_cost_paisa_total : 0;

    const reach = useMemo(() => {
        if (!preview) return null;
        const parts: string[] = [];
        if (channels.includes('email')) parts.push(`${preview.with_email} by email`);
        if (channels.includes('sms')) parts.push(`${preview.with_mobile} by SMS`);
        return parts.join(', ');
    }, [preview, channels]);

    return (
        <Dialog
            open
            onClose={onClose}
            title="Resend to all tickets"
            description={filtered ? 'Sends to the tickets the current filters select.' : 'Sends to every ticket that can still admit someone.'}
            className="max-w-md"
            footer={
                <div className="flex justify-end gap-2">
                    <Button variant="outline" size="sm" onClick={onClose}>Cancel</Button>
                    <Button
                        variant="danger"
                        size="sm"
                        disabled={channels.length === 0 || !preview || preview.tickets === 0 || mutation.isPending}
                        onClick={() => mutation.mutate()}
                    >
                        <Send size={14} />
                        {mutation.isPending ? 'Queueing…' : `Resend to ${preview?.tickets ?? 0}`}
                    </Button>
                </div>
            }
        >
            <div className="space-y-4">
                {isLoading && <Skeleton className="h-20 w-full" />}
                {isError && <div className="text-[13px] text-critical-fg">Could not work out how many tickets this would reach.</div>}

                {preview && (
                    <>
                        <div className="rounded-md border border-border bg-surface-2 px-3 py-3">
                            <div className="tnum text-[22px] font-semibold text-text">{preview.tickets}</div>
                            <div className="text-[12.5px] text-text-muted">
                                ticket{preview.tickets === 1 ? '' : 's'} match{preview.tickets === 1 ? 'es' : ''}
                                {filtered ? ' the current filters' : ''}. Voided and refunded tickets are never included.
                            </div>
                            {reach && <div className="mt-2 text-[12.5px] text-text-muted">Reaches {reach}.</div>}
                        </div>

                        <ChannelPicker selected={channels} onChange={setChannels} disabledChannels={preview.channels_disabled} />

                        {channels.includes('sms') && (
                            <div className="flex items-start gap-2.5 rounded-md border border-warning-border bg-warning-bg px-3 py-2.5">
                                <AlertTriangle size={15} className="mt-0.5 shrink-0 text-warning-fg" />
                                <div className="text-[12.5px] text-warning-fg">
                                    <span className="font-semibold">{money(smsCost)}</span> of prepaid SMS balance,
                                    at {preview.sms_segments_each} segment
                                    {preview.sms_segments_each === 1 ? '' : 's'} each. This cannot be undone once queued.
                                </div>
                            </div>
                        )}
                    </>
                )}
            </div>
        </Dialog>
    );
}
