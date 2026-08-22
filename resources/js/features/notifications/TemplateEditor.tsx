import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AlertTriangle, Check, X } from 'lucide-react';
import { Badge, Button, Field, Input, Label, Switch, Textarea } from '@/components/ui';
import { useToast } from '@/components/Toast';
import { money } from '@/lib/cn';
import { ApiRequestError } from '@/lib/api';
import * as notificationsApi from './api';
import type { NotificationTemplateSummary } from './types';

/** Recipients the cost projection is quoted against. */
const PROJECTION_RECIPIENTS = 12000;

/**
 * Create or edit one notification template.
 *
 * Two things here are not decoration:
 *
 * **The variable list.** A template referring to a `{{placeholder}}` the
 * dispatching code does not pass does not fail — the raw `{{key}}` is
 * rendered into the message a real person receives. That has already
 * happened once. The only defence is showing the author which variables
 * exist, and marking the ones they have used that do not.
 *
 * **The live cost readout.** An SMS is billed per segment per recipient
 * and the boundaries are invisible while typing: 160 GSM-7 characters is
 * one segment, 161 is two, and one character outside GSM-7 — an emoji, or
 * a plain `|` — drops the whole message to 70 per segment. The difference
 * between a one-segment and a five-segment confirmation across 12,000
 * tickets is tens of thousands of taka, decided by a character nobody
 * thinks about.
 */
