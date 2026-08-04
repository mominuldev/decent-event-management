import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Images, Pencil, Plus, Trash2 } from 'lucide-react';
import { Badge, Button, Card, CardHeader, EmptyState, Input, Label, Skeleton } from '@/components/ui';
import { ConfirmDialog, Dialog } from '@/components/Dialog';
import { useToast } from '@/components/Toast';
import { useAuth } from '@/features/auth/AuthProvider';
import { cn } from '@/lib/cn';
import * as cmsApi from '../api';
import { BilingualField, LocaleToggle, type EditLocale } from '../components/BilingualField';
import { MediaField, MediaPicker, MediaThumb } from '../components/MediaPicker';
import type { GalleryAlbum, GalleryItem, MediaFile } from '../types';

/* ---------------------------------------------------------------- Album */

interface AlbumDraft {
    slug: string;
    title: { en: string; bn: string };
    description: { en: string; bn: string };
    position: string;
    isPublished: boolean;
    cover: MediaFile | null;
}

const BLANK_ALBUM: AlbumDraft = {
    slug: '',
    title: { en: '', bn: '' },
    description: { en: '', bn: '' },
    position: '0',
    isPublished: false,
    cover: null,
};

function AlbumDialog({ album, onClose }: { album: GalleryAlbum | null; onClose: () => void }) {
    const { push } = useToast();
    const queryClient = useQueryClient();
    const [locale, setLocale] = useState<EditLocale>('en');
    const [draft, setDraft] = useState<AlbumDraft>(
        album
            ? {
                slug: album.slug,
                title: { en: album.title, bn: album.title_bn ?? '' },
                description: { en: album.description ?? '', bn: album.description_bn ?? '' },
                position: String(album.position),
                isPublished: album.is_published,
                cover: album.cover ?? null,
            }
            : BLANK_ALBUM,
    );

    const saveMutation = useMutation({
        mutationFn: () =>
            cmsApi.saveAlbum(album?.ulid ?? null, {
                slug: draft.slug,
                title: draft.title.en,
                title_bn: draft.title.bn || null,
                description: draft.description.en || null,
                description_bn: draft.description.bn || null,
                position: Number(draft.position) || 0,
                is_published: draft.isPublished,
                cover_media_ulid: draft.cover?.ulid ?? null,
            }),
        onSuccess: () => {
            push('success', album ? 'Album updated.' : 'Album created.');
            void queryClient.invalidateQueries({ queryKey: ['cms-albums'] });
            if (album) void queryClient.invalidateQueries({ queryKey: ['cms-album', album.ulid] });
            onClose();
        },
        onError: (e: Error) => push('critical', e.message),
    });

    return (
        <Dialog open onClose={onClose} title={album ? 'Edit album' : 'New album'} className="max-w-lg">
            <div className="space-y-4">
                <div className="flex justify-end"><LocaleToggle locale={locale} onChange={setLocale} /></div>

                <div>
                    <Label htmlFor="album-slug">Slug</Label>
                    <Input id="album-slug" value={draft.slug} placeholder="opening-ceremony" onChange={(e) => setDraft({ ...draft, slug: e.target.value })} />
                </div>

                <BilingualField label="Title" locale={locale} value={draft.title} onChange={(title) => setDraft({ ...draft, title })} />
                <BilingualField label="Description" locale={locale} multiline rows={2} value={draft.description} onChange={(description) => setDraft({ ...draft, description })} />

                <MediaField label="Cover image" collection="gallery" value={draft.cover} onChange={(cover) => setDraft({ ...draft, cover })} />

                <div className="w-32">
                    <Label htmlFor="album-position">Position</Label>
                    <Input id="album-position" type="number" min={0} value={draft.position} onChange={(e) => setDraft({ ...draft, position: e.target.value })} />
                </div>

                <label className="flex items-center gap-2 text-[13px] text-text">
                    <input type="checkbox" checked={draft.isPublished} onChange={(e) => setDraft({ ...draft, isPublished: e.target.checked })} />
                    Published
                </label>

                <div className="flex justify-end gap-2">
                    <Button variant="outline" size="sm" onClick={onClose}>Cancel</Button>
                    <Button size="sm" disabled={!draft.slug.trim() || !draft.title.en.trim() || saveMutation.isPending} onClick={() => void saveMutation.mutateAsync()}>
                        {saveMutation.isPending ? 'Saving…' : 'Save'}
                    </Button>
                </div>
            </div>
        </Dialog>
    );
}

/* ----------------------------------------------------------------- Item */

