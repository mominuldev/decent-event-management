import { useMemo, useState, type ReactNode } from 'react';
import { NavLink, useLocation } from 'react-router-dom';
import {
    LayoutDashboard, Users, ClipboardList, Wallet, Ticket, QrCode, Bell, FileText,
    BarChart3, Settings, Search, BellRing, Menu, X, Sun, Moon, LogOut, ChevronRight, ChevronDown,
    Globe, Award, CalendarClock, HelpCircle, Images, Image as ImageIcon, Compass,
} from 'lucide-react';
import { cn } from '@/lib/cn';
import { useTheme } from '@/app/theme';
import { useAuth } from '@/features/auth/AuthProvider';
import { IconButton } from '@/components/ui';

type Item = { to: string; label: string; icon: typeof LayoutDashboard; permission?: string; children?: Item[] };
type Group = { heading?: string; items: Item[] };

const NAV: Group[] = [
    { items: [{ to: '/', label: 'Overview', icon: LayoutDashboard }] },
    {
        heading: 'Attendees',
        items: [
            { to: '/attendees', label: 'Attendees', icon: Users, permission: 'attendee.view_any' },
            { to: '/registrations', label: 'Registrations', icon: ClipboardList, permission: 'registration.view_any' },
        ],
    },
    {
        heading: 'Finance',
        items: [{ to: '/finance', label: 'Payments', icon: Wallet, permission: 'payment.view_any' }],
    },
    {
        heading: 'Tickets',
        items: [{ to: '/tickets', label: 'Tickets', icon: Ticket, permission: 'ticket.view_any' }],
    },
    {
        heading: 'Check-in',
        items: [{ to: '/check-in', label: 'Live gate monitor', icon: QrCode, permission: 'checkin.view_any' }],
    },
    {
        heading: 'Notifications',
        items: [{ to: '/notifications', label: 'Notifications', icon: Bell, permission: 'notification.view_any' }],
    },
    {
        heading: 'Website',
        items: [
            {
                to: '/cms',
                label: 'Content',
                icon: Globe,
                permission: 'content.view_any',
                children: [
                    { to: '/cms/pages', label: 'Pages', icon: FileText, permission: 'content.view_any' },
                    { to: '/cms/menus', label: 'Navigation', icon: Compass, permission: 'content.view_any' },
                    { to: '/cms/sponsors', label: 'Sponsors', icon: Award, permission: 'content.view_any' },
                    { to: '/cms/schedule', label: 'Schedule', icon: CalendarClock, permission: 'content.view_any' },
                    { to: '/cms/faqs', label: 'FAQs', icon: HelpCircle, permission: 'content.view_any' },
                    { to: '/cms/gallery', label: 'Gallery', icon: Images, permission: 'content.view_any' },
                    { to: '/cms/media', label: 'Media', icon: ImageIcon, permission: 'content.manage_media' },
                ],
            },
        ],
    },
    {
        heading: 'Reports',
        items: [{ to: '/reports', label: 'Reports', icon: BarChart3, permission: 'report.view_registrations' }],
    },
    {
        heading: 'Settings',
        items: [{ to: '/settings', label: 'Settings', icon: Settings, permission: 'settings.view' }],
    },
];

/** Sub-items stand in for their parent as navigation targets — the parent
 * (e.g. `Content`) is a container, not its own destination. */
function flatNavItems(): Item[] {
    return NAV.flatMap((g) => g.items.flatMap((i) => (i.children && i.children.length > 0 ? i.children : [i])));
}

function breadcrumbLabel(pathname: string): string {
    const item = flatNavItems().find((i) => i.to === pathname || (i.to !== '/' && pathname.startsWith(i.to + '/')));
    return item?.label ?? 'Overview';
}

function isWithin(pathname: string, to: string): boolean {
    return pathname === to || pathname.startsWith(to + '/');
}