export default function TemplateEditor({
    template,
    onClose,
}: {
    template: NotificationTemplateSummary | null;
    onClose: () => void;
}) {
    const { push } = useToast();
    const queryClient = useQueryClient();
    const creating = template === null;

    const [key, setKey] = useState(template?.key ?? '');
    const [channel, setChannel] = useState(template?.channel ?? 'sms');
    const [locale, setLocale] = useState(template?.locale ?? 'en');
    const [subject, setSubject] = useState(template?.subject ?? '');
    const [body, setBody] = useState(template?.body ?? '');
    const [isActive, setIsActive] = useState(template?.is_active ?? true);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});

    // Debounced so a preview is not requested on every keystroke — it is a
    // server round trip, and the number only needs to settle.
    const [debouncedBody, setDebouncedBody] = useState(body);
    useEffect(() => {
        const timer = setTimeout(() => setDebouncedBody(body), 350);
        return () => clearTimeout(timer);
    }, [body]);

    const preview = useQuery({
        queryKey: ['template-preview', debouncedBody, PROJECTION_RECIPIENTS],
        queryFn: () => notificationsApi.previewTemplate(debouncedBody, PROJECTION_RECIPIENTS),
        enabled: channel === 'sms' && debouncedBody.trim().length > 0,
    });

    const save = useMutation({
        mutationFn: () =>
            notificationsApi.saveNotificationTemplate(template?.ulid ?? null, {
                ...(creating ? { key, channel, locale } : {}),
                subject: channel === 'email' ? subject : null,
                body,
                is_active: isActive,
            }),
        onSuccess: () => {
            push('success', creating ? 'Template created.' : 'Template saved.');
            void queryClient.invalidateQueries({ queryKey: ['notification-templates'] });
            onClose();
        },
        onError: (e: Error) => {
            if (e instanceof ApiRequestError) setFieldErrors(e.errors ?? {});
            push('critical', e.message);
        },
    });

    const declared = template?.variables ?? [];
    const usedInBody = Array.from(body.matchAll(/\{\{\s*([A-Za-z0-9_.]+)\s*\}\}/g)).map((m) => m[1]);
    const unknownUsed = Array.from(new Set(usedInBody.filter((v) => declared.length > 0 && !declared.includes(v))));

    return (
        <div className="space-y-4">
            {creating && (
                <div className="grid gap-4 sm:grid-cols-3">
                    <Field id="tpl-key" label="Template key" error={fieldErrors.key?.[0]}>
                        <Input
                            id="tpl-key"
                            value={key}
                            onChange={(e) => setKey(e.target.value)}
                            placeholder="ticket_delivered"
                        />
                    </Field>
                    <Field id="tpl-channel" label="Channel" error={fieldErrors.channel?.[0]}>
                        <select
                            id="tpl-channel"
                            className="h-9 w-full rounded-xl border border-border bg-surface px-3 text-[13px] text-text"
                            value={channel}
                            onChange={(e) => setChannel(e.target.value)}
                        >
                            <option value="sms">SMS</option>
                            <option value="email">Email</option>
                            <option value="whatsapp">WhatsApp</option>
                        </select>
                    </Field>
                    <Field id="tpl-locale" label="Language" error={fieldErrors.locale?.[0]}>
                        <select
                            id="tpl-locale"
                            className="h-9 w-full rounded-xl border border-border bg-surface px-3 text-[13px] text-text"
                            value={locale}
                            onChange={(e) => setLocale(e.target.value)}
                        >
                            <option value="en">English</option>
                            <option value="bn">বাংলা</option>
                        </select>
                    </Field>
                </div>
            )}

            {!creating && (
                <div className="flex flex-wrap items-center gap-2 text-[12px] text-text-muted">
                    <code className="rounded bg-surface-2 px-1.5 py-0.5 font-mono text-[11px]">{template.key}</code>
                    <Badge size="sm">{template.channel}</Badge>
                    <Badge size="sm">{template.locale.toUpperCase()}</Badge>
                    {/* Identity is create-only server-side: changing it would
                        silently retarget every notification using it. */}
                    <span className="text-text-faint">Key, channel and language cannot be changed — create a new template instead.</span>
                </div>
            )}

            {channel === 'email' && (
                <Field id="tpl-subject" label="Subject" error={fieldErrors.subject?.[0]}>
                    <Input id="tpl-subject" value={subject} onChange={(e) => setSubject(e.target.value)} />
                </Field>
            )}

            <Field id="tpl-body" label="Message" error={fieldErrors.body?.[0]}>
                <Textarea
                    id="tpl-body"
                    rows={channel === 'email' ? 10 : 5}
                    value={body}
                    onChange={(e) => setBody(e.target.value)}
                    className="font-mono text-[12.5px]"
                />
            </Field>

            {declared.length > 0 && (
                <div className="rounded-xl border border-border bg-surface-2 px-4 py-3">
                    <Label>Available variables</Label>
                    <div className="mt-1.5 flex flex-wrap gap-1.5">
                        {declared.map((v) => (
                            <button
                                key={v}
                                type="button"
                                onClick={() => setBody((b) => `${b}{{${v}}}`)}
                                className="rounded-lg bg-surface px-2 py-1 font-mono text-[11.5px] text-text-muted ring-1 ring-border hover:text-text"
                            >
                                {`{{${v}}}`}
                            </button>
                        ))}
                    </div>
                    {unknownUsed.length > 0 && (
                        <p className="mt-2 flex items-start gap-1.5 text-[12px] text-critical-fg">
                            <AlertTriangle size={13} className="mt-0.5 shrink-0" />
                            <span>
                                {unknownUsed.map((v) => `{{${v}}}`).join(', ')} {unknownUsed.length === 1 ? 'is' : 'are'} not
                                supplied for this template and will be sent as literal text.
                            </span>
                        </p>
                    )}
                </div>
            )}

            {channel === 'sms' && preview.data && (
                <div className="rounded-xl border border-border px-4 py-3">
                    <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-[12.5px]">
                        <span className="text-text-muted">
                            {preview.data.characters} chars · {preview.data.characters_per_segment}/segment
                        </span>
                        <Badge tone={preview.data.encoding === 'GSM-7' ? 'success' : 'warning'} size="sm">
                            {preview.data.encoding}
                        </Badge>
                        <span className="tnum font-medium text-text">
                            {preview.data.segments} segment{preview.data.segments === 1 ? '' : 's'}
                        </span>
                        <span className="tnum text-text-muted">{money(preview.data.cost_paisa_each)} each</span>
                        <span className="tnum text-text-muted">
                            {money(preview.data.cost_paisa_total)} for {PROJECTION_RECIPIENTS.toLocaleString()}
                        </span>
                    </div>
                    {preview.data.encoding === 'Unicode' && (
                        <p className="mt-2 flex items-start gap-1.5 text-[12px] text-warning-fg">
                            <AlertTriangle size={13} className="mt-0.5 shrink-0" />
                            <span>
                                A character outside GSM-7 cuts each segment from 160 to 70. Bangla text does it, and so do
                                emoji and the characters <code className="font-mono">| {'{ } [ ] ~ ^ \\ €'}</code>.
                            </span>
                        </p>
                    )}
                </div>
            )}

            <div className="flex items-center justify-between gap-3 border-t border-border pt-4">
                <label className="flex items-center gap-2 text-[13px] text-text-muted">
                    <Switch checked={isActive} onChange={setIsActive} label="Active" />
                    Active
                </label>
                <div className="flex gap-2">
                    <Button variant="ghost" size="sm" onClick={onClose} disabled={save.isPending}>
                        <X size={14} /> Cancel
                    </Button>
                    <Button size="sm" onClick={() => save.mutate()} disabled={save.isPending || body.trim() === ''}>
                        <Check size={14} /> {save.isPending ? 'Saving…' : 'Save'}
                    </Button>
                </div>
            </div>
        </div>
    );
}
