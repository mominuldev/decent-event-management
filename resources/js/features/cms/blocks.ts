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

export type FieldKind = 'text' | 'textarea' | 'url' | 'list' | 'repeater';

/**
 * The field kinds a repeater row may contain. `image` is a path or absolute
 * URL rather than a media-library reference: the homepage art is shipped with
 * the public site (`/images/home/...`) and seeded, so requiring an upload for
 * every guest portrait would make the seeder impossible to run on a fresh
 * database. The media picker writes its public URL into the same field.
 */
export type ItemFieldKind = 'text' | 'textarea' | 'url' | 'image';

export interface RepeaterItemField {
    key: string;
    label: string;
    kind: ItemFieldKind;
    /**
     * Whether the row keeps a separate Bangla value. Links, image paths and
     * design tokens (icon names, tone keys) are the same in both languages,
     * so they are written to `data` and `data_bn` identically — that keeps
     * the two arrays index-aligned no matter which locale is being edited.
     * Defaults to true for `text`/`textarea`, false for `url`/`image`.
     */
    translatable?: boolean;
    placeholder?: string;
}

export interface BlockField {
    key: string;
    label: string;
    kind: FieldKind;
    /** Hint shown under the input; keep it about *why*, not *what*. */
    help?: string;
    /** For `kind: 'repeater'` — the fields making up one row. */
    item?: RepeaterItemField[];
    /** For `kind: 'repeater'` — the noun on the "Add …" button. */
    itemLabel?: string;
}