function NavItemLink({ item, indent, onNavigate }: { item: Item; indent?: boolean; onNavigate?: () => void }) {
    return (
        <NavLink
            to={item.to}
            end={item.to === '/'}
            onClick={onNavigate}
            className={({ isActive }) =>
                cn(
                    'group flex items-center gap-3 rounded-xl px-3 py-2 text-[13.5px] font-medium transition-colors',
                    indent && 'py-1.5 text-[13px]',
                    isActive ? 'bg-accent text-accent-fg shadow-[var(--shadow-soft)]' : 'text-text-muted hover:bg-surface-2 hover:text-text',
                )
            }
        >
            {({ isActive }) => (
                <>
                    <item.icon size={indent ? 16 : 18} strokeWidth={2.1} className={cn(isActive ? '' : 'text-text-faint group-hover:text-text')} />
                    {item.label}
                </>
            )}
        </NavLink>
    );
}

/** A parent with sub-items: the row expands to its children whenever the
 * current route is inside it, so landing on `/cms/gallery` from a bookmark
 * or the browser back button shows "Gallery" nested under "Content" without
 * the user having to click anything first. */
function NavGroupItem({ item, pathname, onNavigate }: { item: Item; pathname: string; onNavigate?: () => void }) {
    const expanded = isWithin(pathname, item.to);

    return (
        <div>
            <NavLink
                to={item.to}
                onClick={onNavigate}
                className={cn(
                    'group flex items-center gap-3 rounded-xl px-3 py-2 text-[13.5px] font-medium transition-colors',
                    expanded ? 'text-text' : 'text-text-muted hover:bg-surface-2 hover:text-text',
                )}
            >
                <item.icon size={18} strokeWidth={2.1} className={cn(expanded ? '' : 'text-text-faint group-hover:text-text')} />
                <span className="flex-1">{item.label}</span>
                <ChevronDown size={14} className={cn('text-text-faint transition-transform', !expanded && '-rotate-90')} />
            </NavLink>

            {expanded && (
                <div className="ml-4 mt-0.5 space-y-0.5 border-l border-border pl-3">
                    {item.children?.map((child) => <NavItemLink key={child.to} item={child} indent onNavigate={onNavigate} />)}
                </div>
            )}
        </div>
    );
}

function Sidebar({ onNavigate }: { onNavigate?: () => void }) {
    const { can } = useAuth();
    const location = useLocation();
    const groups = useMemo(
        () =>
            NAV.map((g) => ({
                ...g,
                items: g.items
                    .filter((i) => !i.permission || can(i.permission))
                    .map((i) => ({ ...i, children: i.children?.filter((c) => !c.permission || can(c.permission)) })),
            })).filter((g) => g.items.length > 0),
        [can],
    );

    return (
        <div className="flex h-full flex-col">
            <div className="flex h-16 items-center gap-2.5 px-5">
                <div className="grid h-9 w-9 place-items-center rounded-xl bg-accent text-accent-fg shadow-[var(--shadow-soft)]">
                    <Ticket size={20} strokeWidth={2.4} />
                </div>
                <div className="leading-tight">
                    <div className="font-display text-[15px] font-extrabold tracking-tight text-text">Decent Tickets</div>
                    <div className="text-[10.5px] font-medium uppercase tracking-[0.14em] text-text-faint">Admin console</div>
                </div>
            </div>

            <nav className="flex-1 space-y-5 overflow-y-auto px-3 pb-6 pt-2">
                {groups.map((group, gi) => (
                    <div key={gi}>
                        {group.heading && (
                            <div className="px-3 pb-1.5 text-[10.5px] font-semibold uppercase tracking-[0.12em] text-text-faint">
                                {group.heading}
                            </div>
                        )}
                        <div className="space-y-0.5">
                            {group.items.map((it) =>
                                it.children && it.children.length > 0 ? (
                                    <NavGroupItem key={it.to} item={it} pathname={location.pathname} onNavigate={onNavigate} />
                                ) : (
                                    <NavItemLink key={it.to} item={it} onNavigate={onNavigate} />
                                ),
                            )}
                        </div>
                    </div>
                ))}
            </nav>

            <UserFooter />
        </div>
    );
}

