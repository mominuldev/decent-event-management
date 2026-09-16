import { useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import { ArrowRight, Eye, EyeOff, ShieldCheck } from 'lucide-react';
import { useAuth } from './AuthProvider';
import { toApiError } from '@/lib/api';
import { Button, Input, Label } from '@/components/ui';
import AuthShell from './AuthShell';

export default function LoginPage() {
    const { login } = useAuth();
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [showPassword, setShowPassword] = useState(false);
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
            <div className="mb-7">
                <h1 className="font-display text-[24px] font-bold tracking-tight text-text">Sign in</h1>
                <p className="mt-1 text-[13px] text-text-muted">Use your staff email and password.</p>
            </div>

            <form onSubmit={onSubmit} className="space-y-4">
                <div>
                    <Label htmlFor="email">Email</Label>
                    <Input
                        id="email"
                        type="email"
                        autoComplete="username"
                        autoFocus
                        required
                        placeholder="you@example.com"
                        className="h-11"
                        value={email}
                        onChange={(e) => setEmail(e.target.value)}
                    />
                </div>
                <div>
                    <div className="mb-1.5 flex items-baseline justify-between">
                        <Label htmlFor="password">Password</Label>
                        <Link
                            to="/forgot-password"
                            className="text-[12px] font-medium text-text-muted transition-colors hover:text-accent"
                        >
                            Forgot it?
                        </Link>
                    </div>
                    <div className="relative">
                        <Input
                            id="password"
                            type={showPassword ? 'text' : 'password'}
                            autoComplete="current-password"
                            required
                            className="h-11 pr-11"
                            value={password}
                            onChange={(e) => setPassword(e.target.value)}
                        />
                        <button
                            type="button"
                            onClick={() => setShowPassword((v) => !v)}
                            aria-label={showPassword ? 'Hide password' : 'Show password'}
                            aria-pressed={showPassword}
                            className="absolute inset-y-0 right-0 grid w-11 place-items-center rounded-r-xl text-text-faint transition-colors hover:text-text"
                        >
                            {showPassword ? <EyeOff size={16} /> : <Eye size={16} />}
                        </button>
                    </div>
                </div>
                {needsTotp && (
                    <div className="rounded-2xl border border-brand-200 bg-brand-50 p-4 dark:border-brand-500/25 dark:bg-brand-500/10">
                        <div className="mb-2 flex items-center gap-2 text-[12.5px] font-semibold text-text">
                            <ShieldCheck size={15} className="text-accent" /> Second step
                        </div>
                        <Label htmlFor="totp">Authenticator code</Label>
                        <Input
                            id="totp"
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            autoFocus
                            maxLength={6}
                            placeholder="6-digit code"
                            className="h-11 text-center text-[18px] font-semibold tracking-[0.35em] tnum placeholder:text-[13.5px] placeholder:font-normal placeholder:tracking-normal"
                            value={totpCode}
                            onChange={(e) => setTotpCode(e.target.value.replace(/\D/g, ''))}
                        />
                    </div>
                )}
                {error && (
                    <p role="alert" className="rounded-xl border border-critical-border bg-critical-bg px-3.5 py-2.5 text-[12.5px] text-critical-fg">
                        {error}
                    </p>
                )}
                <Button type="submit" disabled={busy} className="group h-11 w-full text-[14px]">
                    {busy ? 'Signing in…' : 'Sign in'}
                    {!busy && <ArrowRight size={16} className="transition-transform group-hover:translate-x-0.5" />}
                </Button>
            </form>

            {/* True, and the answer to the question a locked-out newcomer has: there is no self-signup. */}
            <p className="mt-6 text-[12px] leading-relaxed text-text-faint">
                No account yet? There is no sign-up here — an administrator creates every staff account.
            </p>
        </AuthShell>
    );
}
