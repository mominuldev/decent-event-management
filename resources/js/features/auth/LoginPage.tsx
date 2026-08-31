import { useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import { Lock } from 'lucide-react';
import { useAuth } from './AuthProvider';
import { toApiError } from '@/lib/api';
import { Button, Input, Label } from '@/components/ui';
import AuthShell from './AuthShell';

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
        <AuthShell>
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

                <Link
                    to="/forgot-password"
                    className="block text-center text-[12.5px] font-medium text-text-muted hover:text-text"
                >
                    Forgot your password?
                </Link>
            </form>
        </AuthShell>
    );
}
