/**
 * Response shapes for the admin CMS API.
 *
 * Hand-written and therefore driftable — the Phase 3 exit criterion is to
 * generate these from public/docs/openapi.json. Until then, re-check this
 * file whenever an Admin\Content API Resource changes.
 */

export type PageStatus = 'draft' | 'in_review' | 'published' | 'archived';

export const PAGE_STATUSES: PageStatus[] = ['draft', 'in_review', 'published', 'archived'];

/** Mirrors ContentPage::TRANSITIONS — the server rejects anything else with a 422. */
export const PAGE_TRANSITIONS: Record<PageStatus, PageStatus[]> = {
    draft: ['in_review', 'published'],
    in_review: ['draft', 'published'],
    published: ['draft', 'archived'],
    archived: ['draft'],
};

/** Mirrors ContentPage::TEMPLATES. */
export const PAGE_TEMPLATES = ['standard', 'landing', 'article', 'contact'] as const;

/** Mirrors ContentBlock::TYPES. */
export const BLOCK_TYPES = [
    'rich_text', 'hero', 'image', 'cta', 'stat_row',
    'faq_list', 'sponsor_grid', 'schedule', 'gallery', 'video',
] as const;

export type BlockType = (typeof BLOCK_TYPES)[number];

export type SponsorTier = 'platinum' | 'gold' | 'silver' | 'bronze' | 'partner';

export const SPONSOR_TIERS: SponsorTier[] = ['platinum', 'gold', 'silver', 'bronze', 'partner'];

export const MEDIA_COLLECTIONS = ['content', 'page_og', 'sponsor_logo', 'speaker_photo', 'gallery'] as const;

export type MediaCollection = (typeof MEDIA_COLLECTIONS)[number];

export interface MediaFile {
    ulid: string;
    collection: string;
    original_name: string | null;
    mime_type: string;
    extension: string;
    size_bytes: number;
    width: number | null;
    height: number | null;
    is_public: boolean;
    scan_status: string;
    /** Null for private files — those are only ever served via a signed URL. */
    url: string | null;
    created_at: string | null;
}

/** Free-form per block type; the field set lives in `blocks.ts`. */
export type BlockData = Record<string, unknown>;

export interface ContentBlock {
    ulid: string;
    type: BlockType;
    position: number;
    data: BlockData;
    data_bn: BlockData | null;
    media?: MediaFile | null;
    is_visible: boolean;
}

/** A block being edited — `ulid` is absent until the first save. */
export interface BlockDraft {
    ulid?: string;
    type: BlockType;
    data: BlockData;
    data_bn: BlockData;
    media_ulid: string | null;
    media?: MediaFile | null;
    is_visible: boolean;
    /** Local-only key so React can track rows that have no ULID yet. */
    key: string;
}

export interface ContentPageSummary {
    ulid: string;
    slug: string;
    template: string;
    title: string;
    title_bn: string | null;
    status: PageStatus;
    is_live: boolean;
    published_at: string | null;
    is_indexable: boolean;
    position: number;
    revision_number: number;
    has_preview_token: boolean;
    updated_by?: string | null;
    updated_at: string | null;
}

export interface ContentPage extends ContentPageSummary {
    excerpt: string | null;
    excerpt_bn: string | null;
    seo_title: string | null;
    seo_title_bn: string | null;
    seo_description: string | null;
    seo_description_bn: string | null;
    og_image?: MediaFile | null;
    created_by?: string | null;
    published_by?: string | null;
    created_at: string | null;
    blocks: ContentBlock[];
}

export interface PageRevision {
    ulid: string;
    revision_number: number;
    title: string;
    title_bn: string | null;
    excerpt: string | null;
    status_at_capture: PageStatus;
    change_note: string | null;
    created_by?: string | null;
    created_at: string | null;
}

export interface PreviewLink {
    preview_token: string;
    preview_url: string;
}

export interface Sponsor {
    ulid: string;
    name: string;
    name_bn: string | null;
    tier: SponsorTier;
    tier_rank: number;
    logo?: MediaFile | null;
    website_url: string | null;
    description: string | null;
    description_bn: string | null;
    position: number;
    is_published: boolean;
    updated_at: string | null;
}

export interface ScheduleItem {
    ulid: string;
    title: string;
    title_bn: string | null;
    description: string | null;
    description_bn: string | null;
    speaker_name: string | null;
    speaker_name_bn: string | null;
    speaker_title: string | null;
    speaker_title_bn: string | null;
    speaker_photo?: MediaFile | null;
    venue: string | null;
    venue_bn: string | null;
    track: string | null;
    starts_at: string;
    ends_at: string | null;
    event_session_code: string | null;
    position: number;
    is_published: boolean;
    updated_at: string | null;
}

export interface Faq {
    ulid: string;
    question: string;
    question_bn: string | null;
    answer: string;
    answer_bn: string | null;
    category: string | null;
    category_bn: string | null;
    position: number;
    is_published: boolean;
    updated_at: string | null;
}

export interface GalleryItem {
    ulid: string;
    album_ulid?: string;
    media?: MediaFile | null;
    caption: string | null;
    caption_bn: string | null;
    alt_text: string | null;
    alt_text_bn: string | null;
    position: number;
    is_published: boolean;
}

export interface GalleryAlbum {
    ulid: string;
    slug: string;
    title: string;
    title_bn: string | null;
    description: string | null;
    description_bn: string | null;
    cover?: MediaFile | null;
    position: number;
    is_published: boolean;
    items_count?: number;
    items?: GalleryItem[];
    updated_at: string | null;
}

export interface MenuItemNode {
    ulid: string;
    label: string;
    label_bn: string | null;
    page_ulid?: string | null;
    page_title?: string | null;
    url: string | null;
    /** What the public site would render; null means the target is no longer live. */
    resolved_url: string | null;
    target: '_self' | '_blank';
    position: number;
    is_visible: boolean;
    children: MenuItemNode[];
}

export interface Menu {
    ulid: string;
    code: string;
    name: string;
    name_bn: string | null;
    is_active: boolean;
    items?: MenuItemNode[];
    created_at: string | null;
    updated_at: string | null;
}
