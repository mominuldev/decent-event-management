import { Construction } from 'lucide-react';
import { Card, Badge } from '@/components/ui';

export default function Placeholder({ title, note }: { title: string; note: string }) {
    return (
        <div className="space-y-6">
            <div>
                <h1 className="text-[26px] font-bold tracking-tight text-text">{title}</h1>
                <p className="mt-1 text-[14px] text-text-muted">Part of the Phase 3 admin dashboard build (docs/08 §3.2).</p>
            </div>
            <Card className="grid place-items-center px-6 py-20 text-center">
                <div className="grid h-14 w-14 place-items-center rounded-2xl bg-brand-50 text-accent dark:bg-brand-500/10">
                    <Construction size={26} />
                </div>
                <h2 className="mt-4 text-[18px] font-semibold text-text">{title} is coming soon</h2>
                <p className="mt-1 max-w-sm text-[13.5px] text-text-muted">
                    Shell, navigation, and permissions are live for this module. <span className="font-semibold text-text">{note}</span>
                </p>
                <Badge tone="accent" className="mt-4">Next up</Badge>
            </Card>
        </div>
    );
}
