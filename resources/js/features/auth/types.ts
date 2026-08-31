export interface AuthUser {
    ulid: string;
    name: string;
    email: string;
    roles: string[];
}

/** Response of /admin/auth/login. */
export interface LoginResult {
    token: string;
    expires_at: string;
    requires_2fa_setup: boolean;
    user: AuthUser;
}

/** Response of /admin/auth/me — the full session shape once past 2FA. */
export interface Session {
    ulid: string;
    name: string;
    email: string;
    phone: string | null;
    roles: string[];
    permissions: string[];
}