function ItemDialog({ albumUlid, item, onClose }: { albumUlid: string; item: GalleryItem; onClose: () => void }) {
    const { push } = useToast();
    const queryClient = useQueryClient();
    const [locale, setLocale] = useState<EditLocale>('en');
    const [caption, setCaption] = useState({ en: item.caption ?? '', bn: item.caption_bn ?? '' });
    const [altText, setAltText] = useState({ en: item.alt_text ?? '', bn: item.alt_text_bn ?? '' });
    const [isPublished, setIsPublished] = useState(item.is_published);

    const saveMutation = useMutation({
        mutationFn: () =>
            cmsApi.updateAlbumItem(albumUlid, item.ulid, {
                caption: caption.en || null,
                caption_bn: caption.bn || null,
                alt_text: altText.en || null,
                alt_text_bn: altText.bn || null,
                is_published: isPublished,
            }),
        onSuccess: () => {
            push('success', 'Picture updated.');
            void queryClient.invalidateQueries({ queryKey: ['cms-album', albumUlid] });
            onClose();
        },
        onError: (e: Error) => push('critical', e.message),
    });

    return (
        <Dialog open onClose={onClose} title="Edit picture" className="max-w-md">
            <div className="space-y-4">
                <div className="flex items-center justify-between">
                    <MediaThumb media={item.media} className="h-20 w-20 rounded-xl border border-border" />
                    <LocaleToggle locale={locale} onChange={setLocale} />
                </div>

                <BilingualField label="Caption" locale={locale} value={caption} onChange={setCaption} />
                <BilingualField label="Alt text" locale={locale} value={altText} onChange={setAltText} help="Describes the picture for screen readers." />

                <label className="flex items-center gap-2 text-[13px] text-text">
                    <input type="checkbox" checked={isPublished} onChange={(e) => setIsPublished(e.target.checked)} />
                    Published
                </label>

                <div className="flex justify-end gap-2">
                    <Button variant="outline" size="sm" onClick={onClose}>Cancel</Button>
                    <Button size="sm" disabled={saveMutation.isPending} onClick={() => void saveMutation.mutateAsync()}>
                        {saveMutation.isPending ? 'Saving…' : 'Save'}
                    </Button>
                </div>
            </div>
        </Dialog>
    );
}

function AlbumDetail({ albumUlid }: { albumUlid: string }) {
    const { can } = useAuth();
    const { push } = useToast();
    const queryClient = useQueryClient();
    const [adding, setAdding] = useState(false);
    const [editingItem, setEditingItem] = useState<GalleryItem | null>(null);
    const [deletingItem, setDeletingItem] = useState<GalleryItem | null>(null);

    const { data: album, isLoading } = useQuery({
        queryKey: ['cms-album', albumUlid],
        queryFn: () => cmsApi.fetchAlbum(albumUlid),
    });

    const addMutation = useMutation({
        mutationFn: (media: MediaFile) => cmsApi.addAlbumItem(albumUlid, { media_ulid: media.ulid, is_published: true }),
        onSuccess: () => {
            push('success', 'Picture added.');
            void queryClient.invalidateQueries({ queryKey: ['cms-album', albumUlid] });
            void queryClient.invalidateQueries({ queryKey: ['cms-albums'] });
        },
        onError: (e: Error) => push('critical', e.message),
    });

    const deleteMutation = useMutation({
        mutationFn: (itemUlid: string) => cmsApi.deleteAlbumItem(albumUlid, itemUlid),
        onSuccess: () => {
            push('success', 'Picture removed.');
            void queryClient.invalidateQueries({ queryKey: ['cms-album', albumUlid] });
            void queryClient.invalidateQueries({ queryKey: ['cms-albums'] });
        },
        onError: (e: Error) => push('critical', e.message),
    });

    if (isLoading) return <Skeleton className="h-48 w-full" />;
    if (!album) return null;

    return (
        <div>
            <div className="flex flex-wrap items-center justify-between gap-2 pb-3">
                <div>
                    <div className="text-[14px] font-semibold text-text">{album.title}</div>
                    <div className="text-[12px] text-text-faint">/{album.slug} · {album.items?.length ?? 0} pictures</div>
                </div>
                {can('content.create') && (
                    <Button size="sm" variant="outline" onClick={() => setAdding(true)}>
                        <Plus size={14} /> Add picture
                    </Button>
                )}
            </div>

            {(album.items?.length ?? 0) === 0 && (
                <EmptyState icon={<Images size={22} />} title="Empty album" description="Add pictures from the media library." />
            )}

            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                {album.items?.map((item) => (
                    <div key={item.ulid} className="group relative overflow-hidden rounded-xl border border-border">
                        <MediaThumb media={item.media} className="aspect-square w-full" />

                        <div className="absolute right-1.5 top-1.5 flex gap-1 opacity-0 transition-opacity focus-within:opacity-100 group-hover:opacity-100">
                            {can('content.update') && (
                                <button
                                    type="button"
                                    aria-label="Edit picture"
                                    onClick={() => setEditingItem(item)}
                                    className="grid h-7 w-7 place-items-center rounded-lg bg-black/60 text-white backdrop-blur-sm transition-colors hover:bg-black/80"
                                >
                                    <Pencil size={13} />
                                </button>
                            )}
                            {can('content.delete') && (
                                <button
                                    type="button"
                                    aria-label="Remove picture"
                                    onClick={() => setDeletingItem(item)}
                                    className="grid h-7 w-7 place-items-center rounded-lg bg-black/60 text-white backdrop-blur-sm transition-colors hover:bg-critical-fg"
                                >
                                    <Trash2 size={13} />
                                </button>
                            )}
                        </div>

                        <div className="px-2 py-1.5">
                            <div className="truncate text-[11.5px] text-text">{item.caption ?? '—'}</div>
                            {!item.is_published && <Badge tone="neutral" size="sm">Hidden</Badge>}
                        </div>
                    </div>
                ))}
            </div>

            <MediaPicker
                open={adding}
                onClose={() => setAdding(false)}
                onSelect={(media) => addMutation.mutate(media)}
                collection="gallery"
            />

            {editingItem && <ItemDialog albumUlid={albumUlid} item={editingItem} onClose={() => setEditingItem(null)} />}

            <ConfirmDialog
                open={deletingItem !== null}
                onClose={() => setDeletingItem(null)}
                onConfirm={async () => { if (deletingItem) await deleteMutation.mutateAsync(deletingItem.ulid); }}
                title="Remove this picture?"
                description="It leaves the album. The image itself stays in the media library."
                confirmLabel="Remove"
            />
        </div>
    );
}

