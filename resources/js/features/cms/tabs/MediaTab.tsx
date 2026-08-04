import { useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Expand, ImageIcon, Trash2, Upload } from 'lucide-react';
import { Button, Card, CardHeader, EmptyState, Label, Select, Skeleton } from '@/components/ui';
import { ConfirmDialog, Dialog } from '@/components/Dialog';
import { useToast } from '@/components/Toast';
import { useAuth } from '@/features/auth/AuthProvider';
import { totalOf } from '@/lib/pagination';
import * as cmsApi from '../api';
import { MediaThumb } from '../components/MediaPicker';
import { MEDIA_COLLECTIONS, type MediaCollection, type MediaFile } from '../types';

const COLLECTION_LABELS: Record<MediaCollection, string> = {
    content: 'Page content',
    page_og: 'Social preview',
    sponsor_logo: 'Sponsor logos',
    speaker_photo: 'Speaker photos',
    gallery: 'Gallery',
};

export default function MediaTab() {
    const { can } = useAuth();
    const { push } = useToast();
    const queryClient = useQueryClient();
    const fileInput = useRef<HTMLInputElement>(null);
    const [collection, setCollection] = useState<MediaCollection>('content');
    const [filter, setFilter] = useState('');
    const [page, setPage] = useState(1);
    const [deleting, setDeleting] = useState<MediaFile | null>(null);
    const [previewing, setPreviewing] = useState<MediaFile | null>(null);

    const { data, isLoading, isError } = useQuery({
        queryKey: ['cms-media', filter, page],
        queryFn: () => cmsApi.fetchMedia(filter, page),
    });

    const uploadMutation = useMutation({
        mutationFn: (file: File) => cmsApi.uploadMedia(file, collection),
        onSuccess: () => {
            push('success', 'Image uploaded.');
            void queryClient.invalidateQueries({ queryKey: ['cms-media'] });
        },
        onError: (e: Error) => push('critical', e.message),
    });

    const deleteMutation = useMutation({
        mutationFn: (ulid: string) => cmsApi.deleteMedia(ulid),
        onSuccess: () => {
            push('success', 'Image removed from the library.');
            void queryClient.invalidateQueries({ queryKey: ['cms-media'] });
        },
        onError: (e: Error) => push('critical', e.message),
    });

    const total = data ? totalOf(data) : 0;
    const lastPage = Math.max(1, Math.ceil(total / 40));

    return (
        <Card>
            <CardHeader
                title="Media library"
                subtitle="JPEG, PNG and WebP up to 8 MB. Every upload is re-encoded server-side, which strips EXIF and GPS data."
            />

            <div className="flex flex-wrap items-end gap-3 px-5 pb-4 pt-3">
                <div className="w-48">
                    <Label htmlFor="media-filter">Show</Label>
                    <Select id="media-filter" value={filter} onChange={(e) => { setFilter(e.target.value); setPage(1); }}>
                        <option value="">All collections</option>
                        {MEDIA_COLLECTIONS.map((c) => <option key={c} value={c}>{COLLECTION_LABELS[c]}</option>)}
                    </Select>
                </div>

                {can('content.manage_media') && (
                    <>
                        <div className="w-48">
                            <Label htmlFor="upload-collection">Upload into</Label>
                            <Select id="upload-collection" value={collection} onChange={(e) => setCollection(e.target.value as MediaCollection)}>
                                {MEDIA_COLLECTIONS.map((c) => <option key={c} value={c}>{COLLECTION_LABELS[c]}</option>)}
                            </Select>
                        </div>
                        <input
                            ref={fileInput}
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            className="hidden"
                            onChange={(e) => {
                                const file = e.target.files?.[0];
                                if (file) uploadMutation.mutate(file);
                                e.target.value = '';
                            }}
                        />
                        <Button onClick={() => fileInput.current?.click()} disabled={uploadMutation.isPending}>
                            <Upload size={15} /> {uploadMutation.isPending ? 'Uploading…' : 'Upload'}
                        </Button>
                    </>
                )}
            </div>

            <div className="px-5 pb-5">
                {isLoading && (
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-6 xl:grid-cols-8">
                        {Array.from({ length: 16 }).map((_, i) => <Skeleton key={i} className="aspect-square w-full" />)}
                    </div>
                )}
                {isError && <p className="py-6 text-[13px] text-critical-fg">Failed to load the media library.</p>}
                {data && data.data.length === 0 && (
                    <EmptyState icon={<ImageIcon size={22} />} title="Nothing here yet" description="Uploads appear in every image picker across the CMS." />
                )}

                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-6 xl:grid-cols-8">
                    {data?.data.map((media) => (
                        <div key={media.ulid} className="group relative overflow-hidden rounded-xl border border-border">
                            <MediaThumb media={media} className="aspect-square w-full" />

                            <div className="absolute right-1.5 top-1.5 flex gap-1 opacity-0 transition-opacity focus-within:opacity-100 group-hover:opacity-100">
                                <button
                                    type="button"
                                    aria-label="View full size"
                                    onClick={() => setPreviewing(media)}
                                    className="grid h-7 w-7 place-items-center rounded-lg bg-black/60 text-white backdrop-blur-sm transition-colors hover:bg-black/80"
                                >
                                    <Expand size={13} />
                                </button>
                                {can('content.delete') && (
                                    <button
                                        type="button"
                                        aria-label="Delete image"
                                        onClick={() => setDeleting(media)}
                                        className="grid h-7 w-7 place-items-center rounded-lg bg-black/60 text-white backdrop-blur-sm transition-colors hover:bg-critical-fg"
                                    >
                                        <Trash2 size={13} />
                                    </button>
                                )}
                            </div>

                            <div className="px-2 py-1.5">
                                <div className="truncate text-[11.5px] font-medium text-text" title={media.original_name ?? ''}>
                                    {media.original_name ?? media.ulid}
                                </div>
                                <div className="text-[10.5px] text-text-faint">
                                    {media.width}×{media.height} · {Math.round(media.size_bytes / 1024)} KB
                                </div>
                            </div>
                        </div>
                    ))}
                </div>

                {total > 40 && (
                    <div className="mt-4 flex items-center justify-between text-[12.5px] text-text-muted">
                        <span>Page {page} of {lastPage} · {total} files</span>
                        <div className="flex gap-2">
                            <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>Previous</Button>
                            <Button variant="outline" size="sm" disabled={page >= lastPage} onClick={() => setPage((p) => p + 1)}>Next</Button>
                        </div>
                    </div>
                )}
            </div>

            <ConfirmDialog
                open={deleting !== null}
                onClose={() => setDeleting(null)}
                onConfirm={async () => { if (deleting) await deleteMutation.mutateAsync(deleting.ulid); }}
                title="Remove this image?"
                description="Pages and sponsors referencing it will show no image. The API refuses if it is still in a gallery album."
                confirmLabel="Remove"
            />

            {previewing && (
                <Dialog
                    open
                    onClose={() => setPreviewing(null)}
                    title={previewing.original_name ?? previewing.ulid}
                    description={`${previewing.width}×${previewing.height} · ${Math.round(previewing.size_bytes / 1024)} KB · ${COLLECTION_LABELS[previewing.collection as MediaCollection] ?? previewing.collection}`}
                    className="max-w-3xl"
                >
                    <img
                        src={previewing.url ?? ''}
                        alt={previewing.original_name ?? ''}
                        className="max-h-[70vh] w-full rounded-xl object-contain"
                    />
                </Dialog>
            )}
        </Card>
    );
}