/** Whether a repeater row field carries its own Bangla value. */
export function isTranslatableItemField(field: RepeaterItemField): boolean {
    return field.translatable ?? (field.kind === 'text' || field.kind === 'textarea');
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
            { key: 'eyebrow', label: 'Eyebrow', kind: 'text', help: 'Homepage layout only; the /faq page ignores it.' },
            { key: 'heading', label: 'Heading', kind: 'text' },
            { key: 'heading_accent', label: 'Heading accent', kind: 'text' },
            { key: 'category', label: 'Only this category', kind: 'text', help: 'Leave blank to show every category.' },
            {
                key: 'items',
                label: 'Questions (homepage grid)',
                kind: 'repeater',
                itemLabel: 'question',
                help: 'Only the homepage teaser grid reads these. Leave empty to fall back to the shipped six.',
                item: [
                    { key: 'question', label: 'Question', kind: 'text' },
                    { key: 'answer', label: 'Answer', kind: 'textarea' },
                    { key: 'icon', label: 'Icon', kind: 'text', placeholder: 'CalendarClock', translatable: false },
                    { key: 'tone', label: 'Tone', kind: 'text', placeholder: 'gold', translatable: false },
                ],
            },
        ],
    },
    sponsor_grid: {
        label: 'Sponsor grid',
        description: 'Renders published sponsors. Edit them on the Sponsors tab.',
        media: 'none',
        fields: [
            { key: 'heading', label: 'Heading', kind: 'text' },
            { key: 'heading_accent', label: 'Heading accent', kind: 'text' },
            { key: 'tier', label: 'Only this tier', kind: 'text', help: 'Leave blank to show every tier, in tier order.' },
            {
                key: 'logos',
                label: 'Logo cards (homepage row)',
                kind: 'repeater',
                itemLabel: 'logo',
                help: 'Only the homepage row reads these — it draws typographic cards, not the Sponsors tab records. Leave empty for the shipped six.',
                item: [
                    { key: 'mark', label: 'Wordmark', kind: 'text' },
                    { key: 'tagline', label: 'Tagline', kind: 'text' },
                    { key: 'text_color', label: 'Wordmark colour', kind: 'text', placeholder: '#1b3a93', translatable: false },
                    { key: 'dot_color', label: 'Dot colour', kind: 'text', placeholder: '#00a651', translatable: false },
                ],
            },
        ],
    },
    schedule: {
        label: 'Schedule',
        description: 'Renders published schedule items. Edit them on the Schedule tab.',
        media: 'none',
        fields: [
            { key: 'heading', label: 'Heading', kind: 'text' },
            { key: 'heading_accent', label: 'Heading accent', kind: 'text' },
            { key: 'track', label: 'Only this track', kind: 'text', help: 'Leave blank to show every track.' },
            { key: 'view_all_label', label: 'View-all label', kind: 'text' },
            { key: 'view_all_url', label: 'View-all link', kind: 'url' },
            {
                key: 'stops',
                label: 'Programme stops (homepage rail)',
                kind: 'repeater',
                itemLabel: 'stop',
                help: 'Only the homepage teaser rail reads these; /event still renders the Schedule tab records. Leave empty for the shipped seven.',
                item: [
                    { key: 'time', label: 'Time', kind: 'text', placeholder: '08:00 AM', translatable: false },
                    { key: 'title', label: 'Title', kind: 'text' },
                    { key: 'description', label: 'Description', kind: 'text' },
                    { key: 'icon', label: 'Icon', kind: 'text', placeholder: 'ClipboardList', translatable: false },
                    { key: 'tone', label: 'Tone', kind: 'text', placeholder: 'gold', translatable: false },
                ],
            },
        ],
    },
    gallery: {
        label: 'Gallery',
        description: 'Embeds a gallery album by its slug.',
        media: 'none',
        fields: [
            { key: 'eyebrow', label: 'Eyebrow', kind: 'text' },
            { key: 'heading', label: 'Heading', kind: 'text' },
            { key: 'album_slug', label: 'Album slug', kind: 'text' },
            { key: 'view_all_label', label: 'View-all label', kind: 'text' },
            { key: 'view_all_url', label: 'View-all link', kind: 'url' },
            {
                key: 'photos',
                label: 'Photo strip (homepage)',
                kind: 'repeater',
                itemLabel: 'photo',
                help: 'Only the homepage scrapbook strip reads these; its five slots — their size, rotation and overlap — are fixed by the design, so a row only sets the picture and its year pill and a sixth would have nowhere to sit. Leave empty for the shipped strip.',
                item: [
                    { key: 'year', label: 'Year pill', kind: 'text', placeholder: '১৯২৭' },
                    { key: 'image', label: 'Photo', kind: 'image' },
                ],
            },
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

    // ---------------------------------------------------------------------
    // Home-page sections.
    //
    // Every field below is optional in practice: the public site keeps the
    // designed copy as its fallback, so a half-filled block degrades to the
    // shipped default rather than rendering an empty section.
    // ---------------------------------------------------------------------

    home_hero: {
        label: 'Home hero',
        description: 'The centenary hero: year pill, three-line headline, two buttons and a countdown.',
        media: 'none',
        fields: [
            { key: 'year_pill', label: 'Year pill', kind: 'text', help: 'The dark pill left of the eyebrow, e.g. “১৯২৭ – ২০২৭”.' },
            { key: 'eyebrow', label: 'Eyebrow', kind: 'text' },
            { key: 'headline_lead', label: 'Headline line 1', kind: 'text' },
            { key: 'headline_accent', label: 'Headline line 2 (accent)', kind: 'text' },
            { key: 'headline_tail_1', label: 'Headline line 3, first word', kind: 'text', help: 'Drawn in maroon with the gold swash underneath.' },
            { key: 'headline_tail_2', label: 'Headline line 3, second word', kind: 'text', help: 'Drawn in deep gold.' },
            { key: 'body', label: 'Body', kind: 'textarea' },
            { key: 'primary_label', label: 'Primary button label', kind: 'text' },
            { key: 'primary_url', label: 'Primary button link', kind: 'url' },
            { key: 'secondary_label', label: 'Secondary button label', kind: 'text' },
            { key: 'secondary_url', label: 'Secondary button link', kind: 'url' },
            { key: 'countdown_target', label: 'Countdown target', kind: 'text', help: 'ISO 8601 with an offset, e.g. 2027-01-01T09:00:00+06:00. Blank hides the countdown.' },
            { key: 'image', label: 'Hero artwork', kind: 'url', help: 'Path on the public site, e.g. /images/home/hero/hero-composition.png' },
        ],
    },

    stat_bar: {
        label: 'Stat bar',
        description: 'The floating divided card of headline numbers under the hero.',
        media: 'none',
        fields: [
            {
                key: 'stats',
                label: 'Statistics',
                kind: 'repeater',
                itemLabel: 'statistic',
                help: 'Four reads best — the card divides evenly at four across.',
                item: [
                    { key: 'value', label: 'Value', kind: 'text', placeholder: '100+', translatable: false },
                    { key: 'label', label: 'Label', kind: 'text', placeholder: 'বছরের পথচলা' },
                    { key: 'icon', label: 'Icon', kind: 'text', placeholder: 'Users', translatable: false },
                    { key: 'tone', label: 'Tone', kind: 'text', placeholder: 'gold', translatable: false },
                ],
            },
        ],
    },

    history_teaser: {
        label: 'History teaser',
        description: 'Two overlapping archive photos beside the institutional narrative and its value chips.',
        media: 'none',
        fields: [
            { key: 'eyebrow', label: 'Eyebrow', kind: 'text' },
            { key: 'line1', label: 'Heading line 1', kind: 'text' },
            { key: 'line2_pre', label: 'Heading line 2, before the accent', kind: 'text' },
            { key: 'accent', label: 'Heading accent word', kind: 'text' },
            { key: 'line2_post', label: 'Heading line 2, after the accent', kind: 'text' },
            { key: 'body', label: 'Body', kind: 'textarea' },
            { key: 'badge', label: 'Photo badge', kind: 'text' },
            { key: 'image_primary', label: 'Back photo', kind: 'url' },
            { key: 'image_primary_alt', label: 'Back photo alt text', kind: 'text' },
            { key: 'image_secondary', label: 'Front photo', kind: 'url' },
            { key: 'image_secondary_alt', label: 'Front photo alt text', kind: 'text' },
            { key: 'link_label', label: 'Link label', kind: 'text' },
            { key: 'link_url', label: 'Link', kind: 'url' },
            {
                key: 'chips',
                label: 'Value chips',
                kind: 'repeater',
                itemLabel: 'chip',
                item: [
                    { key: 'label', label: 'Label', kind: 'text' },
                    { key: 'icon', label: 'Icon', kind: 'text', placeholder: 'GraduationCap', translatable: false },
                    { key: 'tone', label: 'Tone', kind: 'text', placeholder: 'gold', translatable: false },
                ],
            },
        ],
    },

    milestone_timeline: {
        label: 'Journey timeline',
        description: 'The colour-coded year rail. Founding dates are the spine of the centenary identity — change them only against the record.',
        media: 'none',
        fields: [
            { key: 'eyebrow', label: 'Eyebrow', kind: 'text' },
            { key: 'heading', label: 'Heading', kind: 'text' },
            {
                key: 'milestones',
                label: 'Milestones',
                kind: 'repeater',
                itemLabel: 'milestone',
                help: 'Four stops is what the rail is drawn for; more will scroll on mobile.',
                item: [
                    { key: 'year', label: 'Year', kind: 'text', placeholder: '1927', translatable: false },
                    { key: 'title', label: 'Title', kind: 'text' },
                    { key: 'body', label: 'Body', kind: 'textarea' },
                ],
            },
        ],
    },

    guest_carousel: {
        label: 'Guests of honour',
        description: 'The scrolling row of guest cards.',
        media: 'none',
        fields: [
            { key: 'eyebrow', label: 'Eyebrow', kind: 'text' },
            { key: 'heading_dark', label: 'Heading', kind: 'text' },
            { key: 'heading_accent', label: 'Heading accent', kind: 'text' },
            { key: 'view_all_label', label: 'View-all label', kind: 'text' },
            { key: 'view_all_url', label: 'View-all link', kind: 'url' },
            {
                key: 'guests',
                label: 'Guests',
                kind: 'repeater',
                itemLabel: 'guest',
                item: [
                    { key: 'name', label: 'Name', kind: 'text' },
                    { key: 'role', label: 'Role', kind: 'text' },
                    { key: 'org', label: 'Organisation', kind: 'text' },
                    { key: 'batch_year', label: 'Batch year', kind: 'text', placeholder: '1978', translatable: false },
                    { key: 'image', label: 'Photo', kind: 'image' },
                    { key: 'tone', label: 'Batch pill tone', kind: 'text', placeholder: 'danger', translatable: false },
                ],
            },
        ],
    },

    attraction_grid: {
        label: 'Attractions',
        description: 'The six-up grid of what the day holds.',
        media: 'none',
        fields: [
            { key: 'eyebrow', label: 'Eyebrow', kind: 'text' },
            { key: 'heading_dark', label: 'Heading', kind: 'text' },
            { key: 'heading_accent', label: 'Heading accent', kind: 'text' },
            {
                key: 'attractions',
                label: 'Attractions',
                kind: 'repeater',
                itemLabel: 'attraction',
                help: 'Six fills the desktop row exactly; other counts wrap.',
                item: [
                    { key: 'title', label: 'Title', kind: 'text' },
                    { key: 'body', label: 'Body', kind: 'textarea' },
                    { key: 'image', label: 'Photo', kind: 'image' },
                ],
            },
        ],
    },

    testimonial_carousel: {
        label: 'Testimonials',
        description: 'Alumni quotes, three to a page.',
        media: 'none',
        fields: [
            { key: 'eyebrow', label: 'Eyebrow', kind: 'text' },
            { key: 'heading_dark', label: 'Heading', kind: 'text' },
            { key: 'heading_accent', label: 'Heading accent', kind: 'text' },
            {
                key: 'testimonials',
                label: 'Testimonials',
                kind: 'repeater',
                itemLabel: 'testimonial',
                help: 'A multiple of three leaves no half-empty page at desktop width.',
                item: [
                    { key: 'quote', label: 'Quote', kind: 'textarea' },
                    { key: 'name', label: 'Name', kind: 'text' },
                    { key: 'title', label: 'Role and employer', kind: 'text' },
                    { key: 'batch_year', label: 'Batch year', kind: 'text', placeholder: '1996', translatable: false },
                    { key: 'image', label: 'Photo', kind: 'image' },
                ],
            },
        ],
    },

    pricing_teaser: {
        label: 'Pricing teaser',
        description: 'The two package cards. A teaser only — the live, priced grid on /tickets comes from the ticket types, not from here.',
        media: 'none',
        fields: [
            { key: 'eyebrow', label: 'Eyebrow', kind: 'text' },
            { key: 'heading_dark', label: 'Heading', kind: 'text' },
            { key: 'heading_accent', label: 'Heading accent', kind: 'text' },
            { key: 'cta_label', label: 'Card button label', kind: 'text' },
            { key: 'cta_url', label: 'Card button link', kind: 'url' },
            { key: 'popular_label', label: '“Most popular” badge', kind: 'text' },
            { key: 'footnote', label: 'Footnote', kind: 'textarea' },
            {
                key: 'plans',
                label: 'Packages',
                kind: 'repeater',
                itemLabel: 'package',
                help: 'Two is what the section is drawn for.',
                item: [
                    { key: 'title', label: 'Title', kind: 'text' },
                    { key: 'subtitle', label: 'Subtitle', kind: 'text' },
                    { key: 'price', label: 'Price', kind: 'text', placeholder: '৳ ২,০০০' },
                    { key: 'features', label: 'Features', kind: 'textarea', placeholder: 'One per line' },
                    { key: 'image', label: 'Illustration', kind: 'image' },
                    { key: 'tone', label: 'Tone', kind: 'text', placeholder: 'violet', translatable: false },
                    { key: 'popular', label: 'Most popular?', kind: 'text', placeholder: 'yes', translatable: false },
                ],
            },
        ],
    },

    cta_banner: {
        label: 'CTA banner',
        description: 'The gradient closing banner with two buttons.',
        media: 'none',
        fields: [
            { key: 'eyebrow', label: 'Eyebrow', kind: 'text' },
            { key: 'heading_line1', label: 'Heading line 1', kind: 'text' },
            { key: 'heading_accent', label: 'Heading accent', kind: 'text' },
            { key: 'heading_line2', label: 'Heading line 2', kind: 'text' },
            { key: 'body', label: 'Body', kind: 'textarea' },
            { key: 'primary_label', label: 'Primary button label', kind: 'text' },
            { key: 'primary_url', label: 'Primary button link', kind: 'url' },
            { key: 'secondary_label', label: 'Secondary button label', kind: 'text' },
            { key: 'secondary_url', label: 'Secondary button link', kind: 'url' },
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

/**
 * Reads a `repeater` field back out of stored JSON. Every row is flattened to
 * a string map so the editor never has to reason about a row half-written by
 * an older schema — a key the current schema does not know about is dropped
 * on read but preserved on disk until the row is next saved.
 */
export function readItems(raw: unknown): Record<string, string>[] {
    if (!Array.isArray(raw)) return [];

    return raw.map((entry) => {
        const row = (entry ?? {}) as Record<string, unknown>;
        const out: Record<string, string> = {};

        for (const [key, value] of Object.entries(row)) {
            if (typeof value === 'string') out[key] = value;
        }

        return out;
    });
}
