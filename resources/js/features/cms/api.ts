import { api, toApiError } from '@/lib/api';
import type { PaginatedResponse } from '@/lib/pagination';
import { unwrap } from '@/lib/pagination';
import type {
    ContentPage, ContentPageSummary, Faq, GalleryAlbum, GalleryItem, MediaCollection, MediaFile,
    Menu, MenuItemNode, PageRevision, PageStatus, PreviewLink, ScheduleItem, Sponsor,
} from './types';

/**
 * Every CMS write goes through `rethrow`, so a 422's `message` reaches the
 * toast and field errors stay available on the original envelope.
 */
async function rethrow<T>(fn: () => Promise<T>): Promise<T> {
    try {
        return await fn();
    } catch (e) {
        throw new Error(toApiError(e).message);
    }
}

/* ----------------------------------------------------------------- Pages */

export interface PageFilters {
    status?: string;
    template?: string;
    q?: string;
    page?: number;
    per_page?: number;
}

export async function fetchPages(filters: PageFilters): Promise<PaginatedResponse<ContentPageSummary>> {
    const { data } = await api.get('/admin/content/pages', {
        params: {
            status: filters.status || undefined,
            template: filters.template || undefined,
            q: filters.q || undefined,
            page: filters.page,
            per_page: filters.per_page,
        },
    });
    return data as PaginatedResponse<ContentPageSummary>;
}

export async function fetchPage(ulid: string): Promise<ContentPage> {
    const { data } = await api.get(`/admin/content/pages/${ulid}`);
    return unwrap<ContentPage>(data);
}

/** Sending `blocks` replaces the whole tree; omitting it leaves it untouched. */
export async function createPage(body: Record<string, unknown>): Promise<ContentPage> {
    return rethrow(async () => {
        const { data } = await api.post('/admin/content/pages', body);
        return unwrap<ContentPage>(data);
    });
}

export async function updatePage(ulid: string, body: Record<string, unknown>): Promise<ContentPage> {
    return rethrow(async () => {
        const { data } = await api.patch(`/admin/content/pages/${ulid}`, body);
        return unwrap<ContentPage>(data);
    });
}

export async function deletePage(ulid: string): Promise<void> {
    return rethrow(async () => {
        await api.delete(`/admin/content/pages/${ulid}`);
    });
}

export async function changePageStatus(ulid: string, status: PageStatus, publishedAt?: string): Promise<ContentPage> {
    return rethrow(async () => {
        const { data } = await api.post(`/admin/content/pages/${ulid}/status`, {
            status,
            published_at: publishedAt || undefined,
        });
        return unwrap<ContentPage>(data);
    });
}

/** The token is returned exactly once — nothing else on the API exposes it. */
export async function issuePreviewToken(ulid: string): Promise<PreviewLink> {
    return rethrow(async () => {
        const { data } = await api.post(`/admin/content/pages/${ulid}/preview-token`);
        return unwrap<PreviewLink>(data);
    });
}

export async function fetchRevisions(ulid: string): Promise<PaginatedResponse<PageRevision>> {
    const { data } = await api.get(`/admin/content/pages/${ulid}/revisions`);
    return data as PaginatedResponse<PageRevision>;
}

export async function restoreRevision(pageUlid: string, revisionUlid: string): Promise<ContentPage> {
    return rethrow(async () => {
        const { data } = await api.post(`/admin/content/pages/${pageUlid}/revisions/${revisionUlid}/restore`);
        return unwrap<ContentPage>(data);
    });
}

/* -------------------------------------------------------------- Sponsors */

export async function fetchSponsors(): Promise<PaginatedResponse<Sponsor>> {
    const { data } = await api.get('/admin/content/sponsors', { params: { per_page: 100 } });
    return data as PaginatedResponse<Sponsor>;
}

export async function saveSponsor(ulid: string | null, body: Record<string, unknown>): Promise<Sponsor> {
    return rethrow(async () => {
        const { data } = ulid
            ? await api.patch(`/admin/content/sponsors/${ulid}`, body)
            : await api.post('/admin/content/sponsors', body);
        return unwrap<Sponsor>(data);
    });
}

export async function deleteSponsor(ulid: string): Promise<void> {
    return rethrow(async () => {
        await api.delete(`/admin/content/sponsors/${ulid}`);
    });
}

/* -------------------------------------------------------------- Schedule */

export async function fetchScheduleItems(): Promise<PaginatedResponse<ScheduleItem>> {
    const { data } = await api.get('/admin/content/schedule', { params: { per_page: 100 } });
    return data as PaginatedResponse<ScheduleItem>;
}

export async function saveScheduleItem(ulid: string | null, body: Record<string, unknown>): Promise<ScheduleItem> {
    return rethrow(async () => {
        const { data } = ulid
            ? await api.patch(`/admin/content/schedule/${ulid}`, body)
            : await api.post('/admin/content/schedule', body);
        return unwrap<ScheduleItem>(data);
    });
}

export async function deleteScheduleItem(ulid: string): Promise<void> {
    return rethrow(async () => {
        await api.delete(`/admin/content/schedule/${ulid}`);
    });
}

