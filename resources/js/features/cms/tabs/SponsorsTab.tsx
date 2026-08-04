import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { Badge, Button, Card, CardHeader, Input, Label, Select, Skeleton } from '@/components/ui';
import { ConfirmDialog, Dialog } from '@/components/Dialog';
import { useToast } from '@/components/Toast';
import { useAuth } from '@/features/auth/AuthProvider';
import * as cmsApi from '../api';
import { BilingualField, LocaleToggle, type EditLocale } from '../components/BilingualField';
import { MediaField, MediaThumb } from '../components/MediaPicker';
import { SPONSOR_TIERS, type MediaFile, type Sponsor, type SponsorTier } from '../types';

interface Draft {
    name: { en: string; bn: string };
    description: { en: string; bn: string };
    tier: SponsorTier;
    website_url: string;
    position: string;
    is_published: boolean;
    logo: MediaFile | null;
}

const BLANK: Draft = {
    name: { en: '', bn: '' },
    description: { en: '', bn: '' },
    tier: 'partner',
    website_url: '',
    position: '0',
    is_published: false,
    logo: null,
};

function toDraft(sponsor: Sponsor): Draft {
    return {
        name: { en: sponsor.name, bn: sponsor.name_bn ?? '' },
        description: { en: sponsor.description ?? '', bn: sponsor.description_bn ?? '' },
        tier: sponsor.tier,
        website_url: sponsor.website_url ?? '',
        position: String(sponsor.position),
        is_published: sponsor.is_published,
        logo: sponsor.logo ?? null,
    };
}

function SponsorDialog({ sponsor, onClose }: { sponsor: Sponsor | null; onClose: () => void }) {
    const { push } = useToast();
    const queryClient = useQueryClient();
    const [locale, setLocale] = useState<EditLocale>('en');
    const [draft, setDraft] = useState<Draft>(sponsor ? toDraft(sponsor) : BLANK);

    const saveMutation = useMutation({
        mutationFn: () =>
            cmsApi.saveSponsor(sponsor?.ulid ?? null, {
                name: draft.name.en,
                name_bn: draft.name.bn || null,
                description: draft.description.en || null,
                description_bn: draft.description.bn || null,
                tier: draft.tier,
                // An empty string would fail the `url` rule; null clears it.
                website_url: draft.website_url || null,
                position: Number(draft.position) || 0,
                is_published: draft.is_published,
                logo_media_ulid: draft.logo?.ulid ?? null,
            }),
        onSuccess: () => {
            push('success', sponsor ? 'Sponsor updated.' : 'Sponsor added.');
            void queryClient.invalidateQueries({ queryKey: ['cms-sponsors'] });
            onClose();
        },
        onError: (e: Error) => push('critical', e.message),
    });

    return (
        <Dialog open onClose={onClose} title={sponsor ? 'Edit sponsor' : 'Add sponsor'} className="max-w-lg">
            <div className="space-y-4">
                <div className="flex justify-end"><LocaleToggle locale={locale} onChange={setLocale} /></div>

                <BilingualField label="Name" locale={locale} value={draft.name} onChange={(name) => setDraft({ ...draft, name })} />

                <div className="grid grid-cols-2 gap-3">
                    <div>
                        <Label htmlFor="sponsor-tier">Tier</Label>
                        <Select id="sponsor-tier" value={draft.tier} onChange={(e) => setDraft({ ...draft, tier: e.target.value as SponsorTier })}>
                            {SPONSOR_TIERS.map((t) => <option key={t} value={t}>{t}</option>)}
                        </Select>
                    </div>
                    <div>
                        <Label htmlFor="sponsor-position">Position</Label>
                        <Input id="sponsor-position" type="number" min={0} value={draft.position} onChange={(e) => setDraft({ ...draft, position: e.target.value })} />
                    </div>
                </div>

                <div>
                    <Label htmlFor="sponsor-url">Website</Label>
                    <Input id="sponsor-url" value={draft.website_url} placeholder="https://example.com" onChange={(e) => setDraft({ ...draft, website_url: e.target.value })} />
                </div>

                <BilingualField
                    label="Description"
                    locale={locale}
                    multiline
                    rows={2}
                    value={draft.description}
                    onChange={(description) => setDraft({ ...draft, description })}
                />

                <MediaField label="Logo" collection="sponsor_logo" value={draft.logo} onChange={(logo) => setDraft({ ...draft, logo })} />

                <label className="flex items-center gap-2 text-[13px] text-text">
                    <input type="checkbox" checked={draft.is_published} onChange={(e) => setDraft({ ...draft, is_published: e.target.checked })} />
                    Published — visible on the public site
                </label>

                <div className="flex justify-end gap-2">
                    <Button variant="outline" size="sm" onClick={onClose}>Cancel</Button>
                    <Button size="sm" disabled={!draft.name.en.trim() || saveMutation.isPending} onClick={() => void saveMutation.mutateAsync()}>
                        {saveMutation.isPending ? 'Saving…' : 'Save'}
                    </Button>
                </div>
            </div>
        </Dialog>
    );
}

