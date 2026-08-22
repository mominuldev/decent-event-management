import { useQuery } from '@tanstack/react-query';
import { AlertTriangle, ExternalLink, RefreshCw, Wallet } from 'lucide-react';
import { Button, Skeleton } from '@/components/ui';
import { useAuth } from '@/features/auth/AuthProvider';
import { cn, money, num } from '@/lib/cn';
import * as settingsApi from './api';
import { timeAgo } from './values';

/**
 * The SMS account's prepaid balance, sitting above the credentials that
 * authenticate the call to fetch it — a balance that will not load is far
 * more often a wrong key than an empty wallet, and the fix is two rows down.
 *
 * **Recharging happens on REVE's portal, not here.** Their API exposes send,
 * status and balance and nothing else, so the button is a link out. Anything
 * that looked like a top-up form in this app would be inventing an endpoint.
 */
export default function SmsBalanceCard() {
    const { can } = useAuth();
    const allowed = can('notification.view_costs');

    const { data, isLoading, isError, error, refetch, isFetching } = useQuery({
        queryKey: ['sms-balance'],
        queryFn: settingsApi.fetchSmsBalance,
        enabled: allowed,
        // Every load costs a round trip to Dhaka, so this is not refetched on
        // focus — the operator refreshes it when they care.
        refetchOnWindowFocus: false,
        staleTime: 60_000,
        retry: false,
    });

    if (!allowed) return null;

    return (
        <div className="border-b border-border px-5 py-4">
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div className="flex items-start gap-3">
                    <div className="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-surface-2 text-text-muted">
                        <Wallet size={17} />
                    </div>
                    <div>
                        <div className="text-[13.5px] font-medium text-text">Account balance</div>

                        {isLoading ? (
                            <Skeleton className="mt-1.5 h-7 w-32" />
                        ) : isError ? (
                            <p className="mt-0.5 max-w-prose text-[12.5px] text-critical-fg">
                                {error instanceof Error ? error.message : 'The gateway could not be reached.'}
                            </p>
                        ) : !data?.configured ? (
                            <p className="mt-0.5 max-w-prose text-[12.5px] text-text-muted">
                                Not connected — {data?.missing?.length ? <>still needed: <strong className="font-medium text-text">{data.missing.join(', ')}</strong>.</> : 'no credentials set.'}{' '}
                                All three are required before anything is sent.
                            </p>
                        ) : !data.balance_available ? (
                            <p className="mt-0.5 max-w-prose text-[12.5px] text-text-muted">
                                Connected, but this account does not report a balance over the API. Check it on the
                                REVE portal — sending is unaffected.
                            </p>
                        ) : (
                            <>
                                <div className="tnum mt-0.5 text-[24px] font-semibold leading-tight text-text">
                                    {data.balance === null ? 'Unknown' : money(Math.round(data.balance * 100))}
                                </div>
                                <p className="mt-0.5 text-[12.5px] text-text-muted">
                                    {data.estimated_segments !== null && (
                                        <>≈ {num(data.estimated_segments)} segments left · </>
                                    )}
                                    Checked {timeAgo(data.checked_at) ?? 'just now'}
                                </p>
                            </>
                        )}
                    </div>
                </div>

                <div className="flex items-center gap-2">
                    <Button variant="ghost" size="sm" onClick={() => void refetch()} disabled={isFetching}>
                        <RefreshCw size={14} className={cn(isFetching && 'animate-spin')} />
                        {isFetching ? 'Checking…' : 'Refresh'}
                    </Button>
                    {data?.recharge_url && (
                        // An anchor, not a Button — this leaves the app, and a
                        // <button> that navigates loses middle-click, "open in
                        // new tab" and the status-bar preview of where it goes.
                        <a
                            href={data.recharge_url}
                            target="_blank"
                            rel="noreferrer noopener"
                            className="inline-flex items-center justify-center gap-2 rounded-xl border border-border-strong px-2.5 py-1.5 text-xs font-semibold text-text transition-colors hover:bg-surface-2"
                        >
                            Recharge at REVE <ExternalLink size={14} />
                        </a>
                    )}
                </div>
            </div>

            {data?.is_low && (
                <div className="mt-3 flex items-start gap-2 rounded-xl border border-warning-border bg-warning-bg px-3 py-2 text-[12.5px] text-warning-fg">
                    <AlertTriangle size={14} className="mt-0.5 shrink-0" />
                    <span>
                        Below the {money(data.low_balance_threshold_paisa ?? 0)} warning threshold. Top up before the
                        next send — a message that fails for want of balance is not retried indefinitely.
                    </span>
                </div>
            )}
        </div>
    );
}
