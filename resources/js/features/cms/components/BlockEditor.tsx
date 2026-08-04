import { useState } from 'react';
import { ChevronDown, ChevronUp, Eye, EyeOff, GripVertical, Plus, Trash2 } from 'lucide-react';
import { Badge, Button, Input, Label, Select } from '@/components/ui';
import { cn } from '@/lib/cn';
import { BLOCK_SCHEMAS, readList, readText, type ListEntry } from '../blocks';
import { BLOCK_TYPES, type BlockData, type BlockDraft, type BlockType, type MediaFile } from '../types';
import { BilingualField, type EditLocale } from './BilingualField';
import { MediaField } from './MediaPicker';

let draftSeq = 0;

/** Local key for a block that has no server ULID yet. */
export function newBlockKey(): string {
    return `draft-${++draftSeq}`;
}

export function emptyBlock(type: BlockType): BlockDraft {
    return { type, data: {}, data_bn: {}, media_ulid: null, media: null, is_visible: true, key: newBlockKey() };
}

function withField(block: BlockDraft, locale: EditLocale, key: string, value: unknown): BlockDraft {
    const target: BlockData = locale === 'bn' ? { ...block.data_bn } : { ...block.data };
    target[key] = value;
    return locale === 'bn' ? { ...block, data_bn: target } : { ...block, data: target };
}

/**
 * Repeatable value/label pairs — the one structured field shape in the block
 * schema (stat rows). Edits the active locale's array; the other language
 * keeps its own list, since a translated stat row may well word its labels
 * differently.
 */
function ListField({
    label,
    help,
    locale,
    entries,
    onChange,
}: {
    label: string;
    help?: string;
    locale: EditLocale;
    entries: ListEntry[];
    onChange: (next: ListEntry[]) => void;
}) {
    return (
        <div>
            <div className="flex items-baseline justify-between">
                <Label>{label}</Label>
                <span className={cn(
                    'mb-1.5 rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide',
                    locale === 'bn' ? 'bg-info-bg text-info-fg' : 'bg-surface-2 text-text-faint',
                )}>
                    {locale === 'bn' ? 'বাংলা' : 'English'}
                </span>
            </div>

            <div className="space-y-2">
                {entries.map((entry, i) => (
                    <div key={i} className="flex items-center gap-2">
                        <Input
                            value={entry.value}
                            placeholder="100"
                            className="w-28"
                            onChange={(e) => onChange(entries.map((row, j) => (j === i ? { ...row, value: e.target.value } : row)))}
                        />
                        <Input
                            value={entry.label}
                            placeholder="Years"
                            onChange={(e) => onChange(entries.map((row, j) => (j === i ? { ...row, label: e.target.value } : row)))}
                        />
                        <Button type="button" variant="ghost" size="sm" aria-label="Remove entry" onClick={() => onChange(entries.filter((_, j) => j !== i))}>
                            <Trash2 size={14} />
                        </Button>
                    </div>
                ))}
                <Button type="button" variant="outline" size="sm" onClick={() => onChange([...entries, { value: '', label: '' }])}>
                    <Plus size={14} /> Add entry
                </Button>
            </div>

            {help && <p className="mt-1 text-[11.5px] text-text-faint">{help}</p>}
        </div>
    );
}

