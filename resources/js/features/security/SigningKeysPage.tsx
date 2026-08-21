import { useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { KeyRound, ShieldAlert, ShieldCheck } from 'lucide-react';
import { Badge, Button, Card, CardHeader, EmptyState, ErrorState, Input, Label, Skeleton, type Tone } from '@/components/ui';
import { ConfirmDialog, Dialog } from '@/components/Dialog';
import { useToast } from '@/components/Toast';
import { useAuth } from '@/features/auth/AuthProvider';
import { toApiError } from '@/lib/api';
import * as keysApi from './api';
import type { FleetReadiness, SigningKey, SigningKeyStatus } from './types';

const STATUS_TONE: Record<SigningKeyStatus, Tone> = {
    active: 'success',
    pending: 'warning',
    retired: 'neutral',
};

function fmt(iso: string | null) {
    return iso ? new Date(iso).toLocaleString() : '—';
}

/**
 * QR signing key rotation (docs/06 §6.5).
 *
 * The screen is built around the one thing that can go badly wrong:
 * activating a key before every scanner holds it. That is why readiness is
 * a whole card rather than a footnote — an operator should never have to go
 * looking for the reason Activate is disabled, and the devices holding it
 * up are named so they can be chased.
 */
export default function SigningKeysPage() {
    const { can } = useAuth();
    const { push } = useToast();
    const queryClient = useQueryClient();

    const [reauthFor, setReauthFor] = useState<(() => Promise<void>) | null>(null);
    const [publishOpen, setPublishOpen] = useState(false);
    const [publishKeyId, setPublishKeyId] = useState('');
    const [forceTarget, setForceTarget] = useState<SigningKey | null>(null);

    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['qr-signing-keys'],
        queryFn: keysApi.fetchSigningKeys,
        // A rotation is a live procedure — devices sync while the operator
        // watches this page, so it has to move on its own.
        refetchInterval: 15_000,
    });

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['qr-signing-keys'] });

    /**
     * Runs an action; if the server says re-authentication has lapsed,
     * collects it and replays the same action, rather than making the
     * operator find their place in the procedure again.
     */
    const guarded = (action: () => Promise<void>) => async () => {
        try {
            await action();
        } catch (e) {
            const error = toApiError(e);
            if (error.code === 'reauthentication_required') {
                setReauthFor(() => action);
                return;
            }
            push('critical', error.message);
        }
    };

    const publish = async (keyId: string) => {
        const key = await keysApi.publishKey(keyId);
        push('success', `Key ${key.key_id} published. Devices pick it up on their next sync.`);
        setPublishOpen(false);
        setPublishKeyId('');
        await invalidate();
    };

    const activate = async (key: SigningKey, force: boolean) => {
        const result = await keysApi.activateKey(key.ulid, force);
        push('success', `Now signing with ${result.key_id}. Event Managers have been notified.`);
        setForceTarget(null);
        await invalidate();
    };

    const retire = async (key: SigningKey) => {
        await keysApi.retireKey(key.ulid);
        push('success', `Key ${key.key_id} retired without ever signing.`);
        await invalidate();
    };

    if (!can('qr.rotate_signing_key')) {
        return (
            <EmptyState
                icon={<ShieldAlert className="h-6 w-6" />}
                title="Not available to your role"
                description="Rotating the QR signing key requires the qr.rotate_signing_key permission, held by Super Admin only."
            />
        );
    }

    if (isLoading) return <Skeleton className="h-64 w-full" />;
    if (isError || !data) return <ErrorState message="Could not load signing keys." onRetry={() => void refetch()} />;

    const pending = data.keys.find((k) => k.status === 'pending');
    const readiness = data.readiness;
    const ready = readiness !== null && readiness.outstanding.length === 0;

    return (
        <div className="space-y-5">
            <Card>
                <CardHeader
                    title="QR signing keys"
                    subtitle="Tickets are Ed25519-signed. Rotation is staged on purpose: publish the key, wait for every scanner to hold it, only then sign with it."
                    action={
                        data.unpublished_key_ids.length > 0 && !pending ? (
                            <Button onClick={() => { setPublishKeyId(data.unpublished_key_ids[0]); setPublishOpen(true); }}>
                                Publish new key
                            </Button>
                        ) : undefined
                    }
                />

                <div className="space-y-3 px-5 pb-5 pt-2">
                    {data.unpublished_key_ids.length === 0 && !pending && (
                        <p className="text-[12.5px] text-text-muted">
                            To start a rotation, run <code className="rounded bg-surface-3 px-1">php artisan qr-signing:generate-key</code> and
                            add the new key to this server&apos;s configuration. It will appear here once deployed.
                        </p>
                    )}

                    {data.keys.length === 0 && (
                        <p className="text-[13px] text-text-muted">
                            No rotation has happened yet. The key currently signing comes from this server&apos;s configuration
                            and is recorded here the first time a replacement is published.
                        </p>
                    )}

                    {data.keys.map((key) => (
                        <div key={key.ulid} className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-surface-2 px-4 py-3">
                            <div className="min-w-0">
                                <div className="flex items-center gap-2">
                                    <KeyRound className="h-4 w-4 text-text-muted" />
                                    <span className="font-medium text-text">{key.key_id}</span>
                                    <Badge tone={STATUS_TONE[key.status]} size="sm">{key.status}</Badge>
                                </div>
                                <div className="mt-1 text-[12.5px] text-text-muted">
                                    Published {fmt(key.published_at)}
                                    {key.activated_at && ` · Signing since ${fmt(key.activated_at)}`}
                                    {key.retired_at && ` · Retired ${fmt(key.retired_at)}`}
                                </div>
                                {key.status === 'retired' && (
                                    <div className="mt-1 text-[12.5px] text-text-muted">
                                        Still published to devices, so tickets signed with it keep working.
                                    </div>
                                )}
                            </div>

                            {key.status === 'pending' && (
                                <div className="flex items-center gap-2">
                                    <Button size="sm" disabled={!ready} onClick={() => void guarded(() => activate(key, false))()}>
                                        Activate
                                    </Button>
                                    {!ready && (
                                        <Button size="sm" variant="outline" onClick={() => setForceTarget(key)}>
                                            Activate anyway…
                                        </Button>
                                    )}
                                    <Button size="sm" variant="outline" onClick={() => void guarded(() => retire(key))()}>
                                        Cancel
                                    </Button>
                                </div>
                            )}
                        </div>
                    ))}
                </div>
            </Card>

            {pending && readiness && <ReadinessCard readiness={readiness} />}

            {publishOpen && (
                <Dialog open onClose={() => setPublishOpen(false)} title="Publish a signing key">
                    <div className="space-y-4">
                        <p className="text-[13px] text-text-muted">
                            This tells every scanner to start trusting the key. Nothing is signed with it until you activate it,
                            so publishing on its own cannot affect the gate.
                        </p>
                        <div>
                            <Label htmlFor="key-id">Key</Label>
                            <Input
                                id="key-id"
                                value={publishKeyId}
                                onChange={(e) => setPublishKeyId(e.target.value)}
                                list="available-keys"
                            />
                            <datalist id="available-keys">
                                {data.unpublished_key_ids.map((id) => <option key={id} value={id} />)}
                            </datalist>
                            <p className="mt-1.5 text-[12px] text-text-muted">
                                Only keys this server already holds the private half of can be published.
                            </p>
                        </div>
                        <div className="flex justify-end gap-2">
                            <Button variant="outline" onClick={() => setPublishOpen(false)}>Cancel</Button>
                            <Button
                                disabled={publishKeyId.trim() === ''}
                                onClick={() => void guarded(() => publish(publishKeyId.trim()))()}
                            >
                                Publish
                            </Button>
                        </div>
                    </div>
                </Dialog>
            )}

            {forceTarget && (
                <ConfirmDialog
                    open
                    onClose={() => setForceTarget(null)}
                    onConfirm={async () => { await guarded(() => activate(forceTarget, true))(); }}
                    title="Activate without every device synced?"
                    description={
                        `${readiness?.outstanding.length ?? 0} device(s) have not confirmed they hold ${forceTarget.key_id}. `
                        + 'Tickets issued from now on will be rejected at those gates until they sync. '
                        + 'This is recorded separately in the audit trail.'
                    }
                    confirmLabel="Activate anyway"
                />
            )}

            {reauthFor && <ReauthDialog onCancel={() => setReauthFor(null)} onConfirmed={async () => { const action = reauthFor; setReauthFor(null); await action(); }} />}
        </div>
    );
}

