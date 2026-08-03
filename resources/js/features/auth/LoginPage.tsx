import { useState, type FormEvent } from 'react';
import { Ticket, Lock } from 'lucide-react';
import { useAuth } from './AuthProvider';
import { toApiError } from '@/lib/api';
import { Button, Input, Label, Card } from '@/components/ui';

export default function LoginPage() {
    const { login } = useAuth();
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [totpCode, setTotpCode] = useState('');
    const [needsTotp, setNeedsTotp] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    async function onSubmit(e: FormEvent) {
        e.preventDefault();
        setBusy(true);
        setError(null);
        try {
            await login(email, password, totpCode || undefined);
        } catch (err) {
            const apiErr = toApiError(err);
            if (apiErr.message.toLowerCase().includes('authentication code')) {
                setNeedsTotp(true);
            }
            setError(apiErr.message);
        } finally {
            setBusy(false);
        }
    }

    return (
        <div className="grid min-h-screen place-items-center bg-bg px-4">
            <div className="w-full max-w-sm">
                <div className="mb-8 flex flex-col items-center gap-3">
                    <div className="grid h-12 w-12 place-items-center rounded-2xl bg-accent text-accent-fg shadow-[var(--shadow-soft)]">
                        <Ticket size={22} strokeWidth={2.2} />
                    </div>
                    <div className="text-center">
                        <div className="font-display text-[18px] font-bold tracking-tight text-text">Decent Ticket Management</div>
                        <div className="text-[12.5px] text-text-faint">Admin console</div>
                    </div>
                </div>

                <Card className="p-6">
                    <form onSubmit={onSubmit} className="space-y-4">
                        <div>
                            <Label htmlFor="email">Email</Label>
                            <Input
                                id="email"
                                type="email"
                                autoComplete="username"
                                required
                                value={email}
                                onChange={(e) => setEmail(e.target.value)}
                            />
                        </div>
                        <div>
                            <Label htmlFor="password">Password</Label>
                            <Input
                                id="password"
                                type="password"
                                autoComplete="current-password"
                                required
                                value={password}
                                onChange={(e) => setPassword(e.target.value)}
                            />
                        </div>
                        {needsTotp && (
                            <div>
                                <Label htmlFor="totp">Authenticator code</Label>
                                <Input
                                    id="totp"
                                    inputMode="numeric"
                                    autoComplete="one-time-code"
                                    maxLength={6}
                                    placeholder="6-digit code"
                                    value={totpCode}
                                    onChange={(e) => setTotpCode(e.target.value)}
                                />
                            </div>
                        )}
                        {error && (
                            <p className="rounded-xl bg-critical-bg px-3 py-2 text-[12.5px] text-critical-fg">{error}</p>
                        )}
                        <Button type="submit" disabled={busy} className="w-full">
                            <Lock size={15} /> {busy ? 'Signing in…' : 'Sign in'}
                        </Button>
                    </form>
                </Card>
            </div>
        </div>
    );
}
