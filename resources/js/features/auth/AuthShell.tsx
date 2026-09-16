import type { CSSProperties, ReactNode } from 'react';
import { Ticket } from 'lucide-react';

/**
 * The shell the signed-out pages share — login, forgot and reset — so the
 * three cannot drift into looking like different products.
 *
 * It is drawn as a ticket, because a ticket is the one object everything in
 * this console exists to issue, sell, deliver and scan: a dark counterfoil on
 * the left carrying the brand and a few true facts about a staff session, a
 * light stub on the right carrying the form, and a dashed perforation with
 * die-cut notches between them. On a phone the tear runs horizontally.
 *
 * The meta rows are facts, not decoration — the 8-hour figure is the staff
 * token TTL every `createToken()` call site passes (docs/06 §6.7).
 */
const SESSION_FACTS: Array<[label: string, value: string]> = [
    ['Holder', 'Staff account'],
    ['Session', '8 hours'],
    ['Access', 'By role'],
];

function stagger(i: number): CSSProperties {
    return { '--i': i } as CSSProperties;
}

export default function AuthShell({ children }: { children: ReactNode }) {
    return (
        <div className="relative grid min-h-screen place-items-center overflow-hidden bg-bg px-4 py-10">
            {/* Ambient — two slow glows in the two brand hues, and a dot field that fades out toward the edges. */}
            <div aria-hidden className="auth-glow auth-glow-brand" />
            <div aria-hidden className="auth-glow auth-glow-secondary" />
            <div aria-hidden className="auth-dots" />

            <div className="auth-card relative w-full max-w-[900px]">
                <div className="grid overflow-hidden rounded-[1.75rem] bg-surface shadow-[var(--shadow-pop)] md:grid-cols-[minmax(0,10fr)_minmax(0,12fr)]">
                    {/* ---- Counterfoil ------------------------------------------------ */}
                    <aside className="auth-counterfoil relative flex flex-col p-6 text-white sm:p-8 md:min-h-[520px] md:p-10 md:pr-14">
                        <div className="auth-stagger relative flex flex-1 flex-col">
                            <div className="flex items-center gap-3" style={stagger(0)}>
                                <div className="grid h-10 w-10 place-items-center rounded-xl bg-secondary text-secondary-fg shadow-[0_8px_20px_-8px_var(--color-secondary-500)]">
                                    <Ticket size={20} strokeWidth={2.4} />
                                </div>
                                <div className="leading-tight">
                                    <div className="font-display text-[15px] font-extrabold tracking-tight">Decent Tickets</div>
                                    <div className="text-[10.5px] font-medium uppercase tracking-[0.16em] text-white/55">Admin console</div>
                                </div>
                            </div>

                            <div className="my-8 md:my-auto md:py-10" style={stagger(1)}>
                                <h2 className="font-display text-[26px] font-extrabold leading-[1.08] tracking-tight md:text-[32px]">
                                    The desk behind
                                    <br />
                                    <span className="text-secondary-400">the gate.</span>
                                </h2>
                                <p className="mt-3 max-w-[30ch] text-[13.5px] leading-relaxed text-white/65">
                                    Registrations, payments, tickets and check-in, in one place.
                                </p>
                            </div>

                            <dl className="hidden grid-cols-3 gap-4 border-t border-white/12 pt-5 md:grid" style={stagger(2)}>
                                {SESSION_FACTS.map(([label, value]) => (
                                    <div key={label}>
                                        <dt className="text-[10px] font-semibold uppercase tracking-[0.18em] text-white/45">{label}</dt>
                                        <dd className="mt-1 text-[13px] font-semibold">{value}</dd>
                                    </div>
                                ))}
                            </dl>
                        </div>

                        {/* The stripe a real ticket prints along its tear line. */}
                        <div
                            aria-hidden
                            className="absolute right-5 top-1/2 hidden -translate-y-1/2 rotate-180 select-none text-[10px] font-semibold uppercase tracking-[0.32em] whitespace-nowrap text-white/30 [writing-mode:vertical-rl] md:block"
                        >
                            Admit one · Staff only
                        </div>

                        {/* Die-cut notches — page-coloured discs straddling the card edge, clipped by the card's overflow. */}
                        <span aria-hidden className="auth-notch bottom-0 left-0 -translate-x-1/2 translate-y-1/2 md:bottom-auto md:left-auto md:right-0 md:top-0 md:translate-x-1/2 md:-translate-y-1/2" />
                        <span aria-hidden className="auth-notch bottom-0 right-0 translate-x-1/2 translate-y-1/2" />
                    </aside>

                    {/* ---- Stub ------------------------------------------------------- */}
                    <section className="relative flex flex-col justify-center p-6 sm:p-8 md:p-10 md:pl-12">
                        {children}
                    </section>
                </div>
            </div>
        </div>
    );
}
