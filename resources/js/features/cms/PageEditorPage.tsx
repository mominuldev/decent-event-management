import { useEffect, useMemo, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, ExternalLink, History, Link2, Save, Trash2 } from 'lucide-react';
import { Badge, Button, Card, CardHeader, Input, Label, Select, Skeleton, type Tone } from '@/components/ui';
import { ConfirmDialog, Dialog } from '@/components/Dialog';
import { useToast } from '@/components/Toast';
import { useAuth } from '@/features/auth/AuthProvider';
import * as cmsApi from './api';
import { BlockEditor } from './components/BlockEditor';
import { BilingualField, LocaleToggle, type EditLocale } from './components/BilingualField';
import { MediaField } from './components/MediaPicker';
import {
    PAGE_TEMPLATES, PAGE_TRANSITIONS,
    type BlockDraft, type ContentPage, type MediaFile, type PageStatus,
} from './types';

const statusTone: Record<PageStatus, Tone> = {
    draft: 'neutral',
    in_review: 'warning',
    published: 'success',
    archived: 'neutral',
};

const statusLabel: Record<PageStatus, string> = {
    draft: 'Draft',
    in_review: 'In review',
    published: 'Published',
    archived: 'Archived',
};

interface FormState {
    slug: string;
    template: string;
    title: { en: string; bn: string };
    excerpt: { en: string; bn: string };
    seoTitle: { en: string; bn: string };
    seoDescription: { en: string; bn: string };
    isIndexable: boolean;
    ogImage: MediaFile | null;
    changeNote: string;
    blocks: BlockDraft[];
}

const BLANK: FormState = {
    slug: '',
    template: 'standard',
    title: { en: '', bn: '' },
    excerpt: { en: '', bn: '' },
    seoTitle: { en: '', bn: '' },
    seoDescription: { en: '', bn: '' },
    isIndexable: true,
    ogImage: null,
    changeNote: '',
    blocks: [],
};

function toFormState(page: ContentPage): FormState {
    return {
        slug: page.slug,
        template: page.template,
        title: { en: page.title, bn: page.title_bn ?? '' },
        excerpt: { en: page.excerpt ?? '', bn: page.excerpt_bn ?? '' },
        seoTitle: { en: page.seo_title ?? '', bn: page.seo_title_bn ?? '' },
        seoDescription: { en: page.seo_description ?? '', bn: page.seo_description_bn ?? '' },
        isIndexable: page.is_indexable,
        ogImage: page.og_image ?? null,
        changeNote: '',
        blocks: page.blocks.map((block) => ({
            ulid: block.ulid,
            type: block.type,
            data: block.data ?? {},
            data_bn: block.data_bn ?? {},
            media_ulid: block.media?.ulid ?? null,
            media: block.media ?? null,
            is_visible: block.is_visible,
            key: block.ulid,
        })),
    };
}

/** Blocks are only sent when the editor actually touched them — see `dirtyBlocks`. */
function toPayload(form: FormState, includeBlocks: boolean): Record<string, unknown> {
    const payload: Record<string, unknown> = {
        slug: form.slug,
        template: form.template,
        title: form.title.en,
        title_bn: form.title.bn || null,
        excerpt: form.excerpt.en || null,
        excerpt_bn: form.excerpt.bn || null,
        seo_title: form.seoTitle.en || null,
        seo_title_bn: form.seoTitle.bn || null,
        seo_description: form.seoDescription.en || null,
        seo_description_bn: form.seoDescription.bn || null,
        og_image_media_ulid: form.ogImage?.ulid ?? null,
        is_indexable: form.isIndexable,
        change_note: form.changeNote || null,
    };

    if (includeBlocks) {
        payload.blocks = form.blocks.map((block) => ({
            ulid: block.ulid ?? null,
            type: block.type,
            data: block.data,
            data_bn: block.data_bn,
            media_ulid: block.media_ulid,
            is_visible: block.is_visible,
        }));
    }

    return payload;
}

/* ------------------------------------------------------------ Revisions */

