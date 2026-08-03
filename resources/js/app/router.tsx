import type { ReactNode } from 'react';
import { createBrowserRouter } from 'react-router-dom';
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
import NotificationsPage from '@/features/notifications/NotificationsPage';
import ReportsPage from '@/features/reports/ReportsPage';
import SettingsPage from '@/features/settings/SettingsPage';
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
    { path: '/notifications', element: page(<NotificationsPage />) },
    { path: '/reports', element: page(<ReportsPage />) },
    { path: '/settings', element: page(<SettingsPage />) },

    { path: '*', element: page(<Placeholder title="Page not found" note="Check the URL or use the navigation on the left." />) },
]);
