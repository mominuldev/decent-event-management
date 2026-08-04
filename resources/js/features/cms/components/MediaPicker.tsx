import { useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ImageIcon, Upload, X } from 'lucide-react';
import { Button, EmptyState, Label, Skeleton } from '@/components/ui';
import { Dialog } from '@/components/Dialog';
import { useToast } from '@/components/Toast';
import { useAuth } from '@/features/auth/AuthProvider';
import { cn } from '@/lib/cn';
import * as cmsApi from '../api';
import type { MediaCollection, MediaFile } from '../types';

/**
 * Browse-or-upload picker over the CMS media library.
 *
 * Upload is a plain multipart POST with no client-side type sniffing: the
 * server decides what a file is from its magic bytes and re-encodes it, so
 * anything we checked here would be advisory at best and misleading at worst.
 */
export function MediaPicker({
    open,
    onClose,
    onSelect,
    collection = 'content',
}: {
    open: boolean;
    onClose: () => void;
    onSelect: (media: MediaFile) => void;
    collection?: MediaCollection;
}) {
    const { can } = useAuth();
    const { push } = useToast();
    const queryClient = useQueryClient();
    const fileInput = useRef<HTMLInputElement>(null);
    const [filter, setFilter] = useState<string>('');

    const { data, isLoading } = useQuery({
        queryKey: ['cms-media', filter],
        queryFn: () => cmsApi.fetchMedia(filter),
        enabled: open,
    });

    const uploadMutation = useMutation({
        mutationFn: (file: File) => cmsApi.uploadMedia(file, collection),
        onSuccess: (media) => {
            push('success', 'Image uploaded.');
            void queryClient.invalidateQueries({ queryKey: ['cms-media'] });
            onSelect(media);
            onClose();
        },
        onError: (e: Error) => push('critical', e.message),
    });

    const canUpload = can('content.manage_media');

    return (
        <Dialog open={open} onClose={onClose} title="Choose an image" className="max-w-3xl">
            <div className="space-y-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div className="w-52">
                        <Label htmlFor="media-collection">Collection</Label>
                        <select
                            id="media-collection"
                            value={filter}
                            onChange={(e) => setFilter(e.target.value)}
                            className="w-full rounded-xl border border-border bg-surface px-3 py-2 text-[13.5px] text-text outline-none focus:border-accent"
                        >
                            <option value="">All</option>
                            <option value="content">Page content</option>
                            <option value="page_og">Social preview</option>
                            <option value="sponsor_logo">Sponsor logos</option>
                            <option value="speaker_photo">Speaker photos</option>
                            <option value="gallery">Gallery</option>
                        </select>
                    </div>

                    {canUpload && (
                        <>
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
                            <Button size="sm" onClick={() => fileInput.current?.click()} disabled={uploadMutation.isPending}>
                                <Upload size={14} /> {uploadMutation.isPending ? 'Uploading…' : 'Upload'}
                            </Button>
                        </>
                    )}
                </div>

                <div className="max-h-[52vh] overflow-y-auto">
                    {isLoading && (
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            {Array.from({ length: 8 }).map((_, i) => <Skeleton key={i} className="aspect-square w-full" />)}
                        </div>
                    )}

                    {data && data.data.length === 0 && (
                        <EmptyState
                            icon={<ImageIcon size={22} />}
                            title="No images yet"
                            description={canUpload ? 'Upload a JPEG, PNG or WebP to get started.' : 'Ask someone with media permissions to upload one.'}
                        />
                    )}

                    {data && data.data.length > 0 && (
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            {data.data.map((media) => (
                                <button
                                    key={media.ulid}
                                    type="button"
                                    onClick={() => { onSelect(media); onClose(); }}
                                    className="group overflow-hidden rounded-xl border border-border text-left transition-colors hover:border-accent"
                                >
                                    <MediaThumb media={media} className="aspect-square w-full" />
                                    <div className="px-2 py-1.5">
                                        <div className="truncate text-[11.5px] font-medium text-text">{media.original_name ?? media.ulid}</div>
                                        <div className="text-[10.5px] text-text-faint">
                                            {media.width}×{media.height} · {Math.round(media.size_bytes / 1024)} KB
                                        </div>
                                    </div>
                                </button>
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </Dialog>
    );
}

export function MediaThumb({ media, className }: { media: MediaFile | null | undefined; className?: string }) {
    if (!media?.url) {
        return (
            <div className={cn('grid place-items-center bg-surface-2 text-text-faint', className)}>
                <ImageIcon size={20} />
            </div>
        );
    }
    return <img src={media.url} alt={media.original_name ?? ''} className={cn('object-cover', className)} loading="lazy" />;
}

/**
 * Form control for a single image reference — thumbnail, pick, clear.
 * `value` is the currently attached file (for the preview); the parent stores
 * the ULID, which is what the API accepts.
 */
export function MediaField({
    label,
    value,
    onChange,
    collection = 'content',
    help,
}: {
    label: string;
    value: MediaFile | null;
    onChange: (media: MediaFile | null) => void;
    collection?: MediaCollection;
    help?: string;
}) {
    const [picking, setPicking] = useState(false);

    return (
        <div>
            <Label>{label}</Label>
            <div className="flex items-center gap-3">
                <MediaThumb media={value} className="h-16 w-16 shrink-0 rounded-xl border border-border" />
                <div className="flex flex-wrap gap-2">
                    <Button type="button" variant="outline" size="sm" onClick={() => setPicking(true)}>
                        {value ? 'Replace' : 'Choose image'}
                    </Button>
                    {value && (
                        <Button type="button" variant="ghost" size="sm" onClick={() => onChange(null)}>
                            <X size={14} /> Remove
                        </Button>
                    )}
                </div>
            </div>
            {help && <p className="mt-1 text-[11.5px] text-text-faint">{help}</p>}

            <MediaPicker open={picking} onClose={() => setPicking(false)} onSelect={onChange} collection={collection} />
        </div>
    );
}