function BlockCard({
    block,
    index,
    total,
    locale,
    onChange,
    onMove,
    onRemove,
}: {
    block: BlockDraft;
    index: number;
    total: number;
    locale: EditLocale;
    onChange: (next: BlockDraft) => void;
    onMove: (from: number, to: number) => void;
    onRemove: () => void;
}) {
    const [collapsed, setCollapsed] = useState(false);
    const schema = BLOCK_SCHEMAS[block.type];

    return (
        <div className={cn('rounded-2xl border border-border bg-surface', !block.is_visible && 'opacity-60')}>
            <div className="flex items-center gap-2 border-b border-border px-4 py-2.5">
                <GripVertical size={16} className="text-text-faint" />
                <span className="text-[13.5px] font-semibold text-text">{schema.label}</span>
                {!block.is_visible && <Badge tone="neutral" size="sm">Hidden</Badge>}

                <div className="ml-auto flex items-center gap-1">
                    <Button type="button" variant="ghost" size="sm" aria-label="Move up" disabled={index === 0} onClick={() => onMove(index, index - 1)}>
                        <ChevronUp size={15} />
                    </Button>
                    <Button type="button" variant="ghost" size="sm" aria-label="Move down" disabled={index === total - 1} onClick={() => onMove(index, index + 1)}>
                        <ChevronDown size={15} />
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        aria-label={block.is_visible ? 'Hide block' : 'Show block'}
                        onClick={() => onChange({ ...block, is_visible: !block.is_visible })}
                    >
                        {block.is_visible ? <Eye size={15} /> : <EyeOff size={15} />}
                    </Button>
                    <Button type="button" variant="ghost" size="sm" onClick={() => setCollapsed((c) => !c)}>
                        {collapsed ? 'Expand' : 'Collapse'}
                    </Button>
                    <Button type="button" variant="ghost" size="sm" aria-label="Delete block" onClick={onRemove}>
                        <Trash2 size={15} />
                    </Button>
                </div>
            </div>

            {!collapsed && (
                <div className="space-y-4 px-4 py-4">
                    <p className="text-[12.5px] text-text-muted">{schema.description}</p>

                    {schema.media !== 'none' && (
                        <MediaField
                            label={schema.media === 'required' ? 'Image' : 'Image (optional)'}
                            value={block.media ?? null}
                            onChange={(media: MediaFile | null) => onChange({ ...block, media, media_ulid: media?.ulid ?? null })}
                        />
                    )}

                    {schema.fields.map((field) =>
                        field.kind === 'list' ? (
                            <ListField
                                key={field.key}
                                label={field.label}
                                help={field.help}
                                locale={locale}
                                entries={readList(locale === 'bn' ? block.data_bn[field.key] : block.data[field.key])}
                                onChange={(next) => onChange(withField(block, locale, field.key, next))}
                            />
                        ) : (
                            <BilingualField
                                key={field.key}
                                label={field.label}
                                help={field.help}
                                locale={locale}
                                multiline={field.kind === 'textarea'}
                                value={{
                                    en: readText(block.data[field.key]),
                                    bn: readText(block.data_bn[field.key]),
                                }}
                                onChange={(next) =>
                                    onChange(withField(withField(block, 'en', field.key, next.en), 'bn', field.key, next.bn))
                                }
                            />
                        ),
                    )}
                </div>
            )}
        </div>
    );
}

/**
 * The page body editor. Order here is the order on the public site — the API
 * derives `position` from the array, so the client never sends one.
 */
export function BlockEditor({
    blocks,
    locale,
    onChange,
}: {
    blocks: BlockDraft[];
    locale: EditLocale;
    onChange: (next: BlockDraft[]) => void;
}) {
    const [adding, setAdding] = useState<BlockType>('rich_text');

    const move = (from: number, to: number) => {
        if (to < 0 || to >= blocks.length) return;
        const next = [...blocks];
        const [moved] = next.splice(from, 1);
        next.splice(to, 0, moved);
        onChange(next);
    };

    return (
        <div className="space-y-3">
            {blocks.length === 0 && (
                <p className="rounded-2xl border border-dashed border-border px-5 py-10 text-center text-[13px] text-text-muted">
                    This page has no content blocks yet. Add one below.
                </p>
            )}

            {blocks.map((block, i) => (
                <BlockCard
                    key={block.key}
                    block={block}
                    index={i}
                    total={blocks.length}
                    locale={locale}
                    onChange={(next) => onChange(blocks.map((b, j) => (j === i ? next : b)))}
                    onMove={move}
                    onRemove={() => onChange(blocks.filter((_, j) => j !== i))}
                />
            ))}

            <div className="flex items-end gap-2 rounded-2xl border border-dashed border-border p-4">
                <div className="w-56">
                    <Label htmlFor="add-block-type">Add a block</Label>
                    <Select id="add-block-type" value={adding} onChange={(e) => setAdding(e.target.value as BlockType)}>
                        {BLOCK_TYPES.map((type) => (
                            <option key={type} value={type}>{BLOCK_SCHEMAS[type].label}</option>
                        ))}
                    </Select>
                </div>
                <Button type="button" variant="outline" onClick={() => onChange([...blocks, emptyBlock(adding)])}>
                    <Plus size={15} /> Add
                </Button>
            </div>
        </div>
    );
}