function RevisionsDialog({ page, onClose }: { page: ContentPage; onClose: () => void }) {
    const { can } = useAuth();
    const { push } = useToast();
    const queryClient = useQueryClient();
    const [confirming, setConfirming] = useState<string | null>(null);

    const { data, isLoading } = useQuery({
        queryKey: ['cms-revisions', page.ulid],
        queryFn: () => cmsApi.fetchRevisions(page.ulid),
    });

    const restoreMutation = useMutation({
        mutationFn: (revisionUlid: string) => cmsApi.restoreRevision(page.ulid, revisionUlid),
        onSuccess: () => {
            push('success', 'Page restored. A new revision was written on top of the history.');
            void queryClient.invalidateQueries({ queryKey: ['cms-page', page.ulid] });
            void queryClient.invalidateQueries({ queryKey: ['cms-revisions', page.ulid] });
            onClose();
        },
        onError: (e: Error) => push('critical', e.message),
    });

    return (
        <>
            <Dialog open onClose={onClose} title="Revision history" description="Every save is snapshotted. Restoring writes a new revision rather than rewinding the history." className="max-w-2xl">
                {isLoading && <Skeleton className="h-40 w-full" />}
                {data && data.data.length === 0 && <p className="text-[13px] text-text-muted">No revisions recorded yet.</p>}
                {data && data.data.length > 0 && (
                    <ul className="max-h-[50vh] space-y-2 overflow-y-auto">
                        {data.data.map((rev) => (
                            <li key={rev.ulid} className="flex items-start justify-between gap-3 rounded-xl border border-border px-3.5 py-2.5">
                                <div className="min-w-0">
                                    <div className="flex items-center gap-2">
                                        <span className="text-[13px] font-semibold text-text tnum">#{rev.revision_number}</span>
                                        <Badge tone={statusTone[rev.status_at_capture]} size="sm">{statusLabel[rev.status_at_capture]}</Badge>
                                    </div>
                                    <div className="mt-0.5 truncate text-[12.5px] text-text-muted">{rev.title}</div>
                                    {rev.change_note && <div className="mt-0.5 text-[12px] text-text-faint">{rev.change_note}</div>}
                                    <div className="mt-0.5 text-[11.5px] text-text-faint">
                                        {rev.created_at ? new Date(rev.created_at).toLocaleString() : '—'}
                                        {rev.created_by ? ` · ${rev.created_by}` : ''}
                                    </div>
                                </div>
                                {can('content.update') && rev.revision_number !== page.revision_number && (
                                    <Button variant="outline" size="sm" onClick={() => setConfirming(rev.ulid)}>Restore</Button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </Dialog>

            <ConfirmDialog
                open={confirming !== null}
                onClose={() => setConfirming(null)}
                onConfirm={async () => { if (confirming) await restoreMutation.mutateAsync(confirming); }}
                title="Restore this revision?"
                description="The page body and its blocks will be replaced with this snapshot. The page's published status is not changed, and the current version stays in the history."
                confirmLabel="Restore"
                tone="primary"
            />
        </>
    );
}

/* ---------------------------------------------------------------- Page */

export default function PageEditorPage() {
    const { ulid } = useParams<{ ulid: string }>();
    const isNew = ulid === 'new';
    const navigate = useNavigate();
    const { can } = useAuth();
    const { push } = useToast();
    const queryClient = useQueryClient();

    const [locale, setLocale] = useState<EditLocale>('en');
    const [form, setForm] = useState<FormState>(BLANK);
    const [blocksTouched, setBlocksTouched] = useState(false);
    const [showRevisions, setShowRevisions] = useState(false);
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [publishAt, setPublishAt] = useState('');
    const [previewUrl, setPreviewUrl] = useState<string | null>(null);

    const { data: page, isLoading, isError } = useQuery({
        queryKey: ['cms-page', ulid],
        queryFn: () => cmsApi.fetchPage(ulid as string),
        enabled: !isNew && Boolean(ulid),
    });

    useEffect(() => {
        if (page) {
            setForm(toFormState(page));
            setBlocksTouched(false);
        }
    }, [page]);

    const saveMutation = useMutation({
        mutationFn: () =>
            isNew
                ? cmsApi.createPage(toPayload(form, true))
                : cmsApi.updatePage(ulid as string, toPayload(form, blocksTouched)),
        onSuccess: (saved) => {
            push('success', isNew ? 'Page created as a draft.' : 'Page saved.');
            void queryClient.invalidateQueries({ queryKey: ['cms-pages'] });
            if (isNew) {
                navigate(`/cms/pages/${saved.ulid}`, { replace: true });
            } else {
                void queryClient.invalidateQueries({ queryKey: ['cms-page', ulid] });
            }
        },
        onError: (e: Error) => push('critical', e.message),
    });

    const statusMutation = useMutation({
        mutationFn: (next: PageStatus) =>
            cmsApi.changePageStatus(
                ulid as string,
                next,
                next === 'published' && publishAt ? new Date(publishAt).toISOString() : undefined,
            ),
        onSuccess: (updated) => {
            push('success', `Page moved to ${statusLabel[updated.status].toLowerCase()}.`);
            void queryClient.invalidateQueries({ queryKey: ['cms-page', ulid] });
            void queryClient.invalidateQueries({ queryKey: ['cms-pages'] });
        },
        onError: (e: Error) => push('critical', e.message),
    });

    const previewMutation = useMutation({
        mutationFn: () => cmsApi.issuePreviewToken(ulid as string),
        onSuccess: (link) => {
            setPreviewUrl(link.preview_url);
            push('success', 'Preview link created. Any link shared earlier has stopped working.');
            void queryClient.invalidateQueries({ queryKey: ['cms-page', ulid] });
        },
        onError: (e: Error) => push('critical', e.message),
    });

    const deleteMutation = useMutation({
        mutationFn: () => cmsApi.deletePage(ulid as string),
        onSuccess: () => {
            push('success', 'Page deleted.');
            void queryClient.invalidateQueries({ queryKey: ['cms-pages'] });
            navigate('/cms/pages');
        },
        onError: (e: Error) => push('critical', e.message),
    });

    const canEdit = can(isNew ? 'content.create' : 'content.update');
    const canSave = canEdit && form.slug.trim() !== '' && form.title.en.trim() !== '';

    const transitions = useMemo(
        () => (page ? PAGE_TRANSITIONS[page.status] : []),
        [page],
    );

    if (!isNew && isLoading) {
        return <div className="space-y-4"><Skeleton className="h-10 w-64" /><Skeleton className="h-64 w-full" /></div>;
    }

    if (!isNew && isError) {
        return <p className="text-[13px] text-critical-fg">That page could not be loaded.</p>;
    }

    return (
        <div className="space-y-6">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <button onClick={() => navigate('/cms/pages')} className="mb-1 inline-flex items-center gap-1 text-[12.5px] text-text-muted hover:text-text">
                        <ArrowLeft size={14} /> All pages
                    </button>
                    <h1 className="text-[26px] font-bold tracking-tight text-text">
                        {isNew ? 'New page' : form.title.en || page?.slug}
                    </h1>
                    {page && (
                        <div className="mt-1.5 flex flex-wrap items-center gap-2 text-[13px] text-text-muted">
                            <Badge tone={statusTone[page.status]} size="sm">{statusLabel[page.status]}</Badge>
                            {page.is_live && <Badge tone="success" size="sm">Live</Badge>}
                            <span>/{page.slug}</span>
                            <span className="text-text-faint">· revision {page.revision_number}</span>
                        </div>
                    )}
                </div>

                <div className="flex items-center gap-2">
                    <LocaleToggle locale={locale} onChange={setLocale} />
                    {canEdit && (
                        <Button onClick={() => void saveMutation.mutateAsync()} disabled={!canSave || saveMutation.isPending}>
                            <Save size={15} /> {saveMutation.isPending ? 'Saving…' : 'Save'}
                        </Button>
                    )}
                </div>
            </div>

            <div className="grid gap-6 lg:grid-cols-[1fr_320px]">
                <div className="space-y-6">
                    <Card>
                        <CardHeader title="Page details" subtitle="Slug and English title are required; Bangla can follow later." />
                        <div className="grid gap-4 px-5 pb-5 pt-4 sm:grid-cols-2">
                            <div>
                                <Label htmlFor="slug">Slug</Label>
                                <Input
                                    id="slug"
                                    value={form.slug}
                                    placeholder="about-the-centenary"
                                    onChange={(e) => setForm({ ...form, slug: e.target.value })}
                                />
                                <p className="mt-1 text-[11.5px] text-text-faint">Lowercase letters, numbers and single hyphens.</p>
                            </div>
                            <div>
                                <Label htmlFor="template">Template</Label>
                                <Select id="template" value={form.template} onChange={(e) => setForm({ ...form, template: e.target.value })}>
                                    {PAGE_TEMPLATES.map((t) => <option key={t} value={t}>{t}</option>)}
                                </Select>
                            </div>
                            <div className="sm:col-span-2">
                                <BilingualField
                                    id="title"
                                    label="Title"
                                    locale={locale}
                                    value={form.title}
                                    onChange={(title) => setForm({ ...form, title })}
                                />
                            </div>
                            <div className="sm:col-span-2">
                                <BilingualField
                                    id="excerpt"
                                    label="Excerpt"
                                    locale={locale}
                                    multiline
                                    value={form.excerpt}
                                    onChange={(excerpt) => setForm({ ...form, excerpt })}
                                    help="Used in link lists and as the fallback social description."
                                />
                            </div>
                        </div>
                    </Card>

                    <Card>
                        <CardHeader title="Content blocks" subtitle="The order here is the order on the public site." />
                        <div className="px-5 pb-5 pt-4">
                            <BlockEditor
                                blocks={form.blocks}
                                locale={locale}
                                onChange={(blocks) => { setForm({ ...form, blocks }); setBlocksTouched(true); }}
                            />
                        </div>
                    </Card>

                    <Card>
                        <CardHeader title="Search and social" />
                        <div className="space-y-4 px-5 pb-5 pt-4">
                            <BilingualField
                                label="SEO title"
                                locale={locale}
                                value={form.seoTitle}
                                onChange={(seoTitle) => setForm({ ...form, seoTitle })}
                                help="Falls back to the page title when blank."
                            />
                            <BilingualField
                                label="SEO description"
                                locale={locale}
                                multiline
                                rows={2}
                                value={form.seoDescription}
                                onChange={(seoDescription) => setForm({ ...form, seoDescription })}
                            />
                            <MediaField
                                label="Social preview image"
                                collection="page_og"
                                value={form.ogImage}
                                onChange={(ogImage) => setForm({ ...form, ogImage })}
                            />
                            <label className="flex items-center gap-2 text-[13px] text-text">
                                <input
                                    type="checkbox"
                                    checked={form.isIndexable}
                                    onChange={(e) => setForm({ ...form, isIndexable: e.target.checked })}
                                />
                                Allow search engines to index this page
                            </label>
                        </div>
                    </Card>
                </div>

                <div className="space-y-6">
                    {page && (
                        <Card>
                            <CardHeader title="Publishing" subtitle="Only the moves allowed from the current status are offered." />
                            <div className="space-y-3 px-5 pb-5 pt-4">
                                {transitions.includes('published') && (
                                    <div>
                                        <Label htmlFor="publish-at">Go live at</Label>
                                        <Input
                                            id="publish-at"
                                            type="datetime-local"
                                            value={publishAt}
                                            onChange={(e) => setPublishAt(e.target.value)}
                                        />
                                        <p className="mt-1 text-[11.5px] text-text-faint">
                                            Leave blank to publish immediately. A future time schedules it — the page stays hidden until then.
                                        </p>
                                    </div>
                                )}

                                <div className="flex flex-wrap gap-2">
                                    {transitions.map((next) => (
                                        <Button
                                            key={next}
                                            variant={next === 'published' ? 'primary' : 'outline'}
                                            size="sm"
                                            disabled={!can('content.publish') || statusMutation.isPending}
                                            onClick={() => void statusMutation.mutateAsync(next)}
                                        >
                                            Move to {statusLabel[next].toLowerCase()}
                                        </Button>
                                    ))}
                                </div>

                                {!can('content.publish') && (
                                    <p className="text-[12px] text-text-faint">You need the content.publish permission to change a page's status.</p>
                                )}

                                {page.published_at && (
                                    <p className="text-[12.5px] text-text-muted">
                                        Publish date: <span className="tnum">{new Date(page.published_at).toLocaleString()}</span>
                                    </p>
                                )}
                            </div>
                        </Card>
                    )}

                    {page && (
                        <Card>
                            <CardHeader title="Share a preview" subtitle="Lets a reviewer see an unpublished page." />
                            <div className="space-y-3 px-5 pb-5 pt-4">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={!can('content.update') || previewMutation.isPending}
                                    onClick={() => void previewMutation.mutateAsync()}
                                >
                                    <Link2 size={14} /> {page.has_preview_token ? 'Create a new link' : 'Create preview link'}
                                </Button>

                                {previewUrl && (
                                    <div className="rounded-xl border border-border bg-surface-2 px-3 py-2">
                                        <p className="mb-1 text-[11.5px] text-text-faint">Copy this now — it is shown only once.</p>
                                        <a href={previewUrl} target="_blank" rel="noreferrer" className="flex items-start gap-1.5 break-all text-[12px] text-accent hover:underline">
                                            <ExternalLink size={13} className="mt-0.5 shrink-0" /> {previewUrl}
                                        </a>
                                    </div>
                                )}

                                {page.has_preview_token && !previewUrl && (
                                    <p className="text-[12px] text-text-faint">
                                        A preview link already exists. Creating a new one invalidates it.
                                    </p>
                                )}
                            </div>
                        </Card>
                    )}

                    {page && (
                        <Card>
                            <CardHeader title="History" />
                            <div className="space-y-3 px-5 pb-5 pt-4">
                                <Button variant="outline" size="sm" onClick={() => setShowRevisions(true)}>
                                    <History size={14} /> View revisions
                                </Button>

                                <div>
                                    <Label htmlFor="change-note">Change note for the next save</Label>
                                    <Input
                                        id="change-note"
                                        value={form.changeNote}
                                        placeholder="Reworded the hero copy"
                                        onChange={(e) => setForm({ ...form, changeNote: e.target.value })}
                                    />
                                </div>

                                <dl className="space-y-1 text-[12.5px] text-text-muted">
                                    <div className="flex justify-between"><dt>Created by</dt><dd className="text-text">{page.created_by ?? '—'}</dd></div>
                                    <div className="flex justify-between"><dt>Last edited by</dt><dd className="text-text">{page.updated_by ?? '—'}</dd></div>
                                    <div className="flex justify-between"><dt>Published by</dt><dd className="text-text">{page.published_by ?? '—'}</dd></div>
                                </dl>
                            </div>
                        </Card>
                    )}

                    {page && can('content.delete') && (
                        <Card>
                            <CardHeader title="Danger zone" />
                            <div className="px-5 pb-5 pt-4">
                                <Button variant="danger" size="sm" onClick={() => setConfirmDelete(true)}>
                                    <Trash2 size={14} /> Delete page
                                </Button>
                            </div>
                        </Card>
                    )}
                </div>
            </div>

            {showRevisions && page && <RevisionsDialog page={page} onClose={() => setShowRevisions(false)} />}

            <ConfirmDialog
                open={confirmDelete}
                onClose={() => setConfirmDelete(false)}
                onConfirm={async () => { await deleteMutation.mutateAsync(); }}
                title="Delete this page?"
                description="It disappears from the public site immediately. The row is soft-deleted, so its revision history is kept for the audit trail."
                confirmLabel="Delete page"
            />
        </div>
    );
}
