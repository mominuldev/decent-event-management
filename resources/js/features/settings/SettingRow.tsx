import { useEffect, useRef, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Check, Globe, KeyRound, Lock, Pencil, X } from 'lucide-react';
import { Badge, Button, Input, Switch, Textarea } from '@/components/ui';
import { useToast } from '@/components/Toast';
import { cn } from '@/lib/cn';
import * as settingsApi from './api';
import type { EventSetting } from './types';
import { displayValue, fromDraft, timeAgo, toDraft, validateDraft } from './values';

/** Types whose editor needs the full row width rather than a fixed column. */
const WIDE_TYPES = new Set(['json']);

function MetaLine({ setting }: { setting: EventSetting }) {
    const changed = timeAgo(setting.updated_at);

    return (
        <div className="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] text-text-faint">
            <code className="rounded bg-surface-2 px-1.5 py-0.5 font-mono text-[10.5px]">{setting.key}</code>
            {setting.is_secret && (
                <span className="inline-flex items-center gap-1" title="Stored encrypted; never sent back to the browser">
                    <KeyRound size={11} /> Encrypted
                </span>
            )}
            {setting.is_public ? (
                <span className="inline-flex items-center gap-1" title="Readable on the public website">
                    <Globe size={11} /> Public
                </span>
            ) : (
                <span className="inline-flex items-center gap-1" title="Staff only — never sent to the public site">
                    <Lock size={11} /> Internal
                </span>
            )}
            {changed && (
                <span>
                    Changed {changed}
                    {setting.updated_by ? ` by ${setting.updated_by}` : ''}
                </span>
            )}
        </div>
    );
}

