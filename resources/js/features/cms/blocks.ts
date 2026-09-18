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

/**
 * `image` at block level is the same path-or-library control a repeater row
 * gets: untranslatable, written to both halves identically.
 */
export type FieldKind = 'text' | 'textarea' | 'url' | 'image' | 'list' | 'repeater';

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
            { key: 'image', label: 'Hero artwork', kind: 'image', help: 'Pick from the media library, or type a path the public site ships, e.g. /images/home/hero/hero-composition.png' },
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
            { key: 'image_primary', label: 'Back photo', kind: 'image', help: 'Pick from the media library, or type a path the public site ships, e.g. /images/home/history/archive.jpg' },
            { key: 'image_primary_alt', label: 'Back photo alt text', kind: 'text' },
            { key: 'image_secondary', label: 'Front photo', kind: 'image', help: 'Pick from the media library, or type a path the public site ships, e.g. /images/home/history/campus.jpg' },
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

    // ---------------------------------------------------------------------
    // History-page sections. Same contract as the homepage ones above: every
    // field is optional in practice, because the public site keeps the
    // designed copy as its fallback and a cleared field degrades to it.
    //
    // `cta_banner` above closes the History page too — the design binds
    // identical copy to that symbol on both pages, so it is one type, not two.
    // ---------------------------------------------------------------------

    history_hero: {
        label: 'History hero',
        description: 'The centered History banner: breadcrumb, year pill, two-tone title, subheading and intro.',
        media: 'none',
        fields: [
            { key: 'breadcrumb', label: 'Breadcrumb label', kind: 'text', help: 'The trailing crumb after “Home ›”.' },
            { key: 'year_pill', label: 'Year pill', kind: 'text', help: 'ASCII with an en-dash, e.g. “1927–2027” — the digits are localised for you. Blank uses the school’s founding-to-centenary range.' },
            { key: 'eyebrow', label: 'Eyebrow', kind: 'text' },
            { key: 'heading_lead', label: 'Heading', kind: 'text' },
            { key: 'heading_accent', label: 'Heading accent word', kind: 'text', help: 'Drawn in gold with the swash underneath.' },
            { key: 'subheading', label: 'Subheading', kind: 'text' },
            { key: 'body', label: 'Intro paragraph', kind: 'textarea' },
        ],
    },

    founding_story: {
        label: 'Founding story',
        description: 'Two mounted archive photos and the founding-date badge, beside the origin narrative and its chips.',
        media: 'none',
        fields: [
            { key: 'eyebrow', label: 'Eyebrow', kind: 'text' },
            { key: 'line1', label: 'Heading line 1', kind: 'text' },
            { key: 'line2_pre', label: 'Heading line 2, before the accent', kind: 'text' },
            { key: 'accent', label: 'Heading accent word', kind: 'text' },
            { key: 'line2_post', label: 'Heading line 2, after the accent', kind: 'text' },
            { key: 'body', label: 'Body', kind: 'textarea' },
            { key: 'badge', label: 'Photo badge label', kind: 'text' },
            { key: 'badge_year', label: 'Photo badge year', kind: 'text' },
            { key: 'image_primary', label: 'Back photo', kind: 'image', help: 'Pick from the media library, or type a path the public site ships, e.g. /images/home/history/archive.jpg' },
            { key: 'image_primary_alt', label: 'Back photo alt text', kind: 'text' },
            { key: 'image_secondary', label: 'Front photo', kind: 'image', help: 'Pick from the media library, or type a path the public site ships, e.g. /images/home/history/campus.jpg' },
            { key: 'image_secondary_alt', label: 'Front photo alt text', kind: 'text' },
            {
                key: 'chips',
                label: 'Value chips',
                kind: 'repeater',
                itemLabel: 'chip',
                help: 'Three fit the row before it wraps.',
                item: [
                    { key: 'label', label: 'Label', kind: 'text' },
                    { key: 'icon', label: 'Icon', kind: 'text', placeholder: 'CalendarDays', translatable: false },
                    { key: 'tone', label: 'Tone', kind: 'text', placeholder: 'gold', translatable: false },
                ],
            },
        ],
    },

    history_timeline: {
        label: 'Milestone timeline',
        description: 'The vertical zig-zag rail of dated milestones. Not the homepage’s horizontal “Journey timeline” — this one alternates cards either side of a connector.',
        media: 'none',
        fields: [
            { key: 'eyebrow', label: 'Eyebrow', kind: 'text' },
            { key: 'heading_dark', label: 'Heading', kind: 'text' },
            { key: 'heading_accent', label: 'Heading accent', kind: 'text' },
            {
                key: 'milestones',
                label: 'Milestones',
                kind: 'repeater',
                itemLabel: 'milestone',
                help: 'These dates are the institutional record — change them against it, not to fit the layout. Four is what the rail is drawn for; the connector re-colours itself for any number.',
                item: [
                    { key: 'year', label: 'Year', kind: 'text', placeholder: '1927', translatable: false },
                    { key: 'title', label: 'Title', kind: 'text' },
                    { key: 'description', label: 'Description', kind: 'textarea' },
                    { key: 'tone', label: 'Tone', kind: 'text', placeholder: 'gold', translatable: false },
                ],
            },
        ],
    },

    archive_gallery: {
        label: 'Archive gallery',
        description: 'The tilted then-and-now photo strip with year pills.',
        media: 'none',
        fields: [
            { key: 'eyebrow', label: 'Eyebrow', kind: 'text' },
            { key: 'heading_dark', label: 'Heading', kind: 'text' },
            { key: 'heading_accent', label: 'Heading accent', kind: 'text' },
            {
                key: 'photos',
                label: 'Photos',
                kind: 'repeater',
                itemLabel: 'photo',
                help: 'Five slots — size, tilt and overlap are the design, so a sixth photo has nowhere to sit and is dropped.',
                item: [
                    { key: 'image', label: 'Photo', kind: 'image' },
                    { key: 'year', label: 'Pill label', kind: 'text', placeholder: '1927' },
                ],
            },
        ],
    },

    numbers_bar: {
        label: 'By the numbers',
        description: 'The divided stat card under a heading. The homepage’s “Stat bar” is the same card without one.',
        media: 'none',
        fields: [
            { key: 'eyebrow', label: 'Eyebrow', kind: 'text' },
            { key: 'heading_dark', label: 'Heading', kind: 'text' },
            { key: 'heading_accent', label: 'Heading accent', kind: 'text' },
            {
                key: 'stats',
                label: 'Statistics',
                kind: 'repeater',
                itemLabel: 'statistic',
                help: 'Four reads best — the card divides evenly at four across.',
                item: [
                    { key: 'value', label: 'Value', kind: 'text', placeholder: '100', translatable: false },
                    { key: 'label', label: 'Label', kind: 'text', placeholder: 'বছরের পথচলা' },
                    { key: 'icon', label: 'Icon', kind: 'text', placeholder: 'CalendarDays', translatable: false },
                    { key: 'tone', label: 'Tone', kind: 'text', placeholder: 'gold', translatable: false },
                ],
            },
        ],
    },

    headmaster_message: {
        label: "Headmaster's message",
        description: 'The portrait-and-quote card that closes the page before the CTA.',
        media: 'none',
        fields: [
            { key: 'eyebrow', label: 'Eyebrow', kind: 'text' },
            { key: 'heading_dark', label: 'Heading', kind: 'text' },
            { key: 'heading_accent', label: 'Heading accent', kind: 'text' },
            { key: 'name', label: 'Name', kind: 'text' },
            { key: 'title', label: 'Title', kind: 'text', help: 'The role under the name, e.g. “Headmaster”.' },
            { key: 'quote', label: 'Message', kind: 'textarea' },
            { key: 'portrait', label: 'Portrait', kind: 'image', help: 'Pick from the media library, or type a path the public site ships, e.g. /images/history/headmaster-portrait.png. Square, or the circular crop will cut it off.' },
        ],
    },

    // ---------------------------------------------------------------------
    // Events-page sections. Same contract again: every field is optional in
    // practice, because the public site keeps the designed copy as its
    // fallback.
    //
    // The Attractions grid, Guests carousel and CTA banner on that page are
    // the homepage's own types above — the design uses one symbol for each
    // across both pages, so there is one block type for each, not two.
    // ---------------------------------------------------------------------

    event_hero: {
        label: 'Event hero',
        description: 'The Events banner: breadcrumb, year pill, two-tone title, intro and the date/venue/time fact cards.',
        media: 'none',
        fields: [
            { key: 'breadcrumb', label: 'Breadcrumb label', kind: 'text', help: 'The trailing crumb after “Home ›”.' },
            { key: 'year_pill', label: 'Year pill', kind: 'text', help: 'ASCII with an en-dash, e.g. “1927–2027” — the digits are localised for you.' },
            { key: 'eyebrow', label: 'Eyebrow', kind: 'text' },
            { key: 'heading_lead', label: 'Heading', kind: 'text' },
            { key: 'heading_accent', label: 'Heading accent word', kind: 'text', help: 'Drawn in violet with the swash underneath.' },
            { key: 'body', label: 'Intro paragraph', kind: 'textarea' },
            {
                key: 'facts',
                label: 'Key facts',
                kind: 'repeater',
                itemLabel: 'fact',
                help: 'Three fit the row as designed — date, venue, time.',
                item: [
                    { key: 'label', label: 'Label', kind: 'text', placeholder: 'Date' },
                    { key: 'value', label: 'Value', kind: 'text', placeholder: '13 March 2027, Saturday' },
                    { key: 'icon', label: 'Icon', kind: 'text', placeholder: 'Calendar', translatable: false },
                    { key: 'tone', label: 'Tone', kind: 'text', placeholder: 'violet', translatable: false },
                ],
            },
        ],
    },

    programme_glance: {
        label: 'Programme at a glance',
        description: 'The horizontal icon-disc rail summarising the day. The same rail the homepage draws, under this page’s own heading.',
        media: 'none',
        fields: [
            { key: 'eyebrow', label: 'Eyebrow', kind: 'text', help: 'The design leaves this blank here; fill it only if you want one.' },
            { key: 'heading_dark', label: 'Heading', kind: 'text' },
            { key: 'heading_accent', label: 'Heading accent', kind: 'text' },
            {
                key: 'stops',
                label: 'Stops',
                kind: 'repeater',
                itemLabel: 'stop',
                help: 'Keep these in step with the full schedule below — this rail is its summary, not a second programme.',
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

    full_schedule: {
        label: 'Full schedule',
        description: 'The vertical, card-per-session programme with times, tracks, venues and speakers.',
        media: 'none',
        fields: [
            { key: 'eyebrow', label: 'Eyebrow', kind: 'text' },
            { key: 'heading_dark', label: 'Heading', kind: 'text' },
            { key: 'heading_accent', label: 'Heading accent', kind: 'text' },
            {
                key: 'event_date',
                label: 'Event date',
                kind: 'text',
                help: 'YYYY-MM-DD, e.g. 2027-03-13. Sessions take this date unless a row overrides it; times are Bangladesh time.',
            },
            {
                key: 'items',
                label: 'Sessions',
                kind: 'repeater',
                itemLabel: 'session',
                help: 'A session with no start time is skipped rather than drawn with an unreadable time. Sessions are sorted and grouped by day for you.',
                item: [
                    { key: 'start_time', label: 'Start time', kind: 'text', placeholder: '08:00', translatable: false },
                    { key: 'end_time', label: 'End time', kind: 'text', placeholder: '09:30', translatable: false },
                    { key: 'date', label: 'Date override', kind: 'text', placeholder: '2027-03-13', translatable: false },
                    { key: 'title', label: 'Title', kind: 'text' },
                    { key: 'description', label: 'Description', kind: 'textarea' },
                    { key: 'track', label: 'Track', kind: 'text', placeholder: 'Registration' },
                    { key: 'venue', label: 'Venue', kind: 'text', placeholder: 'Main Gate' },
                    { key: 'tone', label: 'Tone', kind: 'text', placeholder: 'purple', translatable: false },
                    { key: 'speaker_name', label: 'Speaker', kind: 'text' },
                    { key: 'speaker_title', label: 'Speaker title', kind: 'text' },
                    { key: 'speaker_photo', label: 'Speaker photo', kind: 'image' },
                ],
            },
        ],
    },

    venue_directions: {
        label: 'Venue & directions',
        description: 'The venue card: map panel, address, arrival notes and the Google Maps link.',
        media: 'none',
        fields: [
            { key: 'eyebrow', label: 'Eyebrow', kind: 'text' },
            { key: 'heading_dark', label: 'Heading', kind: 'text' },
            { key: 'heading_accent', label: 'Heading accent', kind: 'text' },
            { key: 'map_label', label: 'Map panel label', kind: 'text', help: 'The design ships a placeholder panel, not a live embed — this is what it says.' },
            { key: 'venue_label', label: 'Venue eyebrow', kind: 'text' },
            { key: 'venue_name', label: 'Venue name', kind: 'text' },
            { key: 'venue_address', label: 'Address', kind: 'text' },
            { key: 'maps_label', label: 'Maps link label', kind: 'text' },
            { key: 'maps_url', label: 'Maps link', kind: 'url' },
            {
                key: 'notes',
                label: 'Arrival notes',
                kind: 'repeater',
                itemLabel: 'note',
                item: [
                    { key: 'label', label: 'Label', kind: 'text', placeholder: 'Parking' },
                    { key: 'body', label: 'Note', kind: 'text' },
                ],
            },
        ],
    },

    // ---------------------------------------------------------------------
    // Shared inner-page sections. The same contract as every bespoke section
    // above: every field is optional in practice, because the public site
    // keeps the designed copy as its fallback.
    // ---------------------------------------------------------------------

    page_hero: {
        label: 'Page hero',
        description: 'The plain purple inner-page banner: breadcrumb, year pill, eyebrow, two-tone title and intro. Used by FAQ, Sponsors and Alumni.',
        media: 'none',
        fields: [
            { key: 'breadcrumb', label: 'Breadcrumb label', kind: 'text', help: 'The trailing crumb after “Home ›”.' },
            { key: 'year_pill', label: 'Year pill', kind: 'text', help: 'ASCII with an en-dash, e.g. “1927–2027” — the digits are localised for you.' },
            { key: 'eyebrow', label: 'Eyebrow', kind: 'text' },
            { key: 'heading_lead', label: 'Heading', kind: 'text' },
            { key: 'heading_accent', label: 'Heading accent word', kind: 'text', help: 'Drawn in gold after the heading.' },
            { key: 'body', label: 'Intro paragraph', kind: 'textarea' },
            { key: 'subheading', label: 'Subheading', kind: 'text', help: 'A second display line under the title; the design leaves it blank on most pages.' },
        ],
    },

    faq_contact_cta: {
        label: 'FAQ contact card',
        description: 'The “didn’t find your answer?” card that closes the FAQ page and points at the contact desks.',
        media: 'none',
        fields: [
            { key: 'heading', label: 'Heading', kind: 'text' },
            { key: 'body', label: 'Body', kind: 'text' },
            { key: 'cta_label', label: 'Button label', kind: 'text' },
            { key: 'cta_url', label: 'Button link', kind: 'url' },
        ],
    },

    // ---------------------------------------------------------------------
    // Gallery-page sections.
    // ---------------------------------------------------------------------

    gallery_hero: {
        label: 'Gallery hero',
        description: 'The Gallery banner with its three fact cards (archived photos, videos, oldest photo).',
        media: 'none',
        fields: [
            { key: 'breadcrumb', label: 'Breadcrumb label', kind: 'text', help: 'The trailing crumb after “Home ›”.' },
            { key: 'year_pill', label: 'Year pill', kind: 'text', help: 'ASCII with an en-dash, e.g. “1927–2027” — the digits are localised for you.' },
            { key: 'eyebrow', label: 'Eyebrow', kind: 'text' },
            { key: 'heading_lead', label: 'Heading', kind: 'text' },
            { key: 'heading_accent', label: 'Heading accent word', kind: 'text', help: 'Drawn in gold after the heading.' },
            { key: 'body', label: 'Intro paragraph', kind: 'textarea' },
            {
                key: 'facts',
                label: 'Key facts',
                kind: 'repeater',
                itemLabel: 'fact',
                help: 'Three fit the row as designed.',
                item: [
                    { key: 'label', label: 'Label', kind: 'text' },
                    { key: 'value', label: 'Value', kind: 'text' },
                    { key: 'icon', label: 'Icon', kind: 'text', placeholder: 'Camera', translatable: false },
                ],
            },
        ],
    },

    album_filters: {
        label: 'Album filters',
        description: 'The row of filter pills above the photo grid. Counts are display copy, not live figures.',
        media: 'none',
        fields: [
            {
                key: 'filters',
                label: 'Filters',
                kind: 'repeater',
                itemLabel: 'filter',
                item: [
                    { key: 'label', label: 'Label', kind: 'text' },
                    { key: 'count', label: 'Count', kind: 'text', placeholder: '1240', translatable: false },
                ],
            },
        ],
    },

    photo_grid: {
        label: 'Photo grid',
        description: 'The archive grid: a heading and a four-column grid of captioned photos.',
        media: 'none',
        fields: [
            { key: 'eyebrow', label: 'Eyebrow', kind: 'text' },
            { key: 'heading_dark', label: 'Heading', kind: 'text' },
            { key: 'heading_accent', label: 'Heading accent', kind: 'text' },
            { key: 'more_label', label: '“View more” label', kind: 'text' },
            {
                key: 'photos',
                label: 'Photos',
                kind: 'repeater',
                itemLabel: 'photo',
                item: [
                    { key: 'image', label: 'Image', kind: 'image' },
                    { key: 'title', label: 'Title', kind: 'text' },
                    { key: 'meta', label: 'Caption line', kind: 'text', placeholder: '1931 · Founding Era' },
                ],
            },
        ],
    },

    album_collection: {
        label: 'Album collection',
        description: 'The three-column album cards with a year-span pill and photo count.',
        media: 'none',
        fields: [
            { key: 'eyebrow', label: 'Eyebrow', kind: 'text' },
            { key: 'heading_dark', label: 'Heading', kind: 'text' },
            { key: 'heading_accent', label: 'Heading accent', kind: 'text' },
            {
                key: 'albums',
                label: 'Albums',
                kind: 'repeater',
                itemLabel: 'album',
                item: [
                    { key: 'image', label: 'Cover image', kind: 'image' },
                    { key: 'span', label: 'Year span', kind: 'text', placeholder: '1927–1960' },
                    { key: 'title', label: 'Title', kind: 'text' },
                    { key: 'count', label: 'Photo count', kind: 'text', placeholder: '86 photos' },
                ],
            },
        ],
    },

    video_gallery: {
        label: 'Video gallery',
        description: 'The video cards with a play button and duration badge.',
        media: 'none',
        fields: [
            { key: 'eyebrow', label: 'Eyebrow', kind: 'text' },
            { key: 'heading_dark', label: 'Heading', kind: 'text' },
            { key: 'heading_accent', label: 'Heading accent', kind: 'text' },
            {
                key: 'videos',
                label: 'Videos',
                kind: 'repeater',
                itemLabel: 'video',
                item: [
                    { key: 'image', label: 'Thumbnail', kind: 'image' },
                    { key: 'title', label: 'Title', kind: 'text' },
                    { key: 'meta', label: 'Caption line', kind: 'text', placeholder: 'Documentary · 2026' },
                    { key: 'duration', label: 'Duration', kind: 'text', placeholder: '12:40', translatable: false },
                    { key: 'url', label: 'Video link', kind: 'url' },
                ],
            },
        ],
    },

    contribute: {
        label: 'Contribute',
        description: 'The “add your photos” card: an upload panel on the left and the how-to on the right.',
        media: 'none',
        fields: [
            { key: 'eyebrow', label: 'Eyebrow', kind: 'text' },
            { key: 'heading_dark', label: 'Heading', kind: 'text' },
            { key: 'heading_accent', label: 'Heading accent', kind: 'text' },
            { key: 'panel_title', label: 'Panel title', kind: 'text' },
            { key: 'panel_note', label: 'Panel note', kind: 'text', help: 'File types and size limit.' },
            { key: 'kicker', label: 'Kicker', kind: 'text' },
            { key: 'heading', label: 'Card heading', kind: 'text' },
            { key: 'body', label: 'Card body', kind: 'textarea' },
            {
                key: 'steps',
                label: 'Steps',
                kind: 'repeater',
                itemLabel: 'step',
                item: [
                    { key: 'title', label: 'Title', kind: 'text' },
                    { key: 'body', label: 'Body', kind: 'text' },
                ],
            },
            { key: 'cta_label', label: 'Button label', kind: 'text' },
            { key: 'cta_url', label: 'Button link', kind: 'url', help: 'A mailto: address or a page.' },
        ],
    },

    // ---------------------------------------------------------------------
    // Souvenir-page sections.
    // ---------------------------------------------------------------------

    souvenir_hero: {
        label: 'Souvenir hero',
        description: 'The Souvenir banner with its three fact cards (pages, compiled writings, publish date).',
        media: 'none',
        fields: [
            { key: 'breadcrumb', label: 'Breadcrumb label', kind: 'text', help: 'The trailing crumb after “Home ›”.' },
            { key: 'year_pill', label: 'Year pill', kind: 'text', help: 'ASCII with an en-dash, e.g. “1927–2027” — the digits are localised for you.' },
            { key: 'eyebrow', label: 'Eyebrow', kind: 'text' },
            { key: 'heading_lead', label: 'Heading', kind: 'text' },
            { key: 'heading_accent', label: 'Heading accent word', kind: 'text', help: 'Drawn in gold after the heading.' },
            { key: 'body', label: 'Intro paragraph', kind: 'textarea' },
            {
                key: 'facts',
                label: 'Key facts',
                kind: 'repeater',
                itemLabel: 'fact',
                help: 'Three fit the row as designed.',
                item: [
                    { key: 'label', label: 'Label', kind: 'text' },
                    { key: 'value', label: 'Value', kind: 'text' },
                    { key: 'icon', label: 'Icon', kind: 'text', placeholder: 'Camera', translatable: false },
                ],
            },
        ],
    },

    book_preview: {
        label: 'Book preview',
        description: 'The 3D book mockup beside the book’s description, spec grid and two buttons.',
        media: 'none',
        fields: [
            { key: 'cover_school', label: 'Cover: school line', kind: 'text' },
            { key: 'cover_kicker', label: 'Cover: small title', kind: 'text' },
            { key: 'cover_title', label: 'Cover: main title', kind: 'text' },
            { key: 'cover_years', label: 'Cover: years', kind: 'text' },
            { key: 'cover_footer', label: 'Cover: footer line', kind: 'text' },
            { key: 'eyebrow', label: 'Eyebrow', kind: 'text' },
            { key: 'heading_dark', label: 'Heading', kind: 'text' },
            { key: 'heading_accent', label: 'Heading accent', kind: 'text' },
            { key: 'body', label: 'Body', kind: 'textarea' },
            {
                key: 'specs',
                label: 'Specifications',
                kind: 'repeater',
                itemLabel: 'spec',
                item: [
                    { key: 'label', label: 'Label', kind: 'text' },
                    { key: 'value', label: 'Value', kind: 'text' },
                ],
            },
            { key: 'primary_label', label: 'Primary button', kind: 'text' },
            { key: 'primary_url', label: 'Primary link', kind: 'url' },
            { key: 'secondary_label', label: 'Secondary button', kind: 'text' },
            { key: 'secondary_url', label: 'Secondary link', kind: 'url' },
        ],
    },

    book_contents: {
        label: 'Book contents',
        description: 'The table of contents: numbered chapter cards with an icon and page count.',
        media: 'none',
        fields: [
            { key: 'eyebrow', label: 'Eyebrow', kind: 'text' },
            { key: 'heading_dark', label: 'Heading', kind: 'text' },
            { key: 'heading_accent', label: 'Heading accent', kind: 'text' },
            {
                key: 'chapters',
                label: 'Chapters',
                kind: 'repeater',
                itemLabel: 'chapter',
                item: [
                    { key: 'number', label: 'Number', kind: 'text', placeholder: '01' },
                    { key: 'icon', label: 'Icon', kind: 'text', placeholder: 'ScrollText', translatable: false },
                    { key: 'title', label: 'Title', kind: 'text' },
                    { key: 'body', label: 'Body', kind: 'text' },
                    { key: 'pages', label: 'Page count', kind: 'text', placeholder: '42 pages' },
                ],
            },
        ],
    },

    call_for_writing: {
        label: 'Call for writing',
        description: 'The submission deadline banner and the four writing categories.',
        media: 'none',
        fields: [
            { key: 'eyebrow', label: 'Eyebrow', kind: 'text' },
            { key: 'heading_dark', label: 'Heading', kind: 'text' },
            { key: 'heading_accent', label: 'Heading accent', kind: 'text' },
            { key: 'deadline_title', label: 'Deadline line', kind: 'text' },
            { key: 'deadline_body', label: 'Deadline note', kind: 'textarea' },
            { key: 'deadline_badge', label: 'Deadline badge', kind: 'text', help: 'Copy, not a countdown — e.g. “47 days left”. Blank hides it.' },
            {
                key: 'categories',
                label: 'Categories',
                kind: 'repeater',
                itemLabel: 'category',
                item: [
                    { key: 'icon', label: 'Icon', kind: 'text', placeholder: 'Feather', translatable: false },
                    { key: 'title', label: 'Title', kind: 'text' },
                    { key: 'body', label: 'Body', kind: 'text' },
                    { key: 'limit', label: 'Length limit', kind: 'text', placeholder: '800–1200 words' },
                ],
            },
        ],
    },

    editorial_board: {
        label: 'Editorial board',
        description: 'The row of portrait cards for the souvenir book’s editors.',
        media: 'none',
        fields: [
            { key: 'eyebrow', label: 'Eyebrow', kind: 'text' },
            { key: 'heading_dark', label: 'Heading', kind: 'text' },
            { key: 'heading_accent', label: 'Heading accent', kind: 'text' },
            {
                key: 'members',
                label: 'Members',
                kind: 'repeater',
                itemLabel: 'member',
                item: [
                    { key: 'image', label: 'Portrait', kind: 'image' },
                    { key: 'name', label: 'Name', kind: 'text' },
                    { key: 'role', label: 'Role', kind: 'text' },
                ],
            },
        ],
    },

    get_a_copy: {
        label: 'Get a copy',
        description: 'The three ways to get the book, as pricing-style cards.',
        media: 'none',
        fields: [
            { key: 'eyebrow', label: 'Eyebrow', kind: 'text' },
            { key: 'heading_dark', label: 'Heading', kind: 'text' },
            { key: 'heading_accent', label: 'Heading accent', kind: 'text' },
            { key: 'popular_label', label: '“Most popular” badge', kind: 'text' },
            { key: 'footnote', label: 'Footnote', kind: 'text' },
            {
                key: 'options',
                label: 'Options',
                kind: 'repeater',
                itemLabel: 'option',
                item: [
                    { key: 'title', label: 'Title', kind: 'text' },
                    { key: 'subtitle', label: 'Subtitle', kind: 'text' },
                    { key: 'price', label: 'Price', kind: 'text', placeholder: '৳ 800' },
                    { key: 'features', label: 'Features', kind: 'textarea', placeholder: 'One per line' },
                    { key: 'cta_label', label: 'Button label', kind: 'text' },
                    { key: 'cta_url', label: 'Button link', kind: 'url' },
                    { key: 'highlighted', label: 'Highlighted', kind: 'text', placeholder: 'yes', translatable: false },
                ],
            },
        ],
    },

    // ---------------------------------------------------------------------
    // Contact-page sections. Venue & directions and the FAQ grid are the
    // shared `venue_directions` and `faq_list` types above.
    // ---------------------------------------------------------------------

    contact_hero: {
        label: 'Contact hero',
        description: 'The Contact banner and the four channel cards that overlap its foot (helpline, WhatsApp, email, office).',
        media: 'none',
        fields: [
            { key: 'breadcrumb', label: 'Breadcrumb label', kind: 'text', help: 'The trailing crumb after “Home ›”.' },
            { key: 'year_pill', label: 'Year pill', kind: 'text', help: 'ASCII with an en-dash, e.g. “1927–2027” — the digits are localised for you.' },
            { key: 'eyebrow', label: 'Eyebrow', kind: 'text' },
            { key: 'heading_lead', label: 'Heading', kind: 'text' },
            { key: 'heading_accent', label: 'Heading accent word', kind: 'text', help: 'Drawn in gold after the heading.' },
            { key: 'body', label: 'Intro paragraph', kind: 'textarea' },
            {
                key: 'channels',
                label: 'Channels',
                kind: 'repeater',
                itemLabel: 'channel',
                help: 'Leave a value blank to fall back to the event settings (contact.phone, contact.email, contact.address).',
                item: [
                    { key: 'icon', label: 'Icon', kind: 'text', placeholder: 'Phone', translatable: false },
                    { key: 'tone', label: 'Tone', kind: 'text', placeholder: 'gold', translatable: false },
                    { key: 'title', label: 'Title', kind: 'text' },
                    { key: 'description', label: 'Description', kind: 'text' },
                    { key: 'value', label: 'Value', kind: 'text', placeholder: '+880 1234-567890', translatable: false },
                    { key: 'action_label', label: 'Button label', kind: 'text' },
                    { key: 'action_url', label: 'Button link', kind: 'url', placeholder: 'tel:… / mailto:… / https://…' },
                ],
            },
        ],
    },

    committee_desks: {
        label: 'Committee desks',
        description: 'The four department desks and the secretariat hours banner beneath them.',
        media: 'none',
        fields: [
            { key: 'badge', label: 'Badge', kind: 'text' },
            { key: 'heading', label: 'Heading', kind: 'text' },
            { key: 'subheading', label: 'Subheading', kind: 'text' },
            {
                key: 'desks',
                label: 'Desks',
                kind: 'repeater',
                itemLabel: 'desk',
                item: [
                    { key: 'icon', label: 'Icon', kind: 'text', placeholder: 'Ticket', translatable: false },
                    { key: 'tone', label: 'Tone', kind: 'text', placeholder: 'purple', translatable: false },
                    { key: 'title', label: 'Title', kind: 'text' },
                    { key: 'description', label: 'Description', kind: 'text' },
                    { key: 'email', label: 'Email', kind: 'text', translatable: false },
                ],
            },
            { key: 'hours_title', label: 'Hours banner title', kind: 'text' },
            { key: 'hours_subtitle', label: 'Hours banner subtitle', kind: 'text' },
            { key: 'hours_value', label: 'Opening hours', kind: 'text' },
            { key: 'hours_badge', label: 'Hours badge', kind: 'text' },
        ],
    },

    // ---------------------------------------------------------------------
    // Tickets-page sections. No money lives here: prices, admit limits and
    // the free-infant age come from the ticket type, so the cards and the
    // worked-out rules always agree with what the server charges.
    // ---------------------------------------------------------------------

    tickets_hero: {
        label: 'Tickets hero',
        description: 'The Tickets banner: fact cards, a countdown and two buttons.',
        media: 'none',
        fields: [
            { key: 'breadcrumb', label: 'Breadcrumb label', kind: 'text', help: 'The trailing crumb after “Home ›”.' },
            { key: 'year_pill', label: 'Year pill', kind: 'text', help: 'ASCII with an en-dash, e.g. “1927–2027” — the digits are localised for you.' },
            { key: 'eyebrow', label: 'Eyebrow', kind: 'text' },
            { key: 'heading_lead', label: 'Heading', kind: 'text' },
            { key: 'heading_accent', label: 'Heading accent word', kind: 'text', help: 'Drawn in gold after the heading.' },
            { key: 'body', label: 'Intro paragraph', kind: 'textarea' },
            {
                key: 'facts',
                label: 'Key facts',
                kind: 'repeater',
                itemLabel: 'fact',
                help: 'Three fit the row as designed.',
                item: [
                    { key: 'label', label: 'Label', kind: 'text' },
                    { key: 'value', label: 'Value', kind: 'text' },
                    { key: 'icon', label: 'Icon', kind: 'text', placeholder: 'Camera', translatable: false },
                ],
            },
            { key: 'countdown_target', label: 'Countdown target', kind: 'text', help: 'ISO 8601 with offset, e.g. 2027-02-12T09:00:00+06:00.' },
            { key: 'primary_label', label: 'Primary button', kind: 'text' },
            { key: 'primary_url', label: 'Primary link', kind: 'url', help: 'e.g. #register' },
            { key: 'secondary_label', label: 'Secondary button', kind: 'text' },
            { key: 'secondary_url', label: 'Secondary link', kind: 'url', help: 'e.g. #pricing' },
        ],
    },

    ticket_pricing: {
        label: 'Ticket pricing',
        description: 'The heading over the ticket card, and the card’s own words. Every figure on the card — the prices, the family limit, the free-child age — comes from the ticket type and is edited under Tickets, not here.',
        media: 'none',
        fields: [
            { key: 'eyebrow', label: 'Eyebrow', kind: 'text' },
            { key: 'heading_dark', label: 'Heading', kind: 'text' },
            { key: 'heading_accent', label: 'Heading accent', kind: 'text' },
            { key: 'body', label: 'Body', kind: 'textarea' },
            { key: 'card_badge', label: 'Card badge', kind: 'text', help: 'The small pill at the top of the price panel.' },
            { key: 'card_tagline', label: 'Card tagline', kind: 'textarea', help: 'The line under the badge, above the price.' },
            { key: 'card_price_caption', label: 'Price caption', kind: 'text', help: 'The small line under the headline price.' },
            { key: 'card_cta_label', label: 'Button label', kind: 'text', help: 'The button jumps to the registration form on this page.' },
            { key: 'includes_title', label: '“Every ticket includes” heading', kind: 'text' },
            {
                key: 'includes',
                label: 'What every ticket includes',
                kind: 'repeater',
                itemLabel: 'item',
                help: 'Leave the list empty to keep the designed one; the first row you add replaces it entirely.',
                item: [{ key: 'text', label: 'Item', kind: 'text' }],
            },
            { key: 'family_title', label: '“Bringing family?” heading', kind: 'text' },
            { key: 'family_optional_label', label: 'Optional tag', kind: 'text', help: 'The small “(optional)” beside the family heading.' },
            {
                key: 'family_includes',
                label: 'What family members get',
                kind: 'repeater',
                itemLabel: 'item',
                help: 'Shown only when the ticket type allows family. Same rule as above: empty keeps the designed list.',
                item: [{ key: 'text', label: 'Item', kind: 'text' }],
            },
        ],
    },

    pricing_rules: {
        label: 'Pricing rules',
        description: 'The heading beside the worked-out pricing rules. The rule bodies quote live prices and cannot be edited here.',
        media: 'none',
        fields: [
            { key: 'eyebrow', label: 'Eyebrow', kind: 'text' },
            { key: 'heading_dark', label: 'Heading', kind: 'text' },
            { key: 'heading_accent', label: 'Heading accent', kind: 'text' },
        ],
    },

    registration_form: {
        label: 'Registration form',
        description: 'The “reserve your place” heading, the what-to-have-ready checklist, and the live registration form beneath it.',
        media: 'none',
        fields: [
            { key: 'eyebrow', label: 'Eyebrow', kind: 'text' },
            { key: 'heading_dark', label: 'Heading', kind: 'text' },
            { key: 'heading_accent', label: 'Heading accent', kind: 'text' },
            { key: 'body', label: 'Body', kind: 'textarea' },
            { key: 'checklist_heading', label: 'Checklist heading', kind: 'text' },
            {
                key: 'checklist_items',
                label: 'Checklist items',
                kind: 'repeater',
                itemLabel: 'item',
                item: [{ key: 'text', label: 'Item', kind: 'text' }],
            },
            { key: 'video_label', label: 'Video button label', kind: 'text' },
            { key: 'video_url', label: 'Tutorial video', kind: 'url', help: 'A YouTube or Vimeo page URL. Blank shows a “coming soon” note.' },
        ],
    },

    how_it_works: {
        label: 'How it works',
        description: 'The numbered three-step walkthrough of registration.',
        media: 'none',
        fields: [
            { key: 'eyebrow', label: 'Eyebrow', kind: 'text' },
            { key: 'heading_dark', label: 'Heading', kind: 'text' },
            { key: 'heading_accent', label: 'Heading accent', kind: 'text' },
            {
                key: 'steps',
                label: 'Steps',
                kind: 'repeater',
                itemLabel: 'step',
                item: [
                    { key: 'title', label: 'Title', kind: 'text' },
                    { key: 'body', label: 'Body', kind: 'textarea' },
                ],
            },
        ],
    },

    ticket_faq: {
        label: 'Ticket FAQ',
        description: 'The ticket page’s own two-column question grid and the “still unsure?” line under it.',
        media: 'none',
        fields: [
            { key: 'eyebrow', label: 'Eyebrow', kind: 'text' },
            { key: 'heading_dark', label: 'Heading', kind: 'text' },
            { key: 'heading_accent', label: 'Heading accent', kind: 'text' },
            {
                key: 'items',
                label: 'Questions',
                kind: 'repeater',
                itemLabel: 'question',
                item: [
                    { key: 'question', label: 'Question', kind: 'text' },
                    { key: 'answer', label: 'Answer', kind: 'textarea' },
                ],
            },
            { key: 'tail', label: 'Closing line', kind: 'text' },
            { key: 'tail_label', label: 'Closing link label', kind: 'text' },
            { key: 'tail_url', label: 'Closing link', kind: 'url' },
        ],
    },

    // ---------------------------------------------------------------------
    // Attendees-page sections.
    // ---------------------------------------------------------------------

    attendees_hero: {
        label: 'Attendees hero',
        description: 'The directory banner. The six counts are live; only their labels are editable.',
        media: 'none',
        fields: [
            { key: 'breadcrumb', label: 'Breadcrumb label', kind: 'text', help: 'The trailing crumb after “Home ›”.' },
            { key: 'year_pill', label: 'Year pill', kind: 'text', help: 'ASCII with an en-dash, e.g. “1927–2027” — the digits are localised for you.' },
            { key: 'eyebrow', label: 'Eyebrow', kind: 'text' },
            { key: 'heading_lead', label: 'Heading', kind: 'text' },
            { key: 'heading_accent', label: 'Heading accent word', kind: 'text', help: 'Drawn in gold after the heading.' },
            { key: 'body', label: 'Intro paragraph', kind: 'textarea' },
            { key: 'label_total', label: 'Label: total registered', kind: 'text' },
            { key: 'label_alumni', label: 'Label: alumni', kind: 'text' },
            { key: 'label_students', label: 'Label: current students', kind: 'text' },
            { key: 'label_teachers_staff', label: 'Label: teachers & staff', kind: 'text' },
            { key: 'label_guests', label: 'Label: guests', kind: 'text' },
            { key: 'label_batches', label: 'Label: batches', kind: 'text' },
        ],
    },

    attendee_directory: {
        label: 'Attendee directory',
        description: 'Places the live, filterable attendee directory. It has no copy of its own.',
        media: 'none',
        fields: [],
    },

    // ---------------------------------------------------------------------
    // Site chrome. These live on the `site` page, which is not a route: the
    // public site reads its blocks into the header and footer on every page.
    // ---------------------------------------------------------------------

    site_logo: {
        label: 'Site logo',
        description: 'The logo in the header and the one in the footer. Leave either blank to keep the shipped centenary logo.',
        media: 'none',
        fields: [
            { key: 'header_logo', label: 'Header logo', kind: 'image', help: 'PNG or WebP with a transparent background, at least 1200px wide — it is shown 40px tall on a white bar.' },
            { key: 'footer_logo', label: 'Footer logo', kind: 'image', help: 'Shown 64px tall on white. Leave blank to reuse the header logo.' },
        ],
    },

    footer_identity: {
        label: 'Footer identity',
        description: 'The motto and short paragraph under the logo in the footer.',
        media: 'none',
        fields: [
            { key: 'tagline', label: 'Motto', kind: 'text', help: 'One display line under the logo.' },
            { key: 'description', label: 'Paragraph', kind: 'textarea', help: 'Two or three sentences; keep it short — it sits beside the link columns.' },
        ],
    },

    footer_links: {
        label: 'Footer link column',
        description: 'One column of footer links. Add a block per column; the footer shows them in order.',
        media: 'none',
        fields: [
            { key: 'title', label: 'Column heading', kind: 'text' },
            {
                key: 'links',
                label: 'Links',
                kind: 'repeater',
                itemLabel: 'link',
                item: [
                    { key: 'label', label: 'Label', kind: 'text' },
                    { key: 'href', label: 'Link', kind: 'url', placeholder: '/history or https://…' },
                ],
            },
        ],
    },

    footer_credit: {
        label: 'Footer credit',
        description: 'The “developed by” line in the footer’s bottom band.',
        media: 'none',
        fields: [
            { key: 'label', label: 'Lead-in', kind: 'text', help: 'e.g. “Developed by”.' },
            { key: 'name', label: 'Name', kind: 'text' },
            { key: 'url', label: 'Link', kind: 'url', help: 'Optional — wraps the name in a link when set.' },
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
