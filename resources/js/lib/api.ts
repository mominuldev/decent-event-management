import axios from 'axios';

const TOKEN_KEY = 'decent-admin-token';

export function getToken(): string | null {
    return localStorage.getItem(TOKEN_KEY);
}

export function setToken(token: string | null) {
    if (token) localStorage.setItem(TOKEN_KEY, token);
    else localStorage.removeItem(TOKEN_KEY);
}

/**
 * Bearer-token client for the `web-admin` guard (Sanctum personal-access
 * tokens with abilities, not the cookie-based SPA flow — see
 * AdminAuthController::login). Token lives in localStorage; every request
 * attaches it as Authorization: Bearer.
 */
export const api = axios.create({
    baseURL: '/api/v1',
    headers: { Accept: 'application/json' },
});

api.interceptors.request.use((config) => {
    const token = getToken();
    if (token) {
        config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
});

type UnauthorizedHandler = () => void;
let onUnauthorized: UnauthorizedHandler | null = null;

/** Registered once by AuthProvider so a 401 anywhere clears the session. */
export function setUnauthorizedHandler(fn: UnauthorizedHandler) {
    onUnauthorized = fn;
}

api.interceptors.response.use(
    (response) => response,
    (error: unknown) => {
        if (axios.isAxiosError(error) && error.response?.status === 401) {
            setToken(null);
            onUnauthorized?.();
        }
        return Promise.reject(error);
    },
);

export interface ApiError {
    message: string;
    code?: string;
    errors?: Record<string, string[]>;
    request_id?: string;
}

/**
 * Normalise a thrown value into the API's error envelope.
 *
 * The three failures below are genuinely different things and used to
 * collapse into one message. That is not cosmetic: a bug in our own code
 * reported as "Network error. Please try again." sends the reader to check
 * their wifi and the developer to check the server, and neither is where
 * the fault is. It happened — `crypto.randomUUID()` is secure-context only,
 * so on `http://…test` the counter-registration form threw before sending
 * anything and said the network was down. See lib/id.ts.
 */
export function toApiError(e: unknown): ApiError {
    if (axios.isAxiosError(e)) {
        const data = e.response?.data;

        // The server answered in the uniform envelope — the normal path.
        if (data && typeof data === 'object') {
            return data as ApiError;
        }

        // It answered with something else: an HTML error page from a 500
        // with APP_DEBUG on, or a proxy's own page. Returning it verbatim
        // used to put `message: undefined` in a toast.
        if (e.response) {
            return {
                message: `The server returned an unexpected ${e.response.status} response.`,
                code: 'unexpected_response',
            };
        }

        // No response at all — the only case that really is the network.
        return { message: 'Network error. Please try again.' };
    }

    // Not an HTTP failure, so it is a fault in this app rather than in the
    // request. Logged in full because the toast is deliberately short, and
    // this is the case where the stack is the whole story.
    console.error('Unexpected client-side error', e);

    return {
        message: e instanceof Error ? e.message : 'Something went wrong. Please reload and try again.',
        code: 'client_error',
    };
}

/**
 * An API failure that still carries its per-field messages.
 *
 * The convention is that `errors` becomes field-level text and `message`
 * becomes the toast, but `throw new Error(toApiError(e).message)` — the shape
 * most of the feature api.ts files use — discards `errors` on the way out, so
 * a 422 can only ever surface as one vague banner. That matters most where the
 * server has written a specific message worth reading: editing an attendee
 * onto a mobile number another attendee already holds answers "This mobile
 * number already belongs to another attendee", and the operator needs to see
 * it against the mobile field, not as a toast that could mean anything.
 *
 * Subclasses Error, so an existing `onError: (e: Error) => push(e.message)`
 * keeps working unchanged and can opt into the detail when it wants it.
 */
export class ApiRequestError extends Error {
    readonly code?: string;

    readonly errors?: Record<string, string[]>;

    readonly requestId?: string;

    constructor(source: unknown) {
        const api = toApiError(source);
        super(api.message);
        this.name = 'ApiRequestError';
        this.code = api.code;
        this.errors = api.errors;
        this.requestId = api.request_id;
    }

    /** The first message for a field, if the server named one. */
    for(field: string): string | undefined {
        return this.errors?.[field]?.[0];
    }
}