function initials(name: string) {
    return name.split(' ').map((p) => p[0]).slice(0, 2).join('').toUpperCase();
}

function UserFooter() {
    const { session, logout } = useAuth();
    return (
        <div className="border-t border-border px-3 py-3">
            <div className="flex items-center gap-3 rounded-xl px-2 py-2">
                <div className="grid h-9 w-9 place-items-center rounded-lg bg-brand-100 text-[12px] font-bold text-brand-700 dark:bg-brand-500/15 dark:text-brand-300">
                    {session ? initials(session.name) : '—'}
                </div>
                <div className="min-w-0 flex-1 leading-tight">
                    <div className="truncate text-[13px] font-semibold text-text">{session?.name ?? 'Staff'}</div>
                    <div className="truncate text-[11.5px] text-text-faint">{session?.roles.join(', ') ?? 'Member'}</div>
                </div>
                <IconButton aria-label="Sign out" onClick={() => void logout()}>
                    <LogOut size={17} />
                </IconButton>
            </div>
        </div>
    );
}

function Topbar({ onMenu }: { onMenu: () => void }) {
    const { theme, toggle } = useTheme();
    const location = useLocation();

    return (
        <header className="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-border bg-bg/80 px-4 backdrop-blur-md sm:px-6">
            <IconButton className="lg:hidden" aria-label="Open menu" onClick={onMenu}>
                <Menu size={20} />
            </IconButton>

            <div className="hidden items-center gap-1.5 text-[13px] text-text-muted sm:flex">
                <span>Dashboard</span>
                <ChevronRight size={14} className="text-text-faint" />
                <span className="font-semibold text-text">{breadcrumbLabel(location.pathname)}</span>
            </div>

            <div className="ml-auto flex items-center gap-2">
                <div className="hidden items-center gap-2 rounded-xl border border-border bg-surface px-3 py-2 md:flex md:w-64">
                    <Search size={16} className="text-text-faint" />
                    <input
                        placeholder="Search…"
                        className="w-full bg-transparent text-[13px] text-text outline-none placeholder:text-text-faint"
                    />
                </div>
                <IconButton aria-label="Notifications" className="relative">
                    <BellRing size={19} />
                </IconButton>
                <IconButton aria-label="Toggle theme" onClick={toggle}>
                    {theme === 'dark' ? <Sun size={19} /> : <Moon size={19} />}
                </IconButton>
            </div>
        </header>
    );
}

export function DashboardLayout({ children }: { children: ReactNode }) {
    const [mobileOpen, setMobileOpen] = useState(false);
    return (
        <div className="min-h-screen bg-bg">
            <aside className="fixed inset-y-0 left-0 z-30 hidden w-64 border-r border-border bg-surface lg:block">
                <Sidebar />
            </aside>

            {mobileOpen && (
                <div className="fixed inset-0 z-40 lg:hidden">
                    <div className="absolute inset-0 bg-black/50 backdrop-blur-sm" onClick={() => setMobileOpen(false)} />
                    <aside className="absolute inset-y-0 left-0 w-72 border-r border-border bg-surface">
                        <IconButton className="absolute right-3 top-4" aria-label="Close" onClick={() => setMobileOpen(false)}>
                            <X size={20} />
                        </IconButton>
                        <Sidebar onNavigate={() => setMobileOpen(false)} />
                    </aside>
                </div>
            )}

            <div className="lg:pl-64">
                <Topbar onMenu={() => setMobileOpen(true)} />
                <main className="mx-auto max-w-[1400px] px-4 py-6 sm:px-6 lg:px-8">{children}</main>
            </div>
        </div>
    );
}
