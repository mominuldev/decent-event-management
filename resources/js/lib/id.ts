/**
 * A random v4 UUID, in any browsing context.
 *
 * **Do not reach for `crypto.randomUUID()` directly.** It is specified
 * `[SecureContext]`, so it exists on HTTPS and on localhost and is
 * `undefined` everywhere else — including `http://decent-event-management.test`,
 * which is how this app is served in development. Calling it there throws
 * `TypeError: crypto.randomUUID is not a function` *before* the request it
 * was meant to key is ever sent, which surfaces as a generic failure a long
 * way from its cause. That is not a theoretical hazard: it shipped, and the
 * counter-registration form failed with "Network error. Please try again."
 * for exactly this reason.
 *
 * `crypto.getRandomValues()` carries no such gate — it is available in every
 * context — so the fallback is as random as the real thing, just assembled
 * by hand. Production (HTTPS) still takes the native path.
 */
export function randomId(): string {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }

    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);

    // RFC 4122 §4.4: version 4, variant 10xx.
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;

    const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}
