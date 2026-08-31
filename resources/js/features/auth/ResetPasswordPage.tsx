import { useState, type FormEvent } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { ArrowLeft, CheckCircle2, KeyRound } from 'lucide-react';
import { Button, Input, Label } from '@/components/ui';
import { ApiRequestError } from '@/lib/api';
import * as authApi from './api';
import AuthShell from './AuthShell';

/** Mirrors ChangePasswordRequest::MIN_LENGTH. */
const MIN_LENGTH = 12;

export default function ResetPasswordPage() {
    const [params] = useSearchParams();
    const token = params.get('token') ?? '';
    const email = params.get('email') ?? '';

    const [password, setPassword] = useState('');
    const [confirmation, setConfirmation] = useState('');
    const [done, setDone] = useState(false);
    const [errors, setErrors] = useState<Record<string, string | undefined>>({});
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    async function onSubmit(e: FormEvent) {
        e.preventDefault();
        setBusy(true);
        setErrors({});
        setError(null);
        try {
            await authApi.resetPassword({
                token,
                email,
                password,
                password_confirmation: confirmation,
            });
            setDone(true);
        } catch (err) {
            const apiErr = err instanceof ApiRequestError ? err : null;
            setErrors({
                token: apiErr?.for('token'),
                password: apiErr?.for('password'),
                email: apiErr?.for('email'),
            });
            if (!apiErr?.errors) setError(apiErr?.message ?? 'Something went wrong. Try again.');
        } finally {
            setBusy(false);
        }
    }

    // A link opened without its query string cannot be completed, and saying so
    // up front beats letting somebody type a password into a form that will
    // certainly fail.
    if (!token || !email) {
        return (
            <AuthShell>
                <div className="space-y-4 text-center">
                    <h1 className="text-[15px] font-semibold text-text">This link is incomplete</h1>
                    <p className="text-[12.5px] leading-relaxed text-text-muted">
                        Open the link straight from the email, or request a new one.
                    </p>
                    <Link to="/forgot-password" className="inline-flex items-center gap-1.5 text-[12.5px] font-medium text-accent hover:underline">
                        Request a new link
                    </Link>
                </div>
            </AuthShell>
        );
    }

    if (done) {
        return (
            <AuthShell>
                <div className="space-y-4 text-center">
                    <div className="mx-auto grid h-11 w-11 place-items-center rounded-xl bg-success-bg text-success-fg">
                        <CheckCircle2 size={20} />
                    </div>
                    <div>
                        <h1 className="text-[15px] font-semibold text-text">Password set</h1>
                        <p className="mt-1 text-[12.5px] leading-relaxed text-text-muted">
                            Sign in with your new password. If two-factor authentication is set up on this account,
                            you will still be asked for your authenticator code.
                        </p>
                    </div>
                    <Link to="/login" className="inline-flex items-center gap-1.5 text-[12.5px] font-medium text-accent hover:underline">
                        Go to sign in
                    </Link>
                </div>
            </AuthShell>
        );
    }

    return (
        <AuthShell>
            <form onSubmit={onSubmit} className="space-y-4">
                <div>
                    <h1 className="text-[15px] font-semibold text-text">Choose a new password</h1>
                    <p className="mt-1 text-[12.5px] leading-relaxed text-text-muted">
                        For <span className="font-medium text-text">{email}</span>. At least {MIN_LENGTH} characters.
                    </p>
                </div>

                <div>
                    <Label htmlFor="password">New password</Label>
                    <Input
                        id="password"
                        type="password"
                        autoComplete="new-password"
                        required
                        autoFocus
                        minLength={MIN_LENGTH}
                        value={password}
                        onChange={(e) => setPassword(e.target.value)}
                    />
                    {errors.password && <p className="mt-1 text-[12px] text-critical-fg">{errors.password}</p>}
                </div>

                <div>
                    <Label htmlFor="password_confirmation">Confirm new password</Label>
                    <Input
                        id="password_confirmation"
                        type="password"
                        autoComplete="new-password"
                        required
                        value={confirmation}
                        onChange={(e) => setConfirmation(e.target.value)}
                    />
                </div>

                {(errors.token || errors.email || error) && (
                    <p className="rounded-xl bg-critical-bg px-3 py-2 text-[12.5px] text-critical-fg">
                        {errors.token ?? errors.email ?? error}
                    </p>
                )}

                <Button type="submit" disabled={busy} className="w-full">
                    <KeyRound size={15} /> {busy ? 'Saving…' : 'Set new password'}
                </Button>

                <Link to="/forgot-password" className="flex items-center justify-center gap-1.5 text-[12.5px] font-medium text-text-muted hover:text-text">
                    <ArrowLeft size={14} /> Request a new link
                </Link>
            </form>
        </AuthShell>
    );
}
