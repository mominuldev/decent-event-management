import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Pencil } from 'lucide-react';
import { Badge, Button, Card, CardHeader, Input, Select, Skeleton } from '@/components/ui';
import { useAuth } from '@/features/auth/AuthProvider';
import { useToast } from '@/components/Toast';
import { titleCase } from '@/lib/format';
import * as settingsApi from './api';
import type { EventSetting } from './types';

function SettingRow({ setting }: { setting: EventSetting }) {
    const { can } = useAuth();
    const { push } = useToast();
    const queryClient = useQueryClient();
    const [editing, setEditing] = useState(false);
    const [draft, setDraft] = useState<string>(
        typeof setting.typed_value === 'object' && setting.typed_value !== null
            ? JSON.stringify(setting.typed_value)
            : String(setting.typed_value ?? ''),
    );

    const canEdit = can('settings.update');
    const kind =
        typeof setting.typed_value === 'boolean' ? 'boolean' :
        typeof setting.typed_value === 'number' ? 'number' :
        typeof setting.typed_value === 'object' && setting.typed_value !== null ? 'json' :
        'string';

    const updateMutation = useMutation({
        mutationFn: () => {
            const value =
                kind === 'boolean' ? draft === 'true' :
                kind === 'number' ? Number(draft) :
                draft;
            return settingsApi.updateSetting(setting.key, value);
        },
        onSuccess: () => {
            push('success', `${setting.label} updated.`);
            void queryClient.invalidateQueries({ queryKey: ['settings'] });
            setEditing(false);
        },
        onError: (e: Error) => push('critical', e.message),
    });

    return (
        <div className="flex items-start justify-between gap-4 border-b border-border px-5 py-3.5 last:border-0">
            <div className="min-w-0 flex-1">
                <div className="text-[13.5px] font-medium text-text">{setting.label}</div>
                {setting.description && <div className="mt-0.5 text-[12.5px] text-text-muted">{setting.description}</div>}
                <div className="mt-0.5 text-[11px] text-text-faint">{setting.key}</div>
            </div>

            <div className="flex shrink-0 items-center gap-2">
                {editing ? (
                    <>
                        {kind === 'boolean' ? (
                            <Select value={draft} onChange={(e) => setDraft(e.target.value)} className="w-28">
                                <option value="true">True</option>
                                <option value="false">False</option>
                            </Select>
                        ) : (
                            <Input
                                type={kind === 'number' ? 'number' : 'text'}
                                value={draft}
                                onChange={(e) => setDraft(e.target.value)}
                                className="w-56"
                                autoFocus
                            />
                        )}
                        <Button variant="outline" size="sm" onClick={() => setEditing(false)} disabled={updateMutation.isPending}>Cancel</Button>
                        <Button size="sm" onClick={() => void updateMutation.mutateAsync()} disabled={updateMutation.isPending}>
                            {updateMutation.isPending ? 'Saving…' : 'Save'}
                        </Button>
                    </>
                ) : (
                    <>
                        {kind === 'boolean' ? (
                            <Badge tone={setting.typed_value ? 'success' : 'neutral'}>{setting.typed_value ? 'True' : 'False'}</Badge>
                        ) : (
                            <span className="tnum max-w-[220px] truncate text-[13px] text-text" title={draft}>{draft || '—'}</span>
                        )}
                        {canEdit && (
                            <Button variant="ghost" size="sm" onClick={() => setEditing(true)}>
                                <Pencil size={14} />
                            </Button>
                        )}
                    </>
                )}
            </div>
        </div>
    );
}

export default function SettingsPage() {
    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['settings'],
        queryFn: settingsApi.fetchSettings,
    });

    return (
        <div className="space-y-6">
            <div>
                <h1 className="text-[26px] font-bold tracking-tight text-text">Settings</h1>
                <p className="mt-1 text-[14px] text-text-muted">Event configuration — change dates, cutoffs, and toggles without a deployment.</p>
            </div>

            {isLoading && (
                <div className="space-y-3">
                    {Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-32 w-full" />)}
                </div>
            )}

            {isError && (
                <Card className="flex items-center justify-between px-5 py-6">
                    <span className="text-[13.5px] text-critical-fg">Failed to load settings.</span>
                    <Button variant="outline" size="sm" onClick={() => void refetch()}>Retry</Button>
                </Card>
            )}

            {data && Object.entries(data).map(([group, settings]) => (
                <Card key={group}>
                    <CardHeader title={titleCase(group)} />
                    <div className="mt-2">
                        {settings.map((s) => <SettingRow key={s.key} setting={s} />)}
                    </div>
                </Card>
            ))}

            {data && Object.keys(data).length === 0 && (
                <Card className="grid place-items-center px-6 py-16 text-center">
                    <p className="text-[13.5px] text-text-muted">No settings configured yet.</p>
                </Card>
            )}
        </div>
    );
}
