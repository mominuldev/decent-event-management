import { money, num } from './cn';

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
