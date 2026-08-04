import { Navigate, useParams } from 'react-router-dom';
import { useAuth } from '@/features/auth/AuthProvider';
import PagesTab from './tabs/PagesTab';
import MenusTab from './tabs/MenusTab';
import SponsorsTab from './tabs/SponsorsTab';
import ScheduleTab from './tabs/ScheduleTab';
import FaqsTab from './tabs/FaqsTab';
import GalleryTab from './tabs/GalleryTab';
import MediaTab from './tabs/MediaTab';

/**
 * Each section is its own sidebar link and URL (`/cms/pages`, `/cms/gallery`,
 * …) rather than an in-page tab — see `resources/js/layouts/DashboardLayout.tsx`'s
 * `Website` nav group, which is the single source of truth for the section
 * list, order and permissions. This map only needs to agree with it on keys.
 */
const SECTIONS = {
    pages: { label: 'Pages', permission: 'content.view_any', Panel: PagesTab },
    menus: { label: 'Navigation', permission: 'content.view_any', Panel: MenusTab },
    sponsors: { label: 'Sponsors', permission: 'content.view_any', Panel: SponsorsTab },
    schedule: { label: 'Schedule', permission: 'content.view_any', Panel: ScheduleTab },
    faqs: { label: 'FAQs', permission: 'content.view_any', Panel: FaqsTab },
    gallery: { label: 'Gallery', permission: 'content.view_any', Panel: GalleryTab },
    media: { label: 'Media', permission: 'content.manage_media', Panel: MediaTab },
} as const;

type SectionKey = keyof typeof SECTIONS;

const ORDER = Object.keys(SECTIONS) as SectionKey[];

function isSectionKey(value: string | undefined): value is SectionKey {
    return !!value && value in SECTIONS;
}

export default function CmsPage() {
    const { can } = useAuth();
    const { section } = useParams<{ section: string }>();

    if (!isSectionKey(section) || !can(SECTIONS[section].permission)) {
        const fallback = ORDER.find((key) => can(SECTIONS[key].permission));

        if (!fallback) {
            return (
                <div className="space-y-6">
                    <p className="text-[13px] text-text-muted">You don't have permission to view site content.</p>
                </div>
            );
        }

        // Either no `:section` matched a known key, or the user can't see
        // this one — send them to the first section they do have access to
        // rather than a dead page.
        return <Navigate to={`/cms/${fallback}`} replace />;
    }

    const { label, Panel } = SECTIONS[section];

    return (
        <div className="space-y-6">
            <div>
                <h1 className="text-[26px] font-bold tracking-tight text-text">{label}</h1>
                <p className="mt-1 text-[14px] text-text-muted">
                    Content the public site renders. Every string has an English and a Bangla half; a blank Bangla
                    value falls back to English.
                </p>
            </div>

            <Panel />
        </div>
    );
}