export default function SettingRow({ setting, canEdit }: { setting: EventSetting; canEdit: boolean }) {
    const { push } = useToast();
    const queryClient = useQueryClient();
    const [editing, setEditing] = useState(false);
    const [draft, setDraft] = useState(() => toDraft(setting));
    const [error, setError] = useState<string | null>(null);
    const inputRef = useRef<HTMLInputElement>(null);
    const textareaRef = useRef<HTMLTextAreaElement>(null);

    // A save elsewhere (or a refetch) can change the stored value underneath a
    // row that isn't being edited — keep the draft in step so opening the
    // editor never starts from a stale value.
    useEffect(() => {
        if (!editing) setDraft(toDraft(setting));
    }, [setting, editing]);

    useEffect(() => {
        if (editing) (inputRef.current ?? textareaRef.current)?.focus();
    }, [editing]);

    const save = useMutation({
        mutationFn: (value: unknown) => settingsApi.updateSetting(setting.key, value),
        onSuccess: () => {
            push('success', `${setting.label} saved.`);
            void queryClient.invalidateQueries({ queryKey: ['settings'] });
            setEditing(false);
            setError(null);
        },
        onError: (e: Error) => {
            setError(e.message);
            push('critical', e.message);
        },
    });

    const isBool = setting.type === 'bool';
    const isSecret = setting.is_secret;
    const isWide = WIDE_TYPES.has(setting.type);
    // For a secret the editor always opens blank, so "unchanged" cannot mean
    // "same as stored". Typing anything is a replace; leaving it blank is a
    // clear, which is only offered when something is actually stored.
    const dirty = isSecret ? draft.trim() !== '' || Boolean(setting.is_set) : draft !== toDraft(setting);

    function commit() {
        const message = validateDraft(setting, draft);
        if (message) {
            setError(message);
            return;
        }
        save.mutate(fromDraft(setting, draft));
    }

    function cancel() {
        setDraft(toDraft(setting));
        setError(null);
        setEditing(false);
    }

    return (
        <div className="border-b border-border px-5 py-4 last:border-0">
            <div className={cn('flex flex-col gap-3', !isWide && 'md:flex-row md:items-start md:justify-between md:gap-6')}>
                <div className="min-w-0 flex-1">
                    <div className="text-[13.5px] font-medium text-text">{setting.label}</div>
                    {setting.description && (
                        <p className="mt-0.5 max-w-prose text-[12.5px] leading-relaxed text-text-muted">{setting.description}</p>
                    )}
                    <MetaLine setting={setting} />
                </div>

                <div className={cn('shrink-0', !isWide && 'md:w-[300px]')}>
                    {isBool ? (
                        <BooleanControl
                            setting={setting}
                            canEdit={canEdit}
                            pending={save.isPending}
                            onChange={(next) => save.mutate(next)}
                        />
                    ) : editing ? (
                        <div className="space-y-2">
                            {isWide ? (
                                <Textarea
                                    ref={textareaRef}
                                    rows={6}
                                    value={draft}
                                    onChange={(e) => {
                                        setDraft(e.target.value);
                                        setError(null);
                                    }}
                                    onKeyDown={(e) => e.key === 'Escape' && cancel()}
                                    className="font-mono text-[12.5px]"
                                    aria-label={setting.label}
                                    aria-invalid={error !== null}
                                />
                            ) : (
                                <Input
                                    ref={inputRef}
                                    type={
                                        isSecret ? 'password'
                                            : setting.type === 'datetime' ? 'datetime-local'
                                                : setting.type === 'int' || setting.type === 'money' ? 'number'
                                                    : 'text'
                                    }
                                    autoComplete="off"
                                    spellCheck={false}
                                    placeholder={isSecret ? (setting.is_set ? 'Enter a new key to replace it' : 'Paste the key from your REVE account') : undefined}
                                    value={draft}
                                    onChange={(e) => {
                                        setDraft(e.target.value);
                                        setError(null);
                                    }}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter') commit();
                                        if (e.key === 'Escape') cancel();
                                    }}
                                    aria-label={setting.label}
                                    aria-invalid={error !== null}
                                    className={cn(error && 'border-critical-fg')}
                                />
                            )}

                            {setting.type === 'money' && !isSecret && (
                                <p className="text-[11.5px] text-text-faint">Amount in paisa — 100 paisa is ৳1.</p>
                            )}

                            {isSecret && (
                                <p className="text-[11.5px] text-text-faint">
                                    {setting.is_set
                                        ? 'Saving replaces the stored key. Leave it blank and save to remove it entirely.'
                                        : 'Stored encrypted. It cannot be read back afterwards — only replaced.'}
                                </p>
                            )}

                            {error && <p className="text-[12px] text-critical-fg">{error}</p>}

                            <div className="flex items-center gap-2">
                                <Button size="sm" onClick={commit} disabled={save.isPending || !dirty}>
                                    <Check size={14} /> {save.isPending ? 'Saving…' : 'Save'}
                                </Button>
                                <Button variant="ghost" size="sm" onClick={cancel} disabled={save.isPending}>
                                    <X size={14} /> Cancel
                                </Button>
                            </div>
                        </div>
                    ) : (
                        <div className="flex items-start justify-between gap-2 md:justify-end">
                            <span
                                className={cn(
                                    'tnum min-w-0 break-words text-[13px]',
                                    isSecret
                                        ? setting.is_set ? 'font-mono text-text' : 'text-text-faint'
                                        : setting.typed_value === null || setting.typed_value === '' ? 'text-text-faint' : 'text-text',
                                )}
                                title={isSecret ? undefined : displayValue(setting)}
                            >
                                {displayValue(setting)}
                            </span>
                            {canEdit && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => setEditing(true)}
                                    aria-label={`${isSecret ? (setting.is_set ? 'Replace' : 'Set') : 'Edit'} ${setting.label}`}
                                >
                                    {isSecret ? <KeyRound size={14} /> : <Pencil size={14} />}
                                    {isSecret ? (setting.is_set ? 'Replace' : 'Set') : 'Edit'}
                                </Button>
                            )}
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}

/**
 * A toggle saves on flip — a kill switch is the control most likely to be
 * reached for in a hurry, and edit → pick → save is three interactions for a
 * two-state value.
 */
function BooleanControl({
    setting,
    canEdit,
    pending,
    onChange,
}: {
    setting: EventSetting;
    canEdit: boolean;
    pending: boolean;
    onChange: (next: boolean) => void;
}) {
    const on = Boolean(setting.typed_value);

    if (!canEdit) {
        return (
            <div className="md:text-right">
                <Badge tone={on ? 'success' : 'neutral'}>{on ? 'On' : 'Off'}</Badge>
            </div>
        );
    }

    return (
        <div className="flex items-center gap-3 md:justify-end">
            <span className={cn('text-[12.5px]', on ? 'text-text' : 'text-text-muted')}>
                {pending ? 'Saving…' : on ? 'On' : 'Off'}
            </span>
            <Switch checked={on} disabled={pending} onChange={onChange} label={setting.label} />
        </div>
    );
}
