import type { ReactNode } from 'react';
import { createBrowserRouter, Navigate } from 'react-router-dom';
import { DashboardLayout } from '@/layouts/DashboardLayout';
import { ProtectedRoute, GuestRoute, TwoFactorSetupRoute } from '@/app/ProtectedRoute';
import LoginPage from '@/features/auth/LoginPage';
import TwoFactorSetupPage from '@/features/auth/TwoFactorSetupPage';
import DashboardPage from '@/features/dashboard/DashboardPage';
import AttendeesPage from '@/features/attendees/AttendeesPage';
import RegistrationsPage from '@/features/registrations/RegistrationsPage';
import FinancePage from '@/features/finance/FinancePage';
import TicketsPage from '@/features/tickets/TicketsPage';
import CheckInPage from '@/features/checkin/CheckInPage';
import CmsPage from '@/features/cms/CmsPage';
import PageEditorPage from '@/features/cms/PageEditorPage';
import NotificationsPage from '@/features/notifications/NotificationsPage';
import ReportsPage from '@/features/reports/ReportsPage';
import SettingsPage from '@/features/settings/SettingsPage';
import SigningKeysPage from '@/features/security/SigningKeysPage';
import AccountPage from '@/features/account/AccountPage';
import Placeholder from '@/features/misc/Placeholder';

// Protected page = auth gate + dashboard chrome.
const page = (el: ReactNode) => (
    <ProtectedRoute>
        <DashboardLayout>{el}</DashboardLayout>
    </ProtectedRoute>
);

export const router = createBrowserRouter([
    { path: '/login', element: <GuestRoute><LoginPage /></GuestRoute> },
    { path: '/setup-2fa', element: <TwoFactorSetupRoute><TwoFactorSetupPage /></TwoFactorSetupRoute> },

    { path: '/', element: page(<DashboardPage />) },
    { path: '/attendees', element: page(<AttendeesPage />) },
    { path: '/registrations', element: page(<RegistrationsPage />) },
    { path: '/finance', element: page(<FinancePage />) },
    { path: '/tickets', element: page(<TicketsPage />) },
    { path: '/check-in', element: page(<CheckInPage />) },

    // Each CMS section is its own sidebar link and URL now, so `/cms` itself
    // just redirects to the first one — Pages.
    { path: '/cms', element: <Navigate to="/cms/pages" replace /> },
    { path: '/cms/:section', element: page(<CmsPage />) },

    // `new` is handled by the same editor as an existing ULID — it is not a
    // valid ULID, so it cannot collide with a real page. More specific than
    // `/cms/:section` (three segments vs two), so it wins for this shape.
    { path: '/cms/pages/:ulid', element: page(<PageEditorPage />) },

    { path: '/notifications', element: page(<NotificationsPage />) },
    { path: '/reports', element: page(<ReportsPage />) },
    { path: '/settings', element: page(<SettingsPage />) },
    { path: '/security/signing-keys', element: page(<SigningKeysPage />) },

    // No permission gate: every signed-in staff member owns their own account.
    { path: '/account', element: page(<AccountPage />) },

    { path: '*', element: page(<Placeholder title="Page not found" note="Check the URL or use the navigation on the left." />) },
]);
