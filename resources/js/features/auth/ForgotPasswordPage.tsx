import { useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import { ArrowLeft, MailCheck, Send } from 'lucide-react';
import { Button, Input, Label } from '@/components/ui';
import { ApiRequestError } from '@/lib/api';
import * as authApi from './api';
import AuthShell from './AuthShell';

export default function ForgotPasswordPage() {
    const [email, setEmail] = useState('');
    const [sent, setSent] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    async function onSubmit(e: FormEvent) {
        e.preventDefault();
        setBusy(true);
        setError(null);
        try {
            await authApi.requestPasswordReset(email.trim());
            setSent(true);
        } catch (err) {
            const apiErr = err instanceof ApiRequestError ? err : null;
            // 429 is the one case worth naming: it is the only outcome the
            // reader can act on, by waiting.
            setError(apiErr?.for('email') ?? apiErr?.message ?? 'Something went wrong. Try again.');
        } finally {
            setBusy(false);
        }
    }

    if (sent) {
        return (
            <AuthShell>
                <div className="space-y-4 text-center">
                    <div className="mx-auto grid h-11 w-11 place-items-center rounded-xl bg-success-bg text-success-fg">
                        <MailCheck size={20} />
                    </div>
                    <div>
                        <h1 className="text-[15px] font-semibold text-text">Check your email</h1>
                        {/* Deliberately does not confirm the address has an account — the
                            API answers the same either way, and so must this. */}
                        <p className="mt-1 text-[12.5px] leading-relaxed text-text-muted">
                            If <span className="font-medium text-text">{email.trim()}</span> belongs to a staff account,
                            a reset link is on its way. It works once and expires in an hour.
                        </p>
                    </div>
                    <Link to="/login" className="inline-flex items-center gap-1.5 text-[12.5px] font-medium text-accent hover:underline">
                        <ArrowLeft size={14} /> Back to sign in
                    </Link>
                </div>
            </AuthShell>
        );
    }

    return (
        <AuthShell>
            <form onSubmit={onSubmit} className="space-y-4">
                <div>
                    <h1 className="text-[15px] font-semibold text-text">Forgot your password?</h1>
                    <p className="mt-1 text-[12.5px] leading-relaxed text-text-muted">
                        Enter the address you sign in with and we will email you a link to set a new password.
                    </p>
                </div>

                <div>
                    <Label htmlFor="email">Email</Label>
                    <Input
                        id="email"
                        type="email"
                        autoComplete="username"
                        required
                        autoFocus
                        value={email}
                        onChange={(e) => setEmail(e.target.value)}
                    />
                </div>

                {error && <p className="rounded-xl bg-critical-bg px-3 py-2 text-[12.5px] text-critical-fg">{error}</p>}

                <Button type="submit" disabled={busy} className="w-full">
                    <Send size={15} /> {busy ? 'Sending…' : 'Email me a link'}
                </Button>

                <Link to="/login" className="flex items-center justify-center gap-1.5 text-[12.5px] font-medium text-text-muted hover:text-text">
                    <ArrowLeft size={14} /> Back to sign in
                </Link>
            </form>
        </AuthShell>
    );
}
