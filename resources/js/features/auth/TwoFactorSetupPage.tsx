import { useEffect, useState, type FormEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import { ShieldCheck, KeyRound } from 'lucide-react';
import * as authApi from './api';
import { useAuth } from './AuthProvider';
import { toApiError } from '@/lib/api';
import { Button, Input, Label, Card } from '@/components/ui';

export default function TwoFactorSetupPage() {
    const { pendingUser, completeTwoFactorSetup, logout } = useAuth();
    const navigate = useNavigate();
    const [secret, setSecret] = useState<string | null>(null);
    const [qr, setQr] = useState<string | null>(null);
    const [code, setCode] = useState('');
    const [recoveryCodes, setRecoveryCodes] = useState<string[] | null>(null);
    const [confirmedToken, setConfirmedToken] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        authApi
            .setupTwoFactor()
            .then((s) => {
                setSecret(s.secret);
                setQr(s.qr_code_svg);
            })
            .catch((err) => setError(toApiError(err).message));
    }, []);

    async function onConfirm(e: FormEvent) {
        e.preventDefault();
        setBusy(true);
        setError(null);
        try {
            const result = await authApi.confirmTwoFactor(code);
            // Hold the new token locally until the operator has seen (and can
            // copy) the recovery codes — swapping it into AuthProvider now
            // would flip status to 'authed' and the route guard would
            // navigate away before they've been read.
            setRecoveryCodes(result.recovery_codes);
            setConfirmedToken(result.token);
        } catch (err) {
            setError(toApiError(err).message);
        } finally {
            setBusy(false);
        }
    }

    async function onContinue() {
        if (!confirmedToken) return;
        await completeTwoFactorSetup(confirmedToken);
        navigate('/', { replace: true });
    }

    return (
        <div className="grid min-h-screen place-items-center bg-bg px-4">
            <div className="w-full max-w-md">
                <div className="mb-6 flex flex-col items-center gap-3 text-center">
                    <div className="grid h-12 w-12 place-items-center rounded-2xl bg-accent text-accent-fg shadow-[var(--shadow-soft)]">
                        <ShieldCheck size={22} strokeWidth={2.2} />
                    </div>
                    <div>
                        <div className="font-display text-[18px] font-bold tracking-tight text-text">Set up two-factor authentication</div>
                        <div className="text-[12.5px] text-text-faint">
                            Required for {pendingUser?.name ?? 'your account'} before continuing
                        </div>
                    </div>
                </div>

                <Card className="p-6">
                    {!recoveryCodes ? (
                        <form onSubmit={onConfirm} className="space-y-4">
                            <p className="text-[13px] text-text-muted">
                                Scan this QR code with your authenticator app (Google Authenticator, 1Password, Authy), or enter the key manually.
                            </p>
                            {qr && (
                                <div
                                    className="qr-plate mx-auto grid w-fit place-items-center rounded-xl p-3"
                                    dangerouslySetInnerHTML={{ __html: qr }}
                                />
                            )}
                            {secret && (
                                <p className="break-all rounded-xl bg-surface-2 px-3 py-2 text-center font-mono text-[12px] text-text-muted">
                                    {secret}
                                </p>
                            )}
                            <div>
                                <Label htmlFor="code">6-digit code</Label>
                                <Input
                                    id="code"
                                    inputMode="numeric"
                                    autoComplete="one-time-code"
                                    maxLength={6}
                                    required
                                    value={code}
                                    onChange={(e) => setCode(e.target.value)}
                                />
                            </div>
                            {error && <p className="rounded-xl bg-critical-bg px-3 py-2 text-[12.5px] text-critical-fg">{error}</p>}
                            <Button type="submit" disabled={busy || !secret} className="w-full">
                                <KeyRound size={15} /> {busy ? 'Confirming…' : 'Enable 2FA'}
                            </Button>
                            <Button type="button" variant="ghost" className="w-full" onClick={() => void logout()}>
                                Cancel and sign out
                            </Button>
                        </form>
                    ) : (
                        <div className="space-y-4">
                            <p className="text-[13px] text-text-muted">
                                Save these one-time recovery codes somewhere safe. Each can be used once if you lose access to your authenticator.
                            </p>
                            <div className="grid grid-cols-2 gap-2 rounded-xl bg-surface-2 p-3 font-mono text-[12.5px] text-text">
                                {recoveryCodes.map((rc) => (
                                    <div key={rc}>{rc}</div>
                                ))}
                            </div>
                            <Button className="w-full" onClick={onContinue}>
                                I've saved these — continue
                            </Button>
                        </div>
                    )}
                </Card>
            </div>
        </div>
    );
}
