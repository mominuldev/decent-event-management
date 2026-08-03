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

/** Normalise an axios error into the API's error envelope. */
export function toApiError(e: unknown): ApiError {
    if (axios.isAxiosError(e) && e.response?.data) {
        return e.response.data as ApiError;
    }
    return { message: 'Network error. Please try again.' };
}
