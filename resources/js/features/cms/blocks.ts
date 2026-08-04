import type { BlockType } from './types';

/**
 * What each block type is made of.
 *
 * The server keeps `content_blocks.data` as free JSON and only validates the
 * *type* against ContentBlock::TYPES — deliberately, so a copy change never
 * needs a migration. That makes this file the field contract in practice:
 * adding a block type means adding it here, in ContentBlock::TYPES, and in
 * the public site's renderer. It is a structured CMS, not a page builder —
 * editors fill known fields on known types.
 */

export type FieldKind = 'text' | 'textarea' | 'url' | 'list';

export interface BlockField {
    key: string;
    label: string;
    kind: FieldKind;
    /** Hint shown under the input; keep it about *why*, not *what*. */
    help?: string;
}

export interface BlockSchema {
    label: string;
    description: string;
    fields: BlockField[];
    /** Whether the block carries an image from the media library. */
    media: 'none' | 'optional' | 'required';
}

export const BLOCK_SCHEMAS: Record<BlockType, BlockSchema> = {
    rich_text: {
        label: 'Rich text',
        description: 'A heading and a body paragraph.',
        media: 'none',
        fields: [
            { key: 'heading', label: 'Heading', kind: 'text' },
            { key: 'body', label: 'Body', kind: 'textarea' },
        ],
    },
    hero: {
        label: 'Hero',
        description: 'Top-of-page banner with a call to action.',
        media: 'optional',
        fields: [
            { key: 'heading', label: 'Heading', kind: 'text' },
            { key: 'subheading', label: 'Subheading', kind: 'text' },
            { key: 'cta_label', label: 'Button label', kind: 'text' },
            { key: 'cta_url', label: 'Button link', kind: 'url', help: 'A path on the public site, e.g. /register' },
        ],
    },
    image: {
        label: 'Image',
        description: 'A single picture with a caption.',
        media: 'required',
        fields: [
            { key: 'caption', label: 'Caption', kind: 'text' },
            { key: 'alt_text', label: 'Alt text', kind: 'text', help: 'Describes the image for screen readers; not optional in practice.' },
        ],
    },
    cta: {
        label: 'Call to action',
        description: 'A prompt and a button, mid-page.',
        media: 'none',
        fields: [
            { key: 'heading', label: 'Heading', kind: 'text' },
            { key: 'body', label: 'Body', kind: 'textarea' },
            { key: 'cta_label', label: 'Button label', kind: 'text' },
            { key: 'cta_url', label: 'Button link', kind: 'url' },
        ],
    },
    stat_row: {
        label: 'Statistics',
        description: 'A row of headline numbers.',
        media: 'none',
        fields: [
            { key: 'heading', label: 'Heading', kind: 'text' },
            { key: 'stats', label: 'Statistics', kind: 'list', help: 'Each entry is a value and its label, e.g. “100” / “Years”.' },
        ],
    },
    faq_list: {
        label: 'FAQ list',
        description: 'Renders published FAQs. Edit the questions themselves on the FAQs tab.',
        media: 'none',
        fields: [
            { key: 'heading', label: 'Heading', kind: 'text' },
            { key: 'category', label: 'Only this category', kind: 'text', help: 'Leave blank to show every category.' },
        ],
    },
    sponsor_grid: {
        label: 'Sponsor grid',
        description: 'Renders published sponsors. Edit them on the Sponsors tab.',
        media: 'none',
        fields: [
            { key: 'heading', label: 'Heading', kind: 'text' },
            { key: 'tier', label: 'Only this tier', kind: 'text', help: 'Leave blank to show every tier, in tier order.' },
        ],
    },
    schedule: {
        label: 'Schedule',
        description: 'Renders published schedule items. Edit them on the Schedule tab.',
        media: 'none',
        fields: [
            { key: 'heading', label: 'Heading', kind: 'text' },
            { key: 'track', label: 'Only this track', kind: 'text', help: 'Leave blank to show every track.' },
        ],
    },
    gallery: {
        label: 'Gallery',
        description: 'Embeds a gallery album by its slug.',
        media: 'none',
        fields: [
            { key: 'heading', label: 'Heading', kind: 'text' },
            { key: 'album_slug', label: 'Album slug', kind: 'text' },
        ],
    },
    video: {
        label: 'Video',
        description: 'An embedded video with a caption.',
        media: 'none',
        fields: [
            { key: 'heading', label: 'Heading', kind: 'text' },
            { key: 'video_url', label: 'Video URL', kind: 'url' },
            { key: 'caption', label: 'Caption', kind: 'text' },
        ],
    },
};

/** A stat-row entry, the only structured (non-string) field value we store. */
export interface ListEntry {
    value: string;
    label: string;
}

/**
 * Reads a `list` field back out of stored JSON without trusting its shape —
 * the column is free JSON, and older rows may predate the current schema.
 */
export function readList(raw: unknown): ListEntry[] {
    if (!Array.isArray(raw)) return [];
    return raw.map((entry) => {
        const row = (entry ?? {}) as Record<string, unknown>;
        return {
            value: typeof row.value === 'string' ? row.value : '',
            label: typeof row.label === 'string' ? row.label : '',
        };
    });
}

/** Same defensiveness for plain string fields. */
export function readText(raw: unknown): string {
    return typeof raw === 'string' ? raw : '';
}
