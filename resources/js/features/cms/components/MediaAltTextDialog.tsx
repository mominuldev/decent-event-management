import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Button } from '@/components/ui';
import { Dialog } from '@/components/Dialog';
import { useToast } from '@/components/Toast';
import * as cmsApi from '../api';
import type { MediaFile } from '../types';
import { BilingualField, LocaleToggle, type EditLocale } from './BilingualField';
import { MediaThumb } from './MediaPicker';

/**
 * Edits the one thing about an image that changes after upload: how it is
 * described to someone who cannot see it. The public API serves this as
 * `alt` on every embedded media object, localised with the usual English
 * fallback, so a renderer without a better description of its own (a
 * sponsor's name under its logo is better) has one.
 */
export function MediaAltTextDialog({ media, onClose }: { media: MediaFile; onClose: () => void }) {
    const { push } = useToast();
    const queryClient = useQueryClient();
    const [locale, setLocale] = useState<EditLocale>('en');
    const [value, setValue] = useState({ en: media.alt_text ?? '', bn: media.alt_text_bn ?? '' });

    const mutation = useMutation({
        mutationFn: () => cmsApi.updateMedia(media.ulid, {
            alt_text: value.en.trim() || null,
            alt_text_bn: value.bn.trim() || null,
        }),
        onSuccess: () => {
            push('success', 'Alt text saved.');
            void queryClient.invalidateQueries({ queryKey: ['cms-media'] });
            onClose();
        },
        onError: (e: Error) => push('critical', e.message),
    });

    return (
        <Dialog
            open
            onClose={onClose}
            title="Describe this image"
            description="Read aloud by screen readers and shown where the picture cannot load. Say what is in it, not that it is a picture."
            className="max-w-lg"
            footer={
                <div className="flex justify-end gap-2">
                    <Button variant="outline" onClick={onClose} disabled={mutation.isPending}>Cancel</Button>
                    <Button onClick={() => mutation.mutate()} disabled={mutation.isPending}>
                        {mutation.isPending ? 'Saving…' : 'Save'}
                    </Button>
                </div>
            }
        >
            <div className="space-y-4">
                <div className="flex items-center gap-3">
                    <MediaThumb media={media} className="h-20 w-20 shrink-0 rounded-xl border border-border" />
                    <div className="min-w-0 flex-1">
                        <div className="truncate text-[13px] font-medium text-text" title={media.original_name ?? ''}>
                            {media.original_name ?? media.ulid}
                        </div>
                        <div className="text-[11.5px] text-text-faint">
                            {media.width}×{media.height} · {Math.round(media.size_bytes / 1024)} KB
                        </div>
                    </div>
                    <LocaleToggle locale={locale} onChange={setLocale} />
                </div>

                <BilingualField
                    id="media-alt-text"
                    label="Alt text"
                    locale={locale}
                    value={value}
                    onChange={setValue}
                    multiline
                    rows={3}
                    placeholder={locale === 'bn' ? 'ছবিতে কী আছে, এক বাক্যে' : 'What the picture shows, in a sentence'}
                    help="Up to 255 characters. Leave blank for a purely decorative image."
                />
            </div>
        </Dialog>
    );
}