/* ------------------------------------------------------------------ FAQs */

export async function fetchFaqs(): Promise<PaginatedResponse<Faq>> {
    const { data } = await api.get('/admin/content/faqs', { params: { per_page: 100 } });
    return data as PaginatedResponse<Faq>;
}

export async function saveFaq(ulid: string | null, body: Record<string, unknown>): Promise<Faq> {
    return rethrow(async () => {
        const { data } = ulid
            ? await api.patch(`/admin/content/faqs/${ulid}`, body)
            : await api.post('/admin/content/faqs', body);
        return unwrap<Faq>(data);
    });
}

export async function deleteFaq(ulid: string): Promise<void> {
    return rethrow(async () => {
        await api.delete(`/admin/content/faqs/${ulid}`);
    });
}

/* --------------------------------------------------------------- Gallery */

export async function fetchAlbums(): Promise<PaginatedResponse<GalleryAlbum>> {
    const { data } = await api.get('/admin/content/gallery', { params: { per_page: 100 } });
    return data as PaginatedResponse<GalleryAlbum>;
}

export async function fetchAlbum(ulid: string): Promise<GalleryAlbum> {
    const { data } = await api.get(`/admin/content/gallery/${ulid}`);
    return unwrap<GalleryAlbum>(data);
}

export async function saveAlbum(ulid: string | null, body: Record<string, unknown>): Promise<GalleryAlbum> {
    return rethrow(async () => {
        const { data } = ulid
            ? await api.patch(`/admin/content/gallery/${ulid}`, body)
            : await api.post('/admin/content/gallery', body);
        return unwrap<GalleryAlbum>(data);
    });
}

export async function deleteAlbum(ulid: string): Promise<void> {
    return rethrow(async () => {
        await api.delete(`/admin/content/gallery/${ulid}`);
    });
}

export async function addAlbumItem(albumUlid: string, body: Record<string, unknown>): Promise<GalleryItem> {
    return rethrow(async () => {
        const { data } = await api.post(`/admin/content/gallery/${albumUlid}/items`, body);
        return unwrap<GalleryItem>(data);
    });
}

export async function updateAlbumItem(albumUlid: string, itemUlid: string, body: Record<string, unknown>): Promise<GalleryItem> {
    return rethrow(async () => {
        const { data } = await api.patch(`/admin/content/gallery/${albumUlid}/items/${itemUlid}`, body);
        return unwrap<GalleryItem>(data);
    });
}

export async function deleteAlbumItem(albumUlid: string, itemUlid: string): Promise<void> {
    return rethrow(async () => {
        await api.delete(`/admin/content/gallery/${albumUlid}/items/${itemUlid}`);
    });
}

/* ----------------------------------------------------------------- Menus */

export async function fetchMenus(): Promise<Menu[]> {
    const { data } = await api.get('/admin/content/menus');
    return (data as { data: Menu[] }).data;
}

export async function saveMenu(ulid: string | null, body: Record<string, unknown>): Promise<Menu> {
    return rethrow(async () => {
        const { data } = ulid
            ? await api.patch(`/admin/content/menus/${ulid}`, body)
            : await api.post('/admin/content/menus', body);
        return unwrap<Menu>(data);
    });
}

export async function deleteMenu(ulid: string): Promise<void> {
    return rethrow(async () => {
        await api.delete(`/admin/content/menus/${ulid}`);
    });
}

export async function saveMenuItem(menuUlid: string, itemUlid: string | null, body: Record<string, unknown>): Promise<MenuItemNode> {
    return rethrow(async () => {
        const { data } = itemUlid
            ? await api.patch(`/admin/content/menus/${menuUlid}/items/${itemUlid}`, body)
            : await api.post(`/admin/content/menus/${menuUlid}/items`, body);
        return unwrap<MenuItemNode>(data);
    });
}

export async function deleteMenuItem(menuUlid: string, itemUlid: string): Promise<void> {
    return rethrow(async () => {
        await api.delete(`/admin/content/menus/${menuUlid}/items/${itemUlid}`);
    });
}

/* ----------------------------------------------------------------- Media */

export async function fetchMedia(collection?: string, page = 1): Promise<PaginatedResponse<MediaFile>> {
    const { data } = await api.get('/admin/content/media', {
        params: { collection: collection || undefined, page, per_page: 40 },
    });
    return data as PaginatedResponse<MediaFile>;
}

/**
 * Multipart upload. The server decides the file's type from its magic bytes
 * and re-encodes the image, so nothing here needs to inspect it first.
 */
export async function uploadMedia(file: File, collection: MediaCollection = 'content'): Promise<MediaFile> {
    return rethrow(async () => {
        const form = new FormData();
        form.append('file', file);
        form.append('collection', collection);
        const { data } = await api.post('/admin/content/media', form);
        return unwrap<MediaFile>(data);
    });
}

export async function deleteMedia(ulid: string): Promise<void> {
    return rethrow(async () => {
        await api.delete(`/admin/content/media/${ulid}`);
    });
}
