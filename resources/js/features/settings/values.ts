import { money, num } from '@/lib/cn';
import type { EventSetting } from './types';

const pad = (n: number) => String(n).padStart(2, '0');

/**
 * `<input type="datetime-local">` speaks wall-clock time with no zone, so an
 * ISO instant has to be converted into the *browser's* local time to prefill
 * it, and converted back to an instant on save. Anything else silently shifts
 * a cutoff by the viewer's UTC offset.
 */
export function toDatetimeLocal(iso: string): string {
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

export function fromDatetimeLocal(local: string): string {
    const d = new Date(local);
    return Number.isNaN(d.getTime()) ? '' : d.toISOString();
}

/** How the value reads when the row is not being edited. */
export function displayValue(setting: EventSetting): string {
    const v = setting.typed_value;
    if (v === null || v === '') return '—';

    switch (setting.type) {
        case 'bool':
            return v ? 'On' : 'Off';
        case 'money':
            return money(Number(v));
        case 'int':
            return num(Number(v));
        case 'datetime': {
            const d = new Date(String(v));
            return Number.isNaN(d.getTime())
                ? String(v)
                : d.toLocaleString(undefined, {
                    year: 'numeric',
                    month: 'short',
                    day: 'numeric',
                    hour: 'numeric',
                    minute: '2-digit',
                });
        }
        case 'json':
            return JSON.stringify(v);
        default:
            return String(v);
    }
}

/** Seeds the editor when a row enters edit mode. */
export function toDraft(setting: EventSetting): string {
    const v = setting.typed_value;
    if (v === null) return '';

    switch (setting.type) {
        case 'datetime':
            return toDatetimeLocal(String(v));
        case 'json':
            return JSON.stringify(v, null, 2);
        default:
            return String(v);
    }
}

/**
 * Returns a message when the draft can't be saved, or null when it can. This
 * mirrors `UpdateSettingRequest`'s rules so the mistake is caught in the field
 * the user is typing in rather than as a toast after a round trip.
 */
export function validateDraft(setting: EventSetting, draft: string): string | null {
    const trimmed = draft.trim();

    if (trimmed === '' && setting.type !== 'string') {
        return 'This setting needs a value.';
    }

    switch (setting.type) {
        case 'int':
        case 'money':
            if (!/^-?\d+$/.test(trimmed)) return 'Enter a whole number.';
            if (setting.type === 'money' && Number(trimmed) < 0) return 'Enter a positive amount.';
            return null;
        case 'datetime':
            return Number.isNaN(new Date(trimmed).getTime()) ? 'Pick a valid date and time.' : null;
        case 'json':
            try {
                JSON.parse(trimmed);
                return null;
            } catch {
                return 'This is not valid JSON.';
            }
        default:
            return null;
    }
}

/** The value sent to `PATCH /admin/settings/{key}`. */
export function fromDraft(setting: EventSetting, draft: string): unknown {
    switch (setting.type) {
        case 'int':
        case 'money':
            return Number(draft.trim());
        case 'datetime':
            return fromDatetimeLocal(draft);
        case 'json':
            return JSON.parse(draft);
        default:
            return draft;
    }
}

/** "3 minutes ago" / "on 12 Feb 2026" for the last-changed line. */
export function timeAgo(iso: string | null): string | null {
    if (!iso) return null;
    const then = new Date(iso).getTime();
    if (Number.isNaN(then)) return null;

    const seconds = Math.round((Date.now() - then) / 1000);
    if (seconds < 60) return 'just now';

    const units: [number, Intl.RelativeTimeFormatUnit][] = [
        [60, 'minute'],
        [60, 'hour'],
        [24, 'day'],
        [7, 'week'],
    ];

    let value = seconds;
    let unit: Intl.RelativeTimeFormatUnit = 'second';
    for (const [step, next] of units) {
        if (Math.abs(value) < step) break;
        value = Math.round(value / step);
        unit = next;
    }

    if (unit === 'week' && Math.abs(value) > 4) {
        return `on ${new Date(iso).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })}`;
    }

    return new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' }).format(-value, unit);
}
