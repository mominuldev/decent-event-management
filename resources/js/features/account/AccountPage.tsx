import { useEffect, useState, type FormEvent } from 'react';
import { useMutation } from '@tanstack/react-query';
import { KeyRound, ShieldCheck, UserRound } from 'lucide-react';
import { Badge, Button, Card, CardHeader, Field, Input } from '@/components/ui';
import { useToast } from '@/components/Toast';
import { useAuth } from '@/features/auth/AuthProvider';
import { ApiRequestError } from '@/lib/api';
import * as accountApi from './api';
import { MIN_PASSWORD_LENGTH, type ChangePasswordPayload, type UpdateProfilePayload } from './types';

/** Field errors from the last failed save, keyed as the API names them. */
type FieldErrors = Record<string, string | undefined>;

function fieldErrors(error: unknown): FieldErrors {
    if (!(error instanceof ApiRequestError) || !error.errors) return {};

    return Object.fromEntries(
        Object.entries(error.errors).map(([field, messages]) => [field, messages[0]]),
    );
}

function ProfileCard() {
    const { session, applySession } = useAuth();
    const { push } = useToast();

    const [form, setForm] = useState<UpdateProfilePayload>({ name: '', email: '', phone: '' });

    // The session arrives asynchronously, and can change under us when the
    // save below returns — so the controls track it rather than being seeded
    // once from whatever happened to be loaded at first render.
    useEffect(() => {
        if (!session) return;
        setForm({ name: session.name, email: session.email, phone: session.phone ?? '' });
    }, [session]);

    const save = useMutation({
        mutationFn: (payload: UpdateProfilePayload) => accountApi.updateProfile(payload),
        onSuccess: (updated) => {
            // The response is a whole session, so the sidebar and the header
            // update without a second request.
            applySession(updated);
            push('success', 'Your details were saved.');
        },
        onError: (e: Error) => push('critical', e.message),
    });

    const errors = fieldErrors(save.error);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        save.mutate({
            name: form.name.trim(),
            email: form.email.trim(),
            // An emptied box means "no number", not an empty string — the
            // column is nullable and a blank would be a value.
            phone: form.phone?.trim() ? form.phone.trim() : null,
        });
    };

    return (
        <Card>
            <CardHeader title="Your details" subtitle="The name shown beside your actions, and the address you sign in with." />
            <form className="grid gap-4 px-5 py-5 sm:grid-cols-2" onSubmit={submit}>
                <Field id="account-name" label="Full name" error={errors.name}>
                    <Input
                        id="account-name"
                        value={form.name}
                        maxLength={150}
                        onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
                    />
                </Field>

                <Field id="account-email" label="Email address" hint="This is your sign-in address." error={errors.email}>
                    <Input
                        id="account-email"
                        type="email"
                        value={form.email}
                        maxLength={190}
                        onChange={(e) => setForm((f) => ({ ...f, email: e.target.value }))}
                    />
                </Field>

                <Field id="account-phone" label="Phone" optional error={errors.phone}>
                    <Input
                        id="account-phone"
                        value={form.phone ?? ''}
                        maxLength={20}
                        placeholder="+8801711022299"
                        onChange={(e) => setForm((f) => ({ ...f, phone: e.target.value }))}
                    />
                </Field>

                <div className="sm:col-span-2 flex justify-end">
                    <Button type="submit" disabled={save.isPending}>
                        {save.isPending ? 'Saving…' : 'Save details'}
                    </Button>
                </div>
            </form>
        </Card>
    );
}

const EMPTY_PASSWORD_FORM: ChangePasswordPayload = {
    current_password: '',
    password: '',
    password_confirmation: '',
};

function PasswordCard() {
    const { push } = useToast();
    const [form, setForm] = useState<ChangePasswordPayload>(EMPTY_PASSWORD_FORM);

    const change = useMutation({
        mutationFn: (payload: ChangePasswordPayload) => accountApi.changePassword(payload),
        onSuccess: (result) => {
            setForm(EMPTY_PASSWORD_FORM);
            push(
                'success',
                result.other_sessions_revoked > 0
                    ? `Password changed. ${result.other_sessions_revoked} other session${result.other_sessions_revoked === 1 ? '' : 's'} signed out.`
                    : 'Password changed.',
            );
        },
        onError: (e: Error) => push('critical', e.message),
    });

    const errors = fieldErrors(change.error);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        change.mutate(form);
    };

    return (
        <Card>
            <CardHeader
                title="Password"
                subtitle={`At least ${MIN_PASSWORD_LENGTH} characters. Changing it signs out every other device.`}
            />
            <form className="grid gap-4 px-5 py-5 sm:grid-cols-2" onSubmit={submit}>
                <Field id="account-current-password" label="Current password" className="sm:col-span-2" error={errors.current_password}>
                    <Input
                        id="account-current-password"
                        type="password"
                        autoComplete="current-password"
                        value={form.current_password}
                        onChange={(e) => setForm((f) => ({ ...f, current_password: e.target.value }))}
                    />
                </Field>

                <Field id="account-new-password" label="New password" error={errors.password}>
                    <Input
                        id="account-new-password"
                        type="password"
                        autoComplete="new-password"
                        value={form.password}
                        onChange={(e) => setForm((f) => ({ ...f, password: e.target.value }))}
                    />
                </Field>

                <Field id="account-confirm-password" label="Confirm new password">
                    <Input
                        id="account-confirm-password"
                        type="password"
                        autoComplete="new-password"
                        value={form.password_confirmation}
                        onChange={(e) => setForm((f) => ({ ...f, password_confirmation: e.target.value }))}
                    />
                </Field>

                <div className="sm:col-span-2 flex justify-end">
                    <Button type="submit" disabled={change.isPending}>
                        {change.isPending ? 'Changing…' : 'Change password'}
                    </Button>
                </div>
            </form>
        </Card>
    );
}

function RolesCard() {
    const { session } = useAuth();

    return (
        <Card>
            <CardHeader
                title="Access"
                subtitle="What you can do here. Roles are assigned by a Super Admin and cannot be changed from this page."
            />
            <div className="flex flex-wrap items-center gap-2 px-5 py-5">
                {session?.roles.length
                    ? session.roles.map((role) => (
                          <Badge key={role} tone="info">
                              <ShieldCheck size={13} /> {role}
                          </Badge>
                      ))
                    : <p className="text-[13px] text-text-muted">No roles assigned, so this account can sign in but do nothing.</p>}
            </div>
        </Card>
    );
}

export default function AccountPage() {
    return (
        <div className="mx-auto grid max-w-3xl gap-5">
            <header className="flex items-center gap-3">
                <div className="grid h-10 w-10 place-items-center rounded-xl bg-surface-2 text-text-muted">
                    <UserRound size={18} />
                </div>
                <div>
                    <h1 className="text-[17px] font-semibold text-text">My account</h1>
                    <p className="text-[12.5px] text-text-muted">Your own details and password. Nothing here affects anybody else.</p>
                </div>
            </header>

            <ProfileCard />

            <div className="flex items-center gap-2 pt-1 text-text-muted">
                <KeyRound size={15} />
                <span className="text-[12.5px] font-medium uppercase tracking-wide">Security</span>
            </div>

            <PasswordCard />
            <RolesCard />
        </div>
    );
}