/* ------------------------------------------------------------------ Tab */

export default function GalleryTab() {
    const { can } = useAuth();
    const { push } = useToast();
    const queryClient = useQueryClient();
    const [selected, setSelected] = useState<string | null>(null);
    const [editingAlbum, setEditingAlbum] = useState<GalleryAlbum | null | undefined>(undefined);
    const [deletingAlbum, setDeletingAlbum] = useState<GalleryAlbum | null>(null);

    const { data, isLoading, isError } = useQuery({
        queryKey: ['cms-albums'],
        queryFn: () => cmsApi.fetchAlbums(),
    });

    const deleteMutation = useMutation({
        mutationFn: (ulid: string) => cmsApi.deleteAlbum(ulid),
        onSuccess: (_result, ulid) => {
            push('success', 'Album deleted.');
            if (selected === ulid) setSelected(null);
            void queryClient.invalidateQueries({ queryKey: ['cms-albums'] });
        },
        onError: (e: Error) => push('critical', e.message),
    });

    return (
        <div className="grid gap-6 lg:grid-cols-[300px_1fr]">
            <Card>
                <CardHeader
                    title="Albums"
                    action={can('content.create') && <Button size="sm" onClick={() => setEditingAlbum(null)}><Plus size={15} /></Button>}
                />
                <div className="space-y-1.5 px-3 pb-4 pt-3">
                    {isLoading && <><Skeleton className="h-12 w-full" /><Skeleton className="h-12 w-full" /></>}
                    {isError && <p className="px-2 py-4 text-[13px] text-critical-fg">Failed to load albums.</p>}
                    {data && data.data.length === 0 && <p className="px-2 py-6 text-center text-[13px] text-text-muted">No albums yet.</p>}

                    {data?.data.map((album) => (
                        <div
                            key={album.ulid}
                            className={cn(
                                'flex items-center gap-2 rounded-xl px-2.5 py-2 transition-colors',
                                selected === album.ulid ? 'bg-surface-2' : 'hover:bg-surface-2',
                            )}
                        >
                            <button type="button" className="flex min-w-0 flex-1 items-center gap-2 text-left" onClick={() => setSelected(album.ulid)}>
                                <MediaThumb media={album.cover} className="h-9 w-9 shrink-0 rounded-lg" />
                                <div className="min-w-0">
                                    <div className="truncate text-[13px] font-medium text-text">{album.title}</div>
                                    <div className="text-[11px] text-text-faint">{album.items_count ?? 0} pictures</div>
                                </div>
                            </button>
                            <Badge tone={album.is_published ? 'success' : 'neutral'} size="sm">{album.is_published ? 'Live' : 'Draft'}</Badge>
                            {can('content.update') && (
                                <Button variant="ghost" size="sm" aria-label="Edit album" onClick={() => setEditingAlbum(album)}><Pencil size={13} /></Button>
                            )}
                            {can('content.delete') && (
                                <Button variant="ghost" size="sm" aria-label="Delete album" onClick={() => setDeletingAlbum(album)}><Trash2 size={13} /></Button>
                            )}
                        </div>
                    ))}
                </div>
            </Card>

            <Card>
                <div className="px-5 py-5">
                    {selected
                        ? <AlbumDetail albumUlid={selected} />
                        : <EmptyState icon={<Images size={22} />} title="Pick an album" description="Choose one on the left to manage its pictures." />}
                </div>
            </Card>

            {editingAlbum !== undefined && <AlbumDialog album={editingAlbum} onClose={() => setEditingAlbum(undefined)} />}

            <ConfirmDialog
                open={deletingAlbum !== null}
                onClose={() => setDeletingAlbum(null)}
                onConfirm={async () => { if (deletingAlbum) await deleteMutation.mutateAsync(deletingAlbum.ulid); }}
                title="Delete this album?"
                description="The album and its picture list are removed. The images themselves stay in the media library."
                confirmLabel="Delete album"
            />
        </div>
    );
}