export default function SponsorsTab() {
    const { can } = useAuth();
    const { push } = useToast();
    const queryClient = useQueryClient();
    const [editing, setEditing] = useState<Sponsor | null | undefined>(undefined);
    const [deleting, setDeleting] = useState<Sponsor | null>(null);

    const { data, isLoading, isError } = useQuery({
        queryKey: ['cms-sponsors'],
        queryFn: () => cmsApi.fetchSponsors(),
    });

    const deleteMutation = useMutation({
        mutationFn: (ulid: string) => cmsApi.deleteSponsor(ulid),
        onSuccess: () => {
            push('success', 'Sponsor deleted.');
            void queryClient.invalidateQueries({ queryKey: ['cms-sponsors'] });
        },
        onError: (e: Error) => push('critical', e.message),
    });

    return (
        <Card>
            <CardHeader
                title="Sponsors"
                subtitle="Ordered by tier, then position — the same order the public grid renders in."
                action={can('content.create') && <Button size="sm" onClick={() => setEditing(null)}><Plus size={15} /> Add sponsor</Button>}
            />

            <div className="px-5 pb-5 pt-3">
                {isLoading && <div className="space-y-2"><Skeleton className="h-14 w-full" /><Skeleton className="h-14 w-full" /></div>}
                {isError && <p className="py-6 text-[13px] text-critical-fg">Failed to load sponsors.</p>}
                {data && data.data.length === 0 && <p className="py-8 text-center text-[13px] text-text-muted">No sponsors yet.</p>}

                <div className="space-y-2">
                    {data?.data.map((sponsor) => (
                        <div key={sponsor.ulid} className="flex items-center gap-3 rounded-xl border border-border px-3.5 py-2.5">
                            <MediaThumb media={sponsor.logo} className="h-10 w-10 shrink-0 rounded-lg" />
                            <div className="min-w-0 flex-1">
                                <div className="truncate text-[13.5px] font-medium text-text">{sponsor.name}</div>
                                <div className="truncate text-[12px] text-text-faint">{sponsor.name_bn ?? 'Not translated'}</div>
                            </div>
                            <Badge tone="neutral" size="sm">{sponsor.tier}</Badge>
                            <Badge tone={sponsor.is_published ? 'success' : 'neutral'} size="sm">
                                {sponsor.is_published ? 'Published' : 'Draft'}
                            </Badge>
                            {can('content.update') && (
                                <Button variant="ghost" size="sm" aria-label="Edit" onClick={() => setEditing(sponsor)}><Pencil size={14} /></Button>
                            )}
                            {can('content.delete') && (
                                <Button variant="ghost" size="sm" aria-label="Delete" onClick={() => setDeleting(sponsor)}><Trash2 size={14} /></Button>
                            )}
                        </div>
                    ))}
                </div>
            </div>

            {editing !== undefined && <SponsorDialog sponsor={editing} onClose={() => setEditing(undefined)} />}

            <ConfirmDialog
                open={deleting !== null}
                onClose={() => setDeleting(null)}
                onConfirm={async () => { if (deleting) await deleteMutation.mutateAsync(deleting.ulid); }}
                title="Delete this sponsor?"
                description="It is removed from the public sponsor grid immediately."
                confirmLabel="Delete"
            />
        </Card>
    );
}
