/**
 * The OpenAPI spec documents three different envelope shapes across list
 * endpoints (registrations/attendees/payments vs. tickets vs. ticket-types —
 * see docs/08 build notes). Treat `links`/`meta` as optional and fall back to
 * `data.length` for a total when `meta.total` isn't present, rather than
 * assuming a fixed shape.
 */
export interface PaginatedResponse<T> {
    data: T[];
    links?: Record<string, unknown>;
    meta?: {
        current_page?: number;
        per_page?: number;
        total?: number;
        last_page?: number;
    };
}

export function totalOf<T>(page: PaginatedResponse<T>): number {
    return page.meta?.total ?? page.data.length;
}

/** Some show endpoints wrap in `{ data: T }`, others document a bare object — normalise both. */
export function unwrap<T>(payload: unknown): T {
    if (payload && typeof payload === 'object' && 'data' in payload) {
        return (payload as { data: T }).data;
    }
    return payload as T;
}
