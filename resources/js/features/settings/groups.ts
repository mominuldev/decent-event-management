import { Bell, CalendarDays, CreditCard, Palette, ScanLine, Settings2, UserPlus, type LucideIcon } from 'lucide-react';
import { titleCase } from '@/lib/format';

interface GroupMeta {
    label: string;
    description: string;
    Icon: LucideIcon;
}

/**
 * Friendly names for the `group` column seeded by `EventSettingSeeder`. A
 * group that isn't listed here still renders — it falls back to a title-cased
 * version of its own key — so adding a setting to a new group never needs a
 * change here first.
 */
const GROUP_META: Record<string, GroupMeta> = {
    event: {
        label: 'Event',
        description: 'Name, date and venue. These appear on the public website and on every ticket.',
        Icon: CalendarDays,
    },
    registration: {
        label: 'Registration',
        description: 'When the public can register, until when they can edit, and how large a family may be.',
        Icon: UserPlus,
    },
    payment: {
        label: 'Payments',
        description: 'How long a checkout holds seats, whether manual payments are accepted, and the refund cutoff.',
        Icon: CreditCard,
    },
    checkin: {
        label: 'Check-in',
        description: 'The gate window, override policy and the QR key volunteers’ devices verify against.',
        Icon: ScanLine,
    },
    notification: {
        label: 'Notifications',
        description: 'Per-channel kill switches. Turning one off stops sends already queued, not just new ones.',
        Icon: Bell,
    },
    branding: {
        label: 'Branding',
        description: 'Logos, colours and copy shown across the public site.',
        Icon: Palette,
    },
};

/** Sections render in this order; anything unlisted follows, alphabetically. */
const GROUP_ORDER = ['event', 'registration', 'payment', 'checkin', 'notification', 'branding'];

export function groupMeta(group: string): GroupMeta {
    return GROUP_META[group] ?? { label: titleCase(group), description: '', Icon: Settings2 };
}

export function sortGroups(groups: string[]): string[] {
    return [...groups].sort((a, b) => {
        const ai = GROUP_ORDER.indexOf(a);
        const bi = GROUP_ORDER.indexOf(b);
        if (ai === -1 && bi === -1) return a.localeCompare(b);
        if (ai === -1) return 1;
        if (bi === -1) return -1;
        return ai - bi;
    });
}
