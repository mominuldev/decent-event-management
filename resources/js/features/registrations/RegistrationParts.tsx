import { Check, X } from 'lucide-react';
import { Badge, type Tone } from '@/components/ui';
import { cn, money } from '@/lib/cn';
import type { Registration, RegistrationStatus } from './types';

/** Display pieces shared by the registrations list and a registration's own page. */

export const statusTone: Record<RegistrationStatus, Tone> = {
    draft: 'neutral',
    pending_payment: 'warning',
    pending_approval: 'warning',
    approved: 'success',
    confirmed: 'success',
    rejected: 'critical',
    cancelled: 'critical',
};

export function titleCase(s: string) {
    return s.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

const TIMELINE_STEPS: RegistrationStatus[] = ['draft', 'pending_payment', 'pending_approval', 'approved', 'confirmed'];

export function StatusTimeline({ status }: { status: RegistrationStatus }) {
    const isTerminalNegative = status === 'rejected' || status === 'cancelled';
    const currentIndex = TIMELINE_STEPS.indexOf(status);

    return (
        <div className="flex items-center gap-1.5">
            {TIMELINE_STEPS.map((step, i) => {
                const reached = !isTerminalNegative && currentIndex >= i;
                const isCurrent = !isTerminalNegative && currentIndex === i;
                return (
                    <div key={step} className="flex flex-1 items-center gap-1.5">
                        <div
                            className={cn(
                                'grid h-6 w-6 shrink-0 place-items-center rounded-full text-[11px] font-semibold',
                                reached ? 'bg-accent text-accent-fg' : 'bg-surface-2 text-text-faint',
                                isCurrent && 'ring-2 ring-accent/30',
                            )}
                        >
                            {reached ? <Check size={12} /> : i + 1}
                        </div>
                        <span className={cn('shrink-0 text-[11px]', reached ? 'font-medium text-text' : 'text-text-faint')}>
                            {titleCase(step)}
                        </span>
                        {i < TIMELINE_STEPS.length - 1 && <div className={cn('h-px flex-1', reached ? 'bg-accent' : 'bg-border')} />}
                    </div>
                );
            })}
            {isTerminalNegative && (
                <div className="flex items-center gap-1.5">
                    <div className="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-critical-fg text-white">
                        <X size={12} />
                    </div>
                    <span className="text-[11px] font-medium text-critical-fg">{titleCase(status)}</span>
                </div>
            )}
        </div>
    );
}

/** "2 adults, 1 child, 1 infant (free)" — infants included, because they
 *  occupy an admit at the gate even though they cost nothing. */
export function partySummary(r: Registration): string {
    const parts = [`${r.adults_count} adult${r.adults_count === 1 ? '' : 's'}`];

    if (r.children_count > 0) {
        parts.push(`${r.children_count} child${r.children_count === 1 ? '' : 'ren'}`);
    }
    if (r.infants_count > 0) {
        parts.push(`${r.infants_count} infant${r.infants_count === 1 ? '' : 's'} (free)`);
    }

    return parts.join(', ');
}

interface PriceLine {
    label: string;
    detail?: string;
    amountPaisa: number;
    free?: boolean;
}

interface PriceBreakdown {
    lines: PriceLine[];
    /** Whether the lines add up to the stored subtotal. */
    reconciles: boolean;
}

/**
 * Attributes a registration's stored total to the ticket type's price
 * tiers — a mirror of CreateRegistration's formula, including
 * TicketType::basePriceFor(), which bills a current student their own rate.
 *
 * This is an *explanation* of a total, never a recomputation of it: the
 * stored `subtotal_paisa` is what was charged, and a ticket type's prices
 * can legitimately have moved since (the post-sale price lock only applies
 * once a tier has sold). So the caller renders the stored figure as the
 * total and these lines beside it, and `reconciles` says whether the two
 * agree — a disagreement is surfaced rather than papered over, because the
 * alternative is a breakdown that quietly explains the wrong number.
 *
 * Returns null when the row did not carry prices, so a half-built
 * breakdown never renders.
 */
function priceBreakdown(r: Registration): PriceBreakdown | null {
    const type = r.ticket_type;

    if (
        !type ||
        type.base_price_tk === undefined ||
        type.additional_adult_price_tk === undefined ||
        type.additional_child_price_tk === undefined
    ) {
        return null;
    }

    const isStudent = r.attendee?.participant_type === 'current_student';
    const studentRate = type.current_student_price_tk;
    // Compared against null/undefined rather than checked for truthiness:
    // 0 is a real price (a free student ticket), not an absent rule.
    const onStudentRate = isStudent && studentRate !== null && studentRate !== undefined;
    const basePaisa = onStudentRate ? (studentRate as number) : type.base_price_tk;

    const baseAdmits = type.base_admits ?? 1;
    const extraAdults = Math.max(0, r.adults_count - baseAdmits);

    const lines: PriceLine[] = [
        {
            label: 'Registrant',
            detail: onStudentRate ? 'Current student rate' : 'Standard rate',
            amountPaisa: basePaisa,
        },
    ];

    if (extraAdults > 0) {
        lines.push({
            label: `${extraAdults} extra adult${extraAdults === 1 ? '' : 's'}`,
            detail: `${money(type.additional_adult_price_tk)} each`,
            amountPaisa: extraAdults * type.additional_adult_price_tk,
        });
    }

    if (r.children_count > 0) {
        lines.push({
            label: `${r.children_count} child${r.children_count === 1 ? '' : 'ren'}`,
            detail: `${money(type.additional_child_price_tk)} each`,
            amountPaisa: r.children_count * type.additional_child_price_tk,
        });
    }

    // Priced at zero but listed, so the breakdown accounts for every head
    // the gate will admit rather than appearing to have lost one.
    if (r.infants_count > 0) {
        lines.push({
            label: `${r.infants_count} infant${r.infants_count === 1 ? '' : 's'}`,
            detail: 'Under the free age',
            amountPaisa: 0,
            free: true,
        });
    }

    const sum = lines.reduce((total, line) => total + line.amountPaisa, 0);

    return { lines, reconciles: sum === r.subtotal_paisa };
}

/**
 * What was charged, and why. The stored `subtotal_paisa`/`total_paisa` are
 * the money — the itemised lines only explain them, and say so out loud
 * when they no longer add up (a ticket type repriced after this
 * registration was taken).
 */
export function PriceBreakdownBlock({ registration }: { registration: Registration }) {
    const breakdown = priceBreakdown(registration);

    return (
        <div>
            <div className="mb-1.5 text-[12px] font-semibold uppercase tracking-wide text-text-faint">Price</div>
            <div className="rounded-lg border border-border">
                {breakdown?.lines.map((line, i) => (
                    <div
                        key={`${line.label}-${i}`}
                        className="flex items-baseline justify-between gap-3 border-b border-border px-3 py-1.5 text-[13px]"
                    >
                        <span className="min-w-0">
                            <span className="text-text">{line.label}</span>
                            {line.detail && <span className="ml-1.5 text-[12px] text-text-faint">{line.detail}</span>}
                        </span>
                        {line.free ? (
                            <Badge tone="success">Free</Badge>
                        ) : (
                            <span className="tnum shrink-0 text-text">{money(line.amountPaisa)}</span>
                        )}
                    </div>
                ))}

                {registration.discount_paisa > 0 && (
                    <div className="flex items-baseline justify-between gap-3 border-b border-border px-3 py-1.5 text-[13px]">
                        <span className="text-text">
                            Discount
                            {registration.discount_code && (
                                <span className="ml-1.5 text-[12px] text-text-faint">{registration.discount_code}</span>
                            )}
                        </span>
                        <span className="tnum shrink-0 text-text">−{money(registration.discount_paisa)}</span>
                    </div>
                )}

                <div className="flex items-baseline justify-between gap-3 px-3 py-1.5 text-[13px]">
                    <span className="font-semibold text-text">Total</span>
                    <span className="tnum shrink-0 font-semibold text-text">{money(registration.total_paisa)}</span>
                </div>
            </div>

            {breakdown && !breakdown.reconciles && (
                <p className="mt-1.5 text-[12px] text-warning-fg">
                    These lines no longer add up to the amount charged — the ticket type has been repriced since this
                    registration was taken. {money(registration.total_paisa)} is what applies.
                </p>
            )}
        </div>
    );
}
