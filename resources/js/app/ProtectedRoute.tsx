import type { ReactNode } from 'react';
import { Navigate } from 'react-router-dom';
import { Ticket } from 'lucide-react';
import { useAuth } from '@/features/auth/AuthProvider';

function Splash() {
    return (
        <div className="grid min-h-screen place-items-center bg-bg">
            <div className="flex flex-col items-center gap-3">
                <div className="grid h-12 w-12 animate-pulse place-items-center rounded-2xl bg-accent text-accent-fg">
                    <Ticket size={24} />
                </div>
                <div className="text-[13px] text-text-muted">Loading…</div>
            </div>
        </div>
    );
}

/** Gate for fully authenticated (past 2FA) dashboard screens. */
export function ProtectedRoute({ children }: { children: ReactNode }) {
    const { status } = useAuth();
    if (status === 'loading') return <Splash />;
    if (status === 'needs_2fa_setup') return <Navigate to="/setup-2fa" replace />;
    if (status === 'guest') return <Navigate to="/login" replace />;
    return <>{children}</>;
}

/** Gate for the 2FA setup screen — only reachable mid-login, before a full token exists. */
export function TwoFactorSetupRoute({ children }: { children: ReactNode }) {
    const { status } = useAuth();
    if (status === 'loading') return <Splash />;
    if (status === 'guest') return <Navigate to="/login" replace />;
    if (status === 'authed') return <Navigate to="/" replace />;
    return <>{children}</>;
}

/** Keep authenticated users away from the login screen. */
export function GuestRoute({ children }: { children: ReactNode }) {
    const { status } = useAuth();
    if (status === 'loading') return <Splash />;
    if (status === 'needs_2fa_setup') return <Navigate to="/setup-2fa" replace />;
    if (status === 'authed') return <Navigate to="/" replace />;
    return <>{children}</>;
}