function ReadinessCard({ readiness }: { readiness: FleetReadiness }) {
    const pct = readiness.total === 0 ? 100 : Math.round((readiness.synced / readiness.total) * 100);
    const allSynced = readiness.outstanding.length === 0;

    return (
        <Card>
            <CardHeader
                title="Scanner readiness"
                subtitle="Every active device must complete one manifest sync before the new key is allowed to sign anything."
            />
            <div className="space-y-4 px-5 pb-5 pt-2">
                <div className="flex items-center gap-3">
                    {allSynced ? <ShieldCheck className="h-5 w-5 text-success" /> : <ShieldAlert className="h-5 w-5 text-warning" />}
                    <div className="flex-1">
                        <div className="text-[13px] font-medium text-text">
                            {readiness.synced} of {readiness.total} devices hold the new key
                        </div>
                        <div className="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-surface-3">
                            <div className={allSynced ? 'h-full bg-success' : 'h-full bg-warning'} style={{ width: `${pct}%` }} />
                        </div>
                    </div>
                </div>

                {!allSynced && (
                    <div>
                        <div className="mb-2 text-[12.5px] text-text-muted">Waiting on:</div>
                        <ul className="space-y-1.5">
                            {readiness.outstanding.map((d) => (
                                <li key={d.device_code} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border px-3 py-2 text-[12.5px]">
                                    <span className="text-text">{d.device_code} · {d.device_name}</span>
                                    <span className="text-text-muted">last sync {fmt(d.last_sync_at)}</span>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </div>
        </Card>
    );
}

/**
 * docs/06 §6.5 requires re-auth for rotation. Shown on demand rather than
 * up front: the operator only proves they are present at the moment they
 * actually do something.
 */
function ReauthDialog({ onCancel, onConfirmed }: { onCancel: () => void; onConfirmed: () => Promise<void> }) {
    const { push } = useToast();
    const [password, setPassword] = useState('');
    const [totp, setTotp] = useState('');
    const [busy, setBusy] = useState(false);

    const submit = async () => {
        setBusy(true);
        try {
            await keysApi.reauthenticate(password, totp || undefined);
            await onConfirmed();
        } catch (e) {
            const error = toApiError(e);
            push('critical', error.errors?.password?.[0] ?? error.errors?.totp_code?.[0] ?? error.message);
        } finally {
            setBusy(false);
        }
    };

    return (
        <Dialog open onClose={onCancel} title="Confirm it's you">
            <div className="space-y-4">
                <p className="text-[13px] text-text-muted">
                    Rotating the signing key affects every scanner at the gate, so it needs your password again.
                </p>
                <div>
                    <Label htmlFor="reauth-password">Password</Label>
                    <Input
                        id="reauth-password"
                        type="password"
                        autoFocus
                        value={password}
                        onChange={(e) => setPassword(e.target.value)}
                    />
                </div>
                <div>
                    <Label htmlFor="reauth-totp">Two-factor code</Label>
                    <Input
                        id="reauth-totp"
                        inputMode="numeric"
                        autoComplete="one-time-code"
                        value={totp}
                        onChange={(e) => setTotp(e.target.value)}
                    />
                    <p className="mt-1.5 text-[12px] text-text-muted">Required if two-factor authentication is enabled on your account.</p>
                </div>
                <div className="flex justify-end gap-2">
                    <Button variant="outline" onClick={onCancel}>Cancel</Button>
                    <Button disabled={busy || password === ''} onClick={() => void submit()}>Confirm</Button>
                </div>
            </div>
        </Dialog>
    );
}
