import { useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Expand, FileUp, ImageIcon, Pencil, Trash2, Upload, UploadCloud, X } from 'lucide-react';
import { Button, Card, CardHeader, EmptyState, Label, Select, Skeleton } from '@/components/ui';
import { ConfirmDialog, Dialog } from '@/components/Dialog';
import { useToast } from '@/components/Toast';
import { useAuth } from '@/features/auth/AuthProvider';
import { cn } from '@/lib/cn';
import { useFileDrop } from '@/lib/useFileDrop';
import { totalOf } from '@/lib/pagination';
import * as cmsApi from '../api';
import { MediaAltTextDialog } from '../components/MediaAltTextDialog';
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
    const [describing, setDescribing] = useState<MediaFile | null>(null);
    const [progress, setProgress] = useState<{ done: number; total: number } | null>(null);
    const [dropZoneOpen, setDropZoneOpen] = useState(false);

    const { data, isLoading, isError } = useQuery({
        queryKey: ['cms-media', filter, page],
        queryFn: () => cmsApi.fetchMedia(filter, page),
    });

    // One file at a time, on purpose: every upload is re-encoded server-side,
    // and firing twenty of those at once from one browser is how a shared host
    // hits its PHP worker ceiling. The library refreshes once at the end
    // rather than once per file, so the grid does not reflow under the reader.
    const uploadMutation = useMutation({
        mutationFn: async (files: File[]) => {
            const failed: string[] = [];
            let uploaded = 0;
            setProgress({ done: 0, total: files.length });
            for (const file of files) {
                try {
                    await cmsApi.uploadMedia(file, collection);
                    uploaded += 1;
                } catch (e) {
                    failed.push(`${file.name}: ${e instanceof Error ? e.message : 'upload failed'}`);
                }
                setProgress({ done: uploaded + failed.length, total: files.length });
            }
            return { uploaded, failed };
        },
        onSuccess: ({ uploaded, failed }) => {
            if (uploaded > 0) {
                push('success', uploaded === 1 ? 'Image uploaded.' : `${uploaded} images uploaded.`);
                void queryClient.invalidateQueries({ queryKey: ['cms-media'] });
                // Back to the grid, so the reader sees what just landed.
                setDropZoneOpen(false);
            }
            failed.forEach((message) => push('critical', message));
        },
        onError: (e: Error) => push('critical', e.message),
        onSettled: () => setProgress(null),
    });

    const canUpload = can('content.manage_media');
    const { isOver, dropProps } = useFileDrop({
        onFiles: (files) => uploadMutation.mutate(files),
        disabled: !canUpload || uploadMutation.isPending,
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
        <div className="relative" {...dropProps}>
        <Card>
            <CardHeader
                title="Media library"
                subtitle="JPEG, PNG, WebP and SVG up to 8 MB. Every upload is re-encoded server-side, which strips EXIF and GPS data."
            />

            <div className="flex flex-wrap items-end gap-3 px-5 pb-4 pt-3">
                <div className="w-48">
                    <Label htmlFor="media-filter">Show</Label>
                    <Select id="media-filter" value={filter} onChange={(e) => { setFilter(e.target.value); setPage(1); }}>
                        <option value="">All collections</option>
                        {MEDIA_COLLECTIONS.map((c) => <option key={c} value={c}>{COLLECTION_LABELS[c]}</option>)}
                    </Select>
                </div>

                {canUpload && (
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
                            multiple
                            accept="image/jpeg,image/png,image/webp,image/svg+xml"
                            className="hidden"
                            onChange={(e) => {
                                const files = Array.from(e.target.files ?? []);
                                if (files.length > 0) uploadMutation.mutate(files);
                                e.target.value = '';
                            }}
                        />
                        <Button
                            variant={dropZoneOpen ? 'outline' : 'primary'}
                            onClick={() => setDropZoneOpen((open) => !open)}
                            disabled={uploadMutation.isPending}
                            aria-expanded={dropZoneOpen}
                            aria-controls="media-drop-zone"
                        >
                            {dropZoneOpen ? <X size={15} /> : <Upload size={15} />}
                            {progress
                                ? `Uploading ${Math.min(progress.done + 1, progress.total)} of ${progress.total}…`
                                : dropZoneOpen ? 'Close' : 'Upload'}
                        </Button>
                    </>
                )}
            </div>

            {canUpload && (dropZoneOpen || isOver) && (
                <div
                    id="media-drop-zone"
                    className={cn(
                        'mx-5 mb-5 flex flex-col items-center justify-center rounded-2xl border-2 border-dashed px-6 py-14 text-center transition-colors',
                        isOver ? 'border-accent bg-accent/10' : 'border-accent/60 bg-surface-2',
                    )}
                >
                    <UploadCloud size={64} strokeWidth={1.5} className="text-accent" aria-hidden />
                    <p className="mt-6 text-[19px] font-semibold text-text">Drop files here</p>
                    <p className="mt-2 text-[13.5px] text-text-muted">or</p>
                    <Button
                        className="mt-4 px-6 uppercase tracking-[0.12em]"
                        onClick={() => fileInput.current?.click()}
                        disabled={uploadMutation.isPending}
                    >
                        <FileUp size={16} /> Choose files
                    </Button>
                    <p className="mt-6 text-[13px] text-text-muted">
                        Maximum file size: 8 MB · JPEG, PNG, WebP or SVG · several at once is fine
                    </p>
                </div>
            )}

            {/* The upload panel takes the library's place rather than sitting above it. */}
            {!dropZoneOpen && (
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
                                {canUpload && (
                                    <button
                                        type="button"
                                        aria-label="Edit alt text"
                                        onClick={() => setDescribing(media)}
                                        className="grid h-7 w-7 place-items-center rounded-lg bg-black/60 text-white backdrop-blur-sm transition-colors hover:bg-black/80"
                                    >
                                        <Pencil size={13} />
                                    </button>
                                )}
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
                                {media.alt_text || media.alt_text_bn ? (
                                    <div className="mt-0.5 truncate text-[10.5px] text-text-muted" title={media.alt_text ?? media.alt_text_bn ?? ''}>
                                        {media.alt_text ?? media.alt_text_bn}
                                    </div>
                                ) : (
                                    // Flagged rather than hidden: a missing description is the
                                    // accessibility gap most likely to ship unnoticed.
                                    <button
                                        type="button"
                                        onClick={() => setDescribing(media)}
                                        disabled={!canUpload}
                                        className="mt-0.5 text-[10.5px] font-medium text-warning-fg hover:underline disabled:no-underline"
                                    >
                                        No alt text
                                    </button>
                                )}
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
            )}

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
                        alt={previewing.alt_text ?? previewing.original_name ?? ''}
                        className="max-h-[70vh] w-full rounded-xl object-contain"
                    />
                    <p className="mt-3 text-[12.5px] text-text-muted">
                        <span className="font-semibold text-text">Alt text:</span>{' '}
                        {previewing.alt_text ?? previewing.alt_text_bn ?? <span className="text-warning-fg">none yet</span>}
                        {previewing.alt_text && previewing.alt_text_bn && <span className="text-text-faint"> · বাংলা: {previewing.alt_text_bn}</span>}
                    </p>
                </Dialog>
            )}

            {describing && <MediaAltTextDialog media={describing} onClose={() => setDescribing(null)} />}
        </Card>
        </div>
    );
}
