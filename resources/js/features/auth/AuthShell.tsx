import type { ReactNode } from 'react';
import { Ticket } from 'lucide-react';
import { Card } from '@/components/ui';

/**
 * The masthead and centred card the signed-out pages share. Extracted when
 * forgot/reset joined login rather than copied a third time, so the three
 * cannot drift into looking like different products.
 */
export default function AuthShell({ children }: { children: ReactNode }) {
    return (
        <div className="grid min-h-screen place-items-center bg-bg px-4">
            <div className="w-full max-w-sm">
                <div className="mb-8 flex flex-col items-center gap-3">
                    <div className="grid h-12 w-12 place-items-center rounded-2xl bg-accent text-accent-fg shadow-[var(--shadow-soft)]">
                        <Ticket size={22} strokeWidth={2.2} />
                    </div>
                    <div className="text-center">
                        <div className="font-display text-[18px] font-bold tracking-tight text-text">Decent Ticket Management</div>
                        <div className="text-[12.5px] text-text-faint">Admin console</div>
                    </div>
                </div>

                <Card className="p-6">{children}</Card>
            </div>
        </div>
    );
}
