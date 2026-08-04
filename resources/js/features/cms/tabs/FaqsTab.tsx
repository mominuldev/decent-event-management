import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { Badge, Button, Card, CardHeader, Input, Label, Skeleton } from '@/components/ui';
import { ConfirmDialog, Dialog } from '@/components/Dialog';
import { useToast } from '@/components/Toast';
import { useAuth } from '@/features/auth/AuthProvider';
import * as cmsApi from '../api';
import { BilingualField, LocaleToggle, type EditLocale } from '../components/BilingualField';
import type { Faq } from '../types';

interface Draft {
    question: { en: string; bn: string };
    answer: { en: string; bn: string };
    category: { en: string; bn: string };
    position: string;
    isPublished: boolean;
}

const BLANK: Draft = {
    question: { en: '', bn: '' },
    answer: { en: '', bn: '' },
    category: { en: '', bn: '' },
    position: '0',
    isPublished: false,
};

function toDraft(faq: Faq): Draft {
    return {
        question: { en: faq.question, bn: faq.question_bn ?? '' },
        answer: { en: faq.answer, bn: faq.answer_bn ?? '' },
        category: { en: faq.category ?? '', bn: faq.category_bn ?? '' },
        position: String(faq.position),
        isPublished: faq.is_published,
    };
}

function FaqDialog({ faq, onClose }: { faq: Faq | null; onClose: () => void }) {
    const { push } = useToast();
    const queryClient = useQueryClient();
    const [locale, setLocale] = useState<EditLocale>('en');
    const [draft, setDraft] = useState<Draft>(faq ? toDraft(faq) : BLANK);

    const saveMutation = useMutation({
        mutationFn: () =>
            cmsApi.saveFaq(faq?.ulid ?? null, {
                question: draft.question.en,
                question_bn: draft.question.bn || null,
                answer: draft.answer.en,
                answer_bn: draft.answer.bn || null,
                category: draft.category.en || null,
                category_bn: draft.category.bn || null,
                position: Number(draft.position) || 0,
                is_published: draft.isPublished,
            }),
        onSuccess: () => {
            push('success', faq ? 'FAQ updated.' : 'FAQ added.');
            void queryClient.invalidateQueries({ queryKey: ['cms-faqs'] });
            onClose();
        },
        onError: (e: Error) => push('critical', e.message),
    });

    return (
        <Dialog open onClose={onClose} title={faq ? 'Edit FAQ' : 'Add FAQ'} className="max-w-lg">
            <div className="space-y-4">
                <div className="flex justify-end"><LocaleToggle locale={locale} onChange={setLocale} /></div>

                <BilingualField label="Question" locale={locale} value={draft.question} onChange={(question) => setDraft({ ...draft, question })} />
                <BilingualField label="Answer" locale={locale} multiline rows={4} value={draft.answer} onChange={(answer) => setDraft({ ...draft, answer })} />
                <BilingualField label="Category" locale={locale} value={draft.category} onChange={(category) => setDraft({ ...draft, category })} help="Groups questions on the public page. Leave blank for none." />

                <div className="w-32">
                    <Label htmlFor="faq-position">Position</Label>
                    <Input id="faq-position" type="number" min={0} value={draft.position} onChange={(e) => setDraft({ ...draft, position: e.target.value })} />
                </div>

                <label className="flex items-center gap-2 text-[13px] text-text">
                    <input type="checkbox" checked={draft.isPublished} onChange={(e) => setDraft({ ...draft, isPublished: e.target.checked })} />
                    Published
                </label>

                <div className="flex justify-end gap-2">
                    <Button variant="outline" size="sm" onClick={onClose}>Cancel</Button>
                    <Button
                        size="sm"
                        disabled={!draft.question.en.trim() || !draft.answer.en.trim() || saveMutation.isPending}
                        onClick={() => void saveMutation.mutateAsync()}
                    >
                        {saveMutation.isPending ? 'Saving…' : 'Save'}
                    </Button>
                </div>
            </div>
        </Dialog>
    );
}

export default function FaqsTab() {
    const { can } = useAuth();
    const { push } = useToast();
    const queryClient = useQueryClient();
    const [editing, setEditing] = useState<Faq | null | undefined>(undefined);
    const [deleting, setDeleting] = useState<Faq | null>(null);

    const { data, isLoading, isError } = useQuery({
        queryKey: ['cms-faqs'],
        queryFn: () => cmsApi.fetchFaqs(),
    });

    const deleteMutation = useMutation({
        mutationFn: (ulid: string) => cmsApi.deleteFaq(ulid),
        onSuccess: () => {
            push('success', 'FAQ deleted.');
            void queryClient.invalidateQueries({ queryKey: ['cms-faqs'] });
        },
        onError: (e: Error) => push('critical', e.message),
    });

    return (
        <Card>
            <CardHeader
                title="FAQs"
                subtitle="Grouped by category, then position."
                action={can('content.create') && <Button size="sm" onClick={() => setEditing(null)}><Plus size={15} /> Add FAQ</Button>}
            />

            <div className="px-5 pb-5 pt-3">
                {isLoading && <div className="space-y-2"><Skeleton className="h-14 w-full" /><Skeleton className="h-14 w-full" /></div>}
                {isError && <p className="py-6 text-[13px] text-critical-fg">Failed to load FAQs.</p>}
                {data && data.data.length === 0 && <p className="py-8 text-center text-[13px] text-text-muted">No questions yet.</p>}

                <div className="space-y-2">
                    {data?.data.map((faq) => (
                        <div key={faq.ulid} className="flex items-start gap-3 rounded-xl border border-border px-3.5 py-2.5">
                            <div className="min-w-0 flex-1">
                                <div className="text-[13.5px] font-medium text-text">{faq.question}</div>
                                <div className="mt-0.5 line-clamp-2 text-[12px] text-text-muted">{faq.answer}</div>
                            </div>
                            {faq.category && <Badge tone="neutral" size="sm">{faq.category}</Badge>}
                            <Badge tone={faq.is_published ? 'success' : 'neutral'} size="sm">
                                {faq.is_published ? 'Published' : 'Draft'}
                            </Badge>
                            {can('content.update') && (
                                <Button variant="ghost" size="sm" aria-label="Edit" onClick={() => setEditing(faq)}><Pencil size={14} /></Button>
                            )}
                            {can('content.delete') && (
                                <Button variant="ghost" size="sm" aria-label="Delete" onClick={() => setDeleting(faq)}><Trash2 size={14} /></Button>
                            )}
                        </div>
                    ))}
                </div>
            </div>

            {editing !== undefined && <FaqDialog faq={editing} onClose={() => setEditing(undefined)} />}

            <ConfirmDialog
                open={deleting !== null}
                onClose={() => setDeleting(null)}
                onConfirm={async () => { if (deleting) await deleteMutation.mutateAsync(deleting.ulid); }}
                title="Delete this FAQ?"
                description="It is removed from the public page immediately."
                confirmLabel="Delete"
            />
        </Card>
    );
}
