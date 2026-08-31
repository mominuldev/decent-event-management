import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react';
import * as authApi from './api';
import { getToken, setToken, setUnauthorizedHandler } from '@/lib/api';
import type { AuthUser, Session } from './types';

type Status = 'loading' | 'guest' | 'needs_2fa_setup' | 'authed';

interface AuthCtx {
    status: Status;
    session: Session | null;
    pendingUser: AuthUser | null;
    login: (email: string, password: string, totpCode?: string) => Promise<void>;
    completeTwoFactorSetup: (token: string) => Promise<void>;
    logout: () => Promise<void>;
    can: (permission: string) => boolean;
    /** Re-read /admin/auth/me — after the signed-in user edits their own profile. */
    refreshSession: () => Promise<void>;
    /** Replace the session with one the caller already has, skipping the round trip. */
    applySession: (session: Session) => void;
}

const Ctx = createContext<AuthCtx | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
    const [status, setStatus] = useState<Status>('loading');
    const [session, setSession] = useState<Session | null>(null);
    const [pendingUser, setPendingUser] = useState<AuthUser | null>(null);

    const loadSession = useCallback(async () => {
        const s = await authApi.fetchMe();
        setSession(s);
        setPendingUser(null);
        setStatus('authed');
    }, []);

    useEffect(() => {
        setUnauthorizedHandler(() => {
            setSession(null);
            setPendingUser(null);
            setStatus('guest');
        });

        if (!getToken()) {
            setStatus('guest');
            return;
        }

        loadSession().catch(() => {
            setToken(null);
            setStatus('guest');
        });
    }, [loadSession]);

    const login = useCallback(
        async (email: string, password: string, totpCode?: string) => {
            const result = await authApi.login(email, password, totpCode);
            setToken(result.token);

            if (result.requires_2fa_setup) {
                setPendingUser(result.user);
                setStatus('needs_2fa_setup');
                return;
            }

            await loadSession();
        },
        [loadSession],
    );

    const completeTwoFactorSetup = useCallback(
        async (token: string) => {
            setToken(token);
            await loadSession();
        },
        [loadSession],
    );

    const logout = useCallback(async () => {
        try {
            await authApi.logout();
        } finally {
            setToken(null);
            setSession(null);
            setPendingUser(null);
            setStatus('guest');
        }
    }, []);

    const applySession = useCallback((next: Session) => {
        setSession(next);
    }, []);

    const can = useCallback(
        (permission: string) => session?.permissions.includes(permission) ?? false,
        [session],
    );

    return (
        <Ctx.Provider value={{ status, session, pendingUser, login, completeTwoFactorSetup, logout, can, refreshSession: loadSession, applySession }}>
            {children}
        </Ctx.Provider>
    );
}

export function useAuth() {
    const ctx = useContext(Ctx);
    if (!ctx) throw new Error('useAuth must be used within AuthProvider');
    return ctx;
}
