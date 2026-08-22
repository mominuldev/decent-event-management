import { money, num } from './cn';

/**
 * The date form the data tables use in their "Created" column — day and month
 * only. Tables are ordered newest-first by default, so the year is noise on
 * every row that is not near a boundary; the full timestamp is in the detail
 * dialog.
 */
export function shortDate(value: string | null | undefined): string {
    if (!value) return '—';
    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) return '—';
    return parsed.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
}

/** Day, month and year — for a date the reader needs to place exactly, like a birth date. */
export function fullDate(value: string | null | undefined): string {
    if (!value) return '—';
    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) return '—';
    return parsed.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
}

export function titleCase(key: string): string {
    return key
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (c) => c.toUpperCase());
}

/**
 * Best-effort rendering for report rows whose field shapes aren't pinned down
 * by the OpenAPI spec (see docs/08 §3.2 Reports — the catalogue's row shapes
 * vary per report key). Formats by key-name convention rather than a fixed
 * schema: `*_paisa` as money, `*_at`/`*_date` as a date, numbers with
 * separators, everything else as-is.
 */
export function formatGenericValue(key: string, value: unknown): string {
    if (value === null || value === undefined) return '—';
    if (typeof value === 'number') {
        if (key.endsWith('_paisa')) return money(value);
        return num(value);
    }
    if (typeof value === 'string') {
        if ((key.endsWith('_at') || key.endsWith('_date')) && !Number.isNaN(Date.parse(value))) {
            return new Date(value).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
        }
        return value;
    }
    if (typeof value === 'boolean') return value ? 'Yes' : 'No';
    return JSON.stringify(value);
}
