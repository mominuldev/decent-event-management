import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { Badge, Button, Card, CardHeader, Input, Label, Skeleton } from '@/components/ui';
import { ConfirmDialog, Dialog } from '@/components/Dialog';
import { useToast } from '@/components/Toast';
import { useAuth } from '@/features/auth/AuthProvider';
import * as cmsApi from '../api';
import { BilingualField, LocaleToggle, type EditLocale } from '../components/BilingualField';
import { MediaField } from '../components/MediaPicker';
import type { MediaFile, ScheduleItem } from '../types';

/** `datetime-local` wants `YYYY-MM-DDTHH:mm` in local time, not an ISO string. */
function toLocalInput(iso: string | null): string {
    if (!iso) return '';
    const d = new Date(iso);
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

interface Draft {
    title: { en: string; bn: string };
    description: { en: string; bn: string };
    speakerName: { en: string; bn: string };
    speakerTitle: { en: string; bn: string };
    venue: { en: string; bn: string };
    track: string;
    startsAt: string;
    endsAt: string;
    eventSessionCode: string;
    position: string;
    isPublished: boolean;
    photo: MediaFile | null;
}

const BLANK: Draft = {
    title: { en: '', bn: '' },
    description: { en: '', bn: '' },
    speakerName: { en: '', bn: '' },
    speakerTitle: { en: '', bn: '' },
    venue: { en: '', bn: '' },
    track: '',
    startsAt: '',
    endsAt: '',
    eventSessionCode: '',
    position: '0',
    isPublished: false,
    photo: null,
};

function toDraft(item: ScheduleItem): Draft {
    return {
        title: { en: item.title, bn: item.title_bn ?? '' },
        description: { en: item.description ?? '', bn: item.description_bn ?? '' },
        speakerName: { en: item.speaker_name ?? '', bn: item.speaker_name_bn ?? '' },
        speakerTitle: { en: item.speaker_title ?? '', bn: item.speaker_title_bn ?? '' },
        venue: { en: item.venue ?? '', bn: item.venue_bn ?? '' },
        track: item.track ?? '',
        startsAt: toLocalInput(item.starts_at),
        endsAt: toLocalInput(item.ends_at),
        eventSessionCode: item.event_session_code ?? '',
        position: String(item.position),
        isPublished: item.is_published,
        photo: item.speaker_photo ?? null,
    };
}

function ScheduleDialog({ item, onClose }: { item: ScheduleItem | null; onClose: () => void }) {
    const { push } = useToast();
    const queryClient = useQueryClient();
    const [locale, setLocale] = useState<EditLocale>('en');
    const [draft, setDraft] = useState<Draft>(item ? toDraft(item) : BLANK);

    const saveMutation = useMutation({
        mutationFn: () =>
            cmsApi.saveScheduleItem(item?.ulid ?? null, {
                title: draft.title.en,
                title_bn: draft.title.bn || null,
                description: draft.description.en || null,
                description_bn: draft.description.bn || null,
                speaker_name: draft.speakerName.en || null,
                speaker_name_bn: draft.speakerName.bn || null,
                speaker_title: draft.speakerTitle.en || null,
                speaker_title_bn: draft.speakerTitle.bn || null,
                venue: draft.venue.en || null,
                venue_bn: draft.venue.bn || null,
                track: draft.track || null,
                starts_at: draft.startsAt ? new Date(draft.startsAt).toISOString() : undefined,
                ends_at: draft.endsAt ? new Date(draft.endsAt).toISOString() : null,
                event_session_code: draft.eventSessionCode || null,
                position: Number(draft.position) || 0,
                is_published: draft.isPublished,
                speaker_photo_media_ulid: draft.photo?.ulid ?? null,
            }),
        onSuccess: () => {
            push('success', item ? 'Schedule item updated.' : 'Schedule item added.');
            void queryClient.invalidateQueries({ queryKey: ['cms-schedule'] });
            onClose();
        },
        onError: (e: Error) => push('critical', e.message),
    });

    return (
        <Dialog open onClose={onClose} title={item ? 'Edit schedule item' : 'Add schedule item'} className="max-w-lg">
            <div className="max-h-[70vh] space-y-4 overflow-y-auto pr-1">
                <div className="flex justify-end"><LocaleToggle locale={locale} onChange={setLocale} /></div>

                <BilingualField label="Title" locale={locale} value={draft.title} onChange={(title) => setDraft({ ...draft, title })} />

                <div className="grid grid-cols-2 gap-3">
                    <div>
                        <Label htmlFor="starts-at">Starts</Label>
                        <Input id="starts-at" type="datetime-local" value={draft.startsAt} onChange={(e) => setDraft({ ...draft, startsAt: e.target.value })} />
                    </div>
                    <div>
                        <Label htmlFor="ends-at">Ends</Label>
                        <Input id="ends-at" type="datetime-local" value={draft.endsAt} onChange={(e) => setDraft({ ...draft, endsAt: e.target.value })} />
                    </div>
                </div>

                <BilingualField label="Speaker" locale={locale} value={draft.speakerName} onChange={(speakerName) => setDraft({ ...draft, speakerName })} />
                <BilingualField label="Speaker title" locale={locale} value={draft.speakerTitle} onChange={(speakerTitle) => setDraft({ ...draft, speakerTitle })} />
                <BilingualField label="Venue" locale={locale} value={draft.venue} onChange={(venue) => setDraft({ ...draft, venue })} />
                <BilingualField label="Description" locale={locale} multiline rows={2} value={draft.description} onChange={(description) => setDraft({ ...draft, description })} />

                <div className="grid grid-cols-2 gap-3">
                    <div>
                        <Label htmlFor="track">Track</Label>
                        <Input id="track" value={draft.track} placeholder="main" onChange={(e) => setDraft({ ...draft, track: e.target.value })} />
                    </div>
                    <div>
                        <Label htmlFor="position">Position</Label>
                        <Input id="position" type="number" min={0} value={draft.position} onChange={(e) => setDraft({ ...draft, position: e.target.value })} />
                    </div>
                </div>

                <div>
                    <Label htmlFor="session-code">Event session code</Label>
                    <Input id="session-code" value={draft.eventSessionCode} placeholder="day-1-morning" onChange={(e) => setDraft({ ...draft, eventSessionCode: e.target.value })} />
                    <p className="mt-1 text-[11.5px] text-text-faint">
                        Optional, and deliberately loose — published copy survives a check-in session being renamed.
                    </p>
                </div>

                <MediaField label="Speaker photo" collection="speaker_photo" value={draft.photo} onChange={(photo) => setDraft({ ...draft, photo })} />

                <label className="flex items-center gap-2 text-[13px] text-text">
                    <input type="checkbox" checked={draft.isPublished} onChange={(e) => setDraft({ ...draft, isPublished: e.target.checked })} />
                    Published
                </label>

                <div className="flex justify-end gap-2">
                    <Button variant="outline" size="sm" onClick={onClose}>Cancel</Button>
                    <Button
                        size="sm"
                        disabled={!draft.title.en.trim() || !draft.startsAt || saveMutation.isPending}
                        onClick={() => void saveMutation.mutateAsync()}
                    >
                        {saveMutation.isPending ? 'Saving…' : 'Save'}
                    </Button>
                </div>
            </div>
        </Dialog>
    );
}

export default function ScheduleTab() {
    const { can } = useAuth();
    const { push } = useToast();
    const queryClient = useQueryClient();
    const [editing, setEditing] = useState<ScheduleItem | null | undefined>(undefined);
    const [deleting, setDeleting] = useState<ScheduleItem | null>(null);

    const { data, isLoading, isError } = useQuery({
        queryKey: ['cms-schedule'],
        queryFn: () => cmsApi.fetchScheduleItems(),
    });

    const deleteMutation = useMutation({
        mutationFn: (ulid: string) => cmsApi.deleteScheduleItem(ulid),
        onSuccess: () => {
            push('success', 'Schedule item deleted.');
            void queryClient.invalidateQueries({ queryKey: ['cms-schedule'] });
        },
        onError: (e: Error) => push('critical', e.message),
    });

    return (
        <Card>
            <CardHeader
                title="Schedule"
                subtitle="The published agenda, in chronological order."
                action={can('content.create') && <Button size="sm" onClick={() => setEditing(null)}><Plus size={15} /> Add item</Button>}
            />

            <div className="px-5 pb-5 pt-3">
                {isLoading && <div className="space-y-2"><Skeleton className="h-14 w-full" /><Skeleton className="h-14 w-full" /></div>}
                {isError && <p className="py-6 text-[13px] text-critical-fg">Failed to load the schedule.</p>}
                {data && data.data.length === 0 && <p className="py-8 text-center text-[13px] text-text-muted">Nothing scheduled yet.</p>}

                <div className="space-y-2">
                    {data?.data.map((item) => (
                        <div key={item.ulid} className="flex items-center gap-3 rounded-xl border border-border px-3.5 py-2.5">
                            <div className="w-36 shrink-0 text-[12px] text-text-muted tnum">
                                {new Date(item.starts_at).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })}
                            </div>
                            <div className="min-w-0 flex-1">
                                <div className="truncate text-[13.5px] font-medium text-text">{item.title}</div>
                                <div className="truncate text-[12px] text-text-faint">
                                    {[item.speaker_name, item.venue].filter(Boolean).join(' · ') || '—'}
                                </div>
                            </div>
                            {item.track && <Badge tone="neutral" size="sm">{item.track}</Badge>}
                            <Badge tone={item.is_published ? 'success' : 'neutral'} size="sm">
                                {item.is_published ? 'Published' : 'Draft'}
                            </Badge>
                            {can('content.update') && (
                                <Button variant="ghost" size="sm" aria-label="Edit" onClick={() => setEditing(item)}><Pencil size={14} /></Button>
                            )}
                            {can('content.delete') && (
                                <Button variant="ghost" size="sm" aria-label="Delete" onClick={() => setDeleting(item)}><Trash2 size={14} /></Button>
                            )}
                        </div>
                    ))}
                </div>
            </div>

            {editing !== undefined && <ScheduleDialog item={editing} onClose={() => setEditing(undefined)} />}

            <ConfirmDialog
                open={deleting !== null}
                onClose={() => setDeleting(null)}
                onConfirm={async () => { if (deleting) await deleteMutation.mutateAsync(deleting.ulid); }}
                title="Delete this schedule item?"
                description="It is removed from the published agenda immediately."
                confirmLabel="Delete"
            />
        </Card>
    );
}
