import { cloneElement, isValidElement, useEffect, useState } from 'react';
import type { ButtonHTMLAttributes, ComponentPropsWithRef, ReactNode, SelectHTMLAttributes } from 'react';
import { cn } from '@/lib/cn';

/* ---- Card ---------------------------------------------------------------- */
export function Card({ className, children }: { className?: string; children: ReactNode }) {
    return (
        <div className={cn('rounded-2xl border border-border bg-surface shadow-[var(--shadow-card)]', className)}>
            {children}
        </div>
    );
}

export function CardHeader({ title, subtitle, action }: { title: string; subtitle?: string; action?: ReactNode }) {
    return (
        <div className="flex items-start justify-between gap-4 px-5 pt-5">
            <div>
                <h3 className="text-[15px] font-semibold text-text">{title}</h3>
                {subtitle && <p className="mt-0.5 text-[13px] text-text-muted">{subtitle}</p>}
            </div>
            {action}
        </div>
    );
}

/* ---- Badge — status axis is separate from the brand accent --------------- */
export type Tone = 'accent' | 'success' | 'warning' | 'critical' | 'info' | 'neutral';
const toneMap: Record<Tone, string> = {
    accent: 'text-accent bg-brand-50 ring-brand-200 dark:bg-brand-500/10 dark:ring-brand-500/25',
    success: 'text-success-fg bg-success-bg ring-success-border',
    warning: 'text-warning-fg bg-warning-bg ring-warning-border',
    critical: 'text-critical-fg bg-critical-bg ring-critical-border',
    info: 'text-info-fg bg-info-bg ring-info-border',
    neutral: 'text-text-muted bg-surface-2 ring-border',
};

export function Badge({
    tone = 'neutral',
    size = 'md',
    children,
    className,
}: {
    tone?: Tone;
    size?: 'sm' | 'md';
    children: ReactNode;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 rounded-full font-medium ring-1 ring-inset',
                size === 'sm' ? 'px-2 py-0 text-[11px]' : 'px-2.5 py-0.5 text-xs',
                toneMap[tone],
                className,
            )}
        >
            {children}
        </span>
    );
}

/* ---- Button ---------------------------------------------------------------- */
interface BtnProps extends ButtonHTMLAttributes<HTMLButtonElement> {
    variant?: 'primary' | 'ghost' | 'outline' | 'danger';
    size?: 'sm' | 'md';
    children: ReactNode;
}
export function Button({ variant = 'primary', size = 'md', className, children, ...rest }: BtnProps) {
    const base = 'inline-flex items-center justify-center gap-2 rounded-xl font-semibold transition-colors disabled:opacity-50 disabled:cursor-not-allowed';
    const sizes = { sm: 'px-2.5 py-1.5 text-xs', md: 'px-3.5 py-2 text-sm' } as const;
    const variants = {
        primary: 'bg-accent text-accent-fg hover:opacity-90 shadow-[var(--shadow-soft)]',
        outline: 'border border-border-strong text-text hover:bg-surface-2',
        ghost: 'text-text-muted hover:bg-surface-2 hover:text-text',
        danger: 'bg-critical-fg text-white hover:opacity-90',
    } as const;
    return (
        <button className={cn(base, sizes[size], variants[variant], className)} {...rest}>
            {children}
        </button>
    );
}

export function IconButton({ className, children, ...rest }: BtnProps) {
    return (
        <button
            className={cn(
                'grid h-9 w-9 place-items-center rounded-xl text-text-muted transition-colors hover:bg-surface-2 hover:text-text',
                className,
            )}
            {...rest}
        >
            {children}
        </button>
    );
}

/* ---- Input ----------------------------------------------------------------- */
/** `ComponentPropsWithRef` so callers can focus the field — React 19 passes `ref` through the spread. */
export function Input({ className, ...rest }: ComponentPropsWithRef<'input'>) {
    return (
        <input
            className={cn(
                'w-full rounded-xl border border-border bg-surface px-3 py-2 text-[13.5px] text-text outline-none placeholder:text-text-faint focus:border-accent',
                className,
            )}
            {...rest}
        />
    );
}

export function Select({ className, children, ...rest }: SelectHTMLAttributes<HTMLSelectElement>) {
    return (
        <select
            className={cn(
                'w-full rounded-xl border border-border bg-surface px-3 py-2 text-[13.5px] text-text outline-none focus:border-accent',
                className,
            )}
            {...rest}
        >
            {children}
        </select>
    );
}

export function Textarea({ className, ...rest }: ComponentPropsWithRef<'textarea'>) {
    return (
        <textarea
            className={cn(
                'w-full rounded-xl border border-border bg-surface px-3 py-2 text-[13.5px] text-text outline-none placeholder:text-text-faint focus:border-accent',
                className,
            )}
            {...rest}
        />
    );
}

/* ---- Switch ---------------------------------------------------------------- */
/**
 * A real `role="switch"` button rather than a styled checkbox, so screen
 * readers announce on/off state and the whole control is one tab stop.
 */
export function Switch({
    checked,
    onChange,
    disabled,
    label,
    className,
}: {
    checked: boolean;
    onChange: (next: boolean) => void;
    disabled?: boolean;
    label: string;
    className?: string;
}) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            aria-label={label}
            disabled={disabled}
            onClick={() => onChange(!checked)}
            className={cn(
                'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors',
                'focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent',
                checked ? 'bg-accent' : 'bg-border-strong',
                disabled && 'cursor-not-allowed opacity-50',
                className,
            )}
        >
            <span
                className={cn(
                    'inline-block h-4.5 w-4.5 rounded-full bg-white shadow transition-transform',
                    checked ? 'translate-x-[1.375rem]' : 'translate-x-1',
                )}
            />
        </button>
    );
}

export function Label({ children, htmlFor }: { children: ReactNode; htmlFor?: string }) {
    return (
        <label htmlFor={htmlFor} className="mb-1.5 block text-[12.5px] font-semibold text-text">
            {children}
        </label>
    );
}

/* ---- Field -------------------------------------------------------------------- */
/**
 * Label, control, and the one line underneath that is either a hint or an
 * error — never both at once, because a field showing "Max 150 characters"
 * next to "This name is too long" makes the reader work out which one is
 * telling them what to do.
 *
 * The error wins when present, and it is wired to the control through
 * `aria-describedby`, so a screen reader reaches it without hunting.
 */
export function Field({
    id,
    label,
    hint,
    error,
    optional,
    className,
    children,
}: {
    id: string;
    label: string;
    hint?: string;
    error?: string;
    /** Marks the field as skippable. Required is the default and goes unmarked. */
    optional?: boolean;
    className?: string;
    children: ReactNode;
}) {
    const message = error ?? hint;
    const messageId = `${id}-message`;

    // Cloned rather than asking all eleven call sites to repeat an
    // aria-describedby that must match a string this component owns.
    const control =
        isValidElement<{ 'aria-describedby'?: string }>(children) && message
            ? cloneElement(children, { 'aria-describedby': messageId })
            : children;

    return (
        <div className={className}>
            <label htmlFor={id} className="mb-1.5 flex items-baseline gap-2 text-[12.5px] font-semibold text-text">
                {label}
                {optional && <span className="font-normal text-text-faint">Optional</span>}
            </label>
            {control}
            {message && (
                <p
                    id={messageId}
                    // Announced when a save comes back rejected, since the
                    // message appears without the reader having moved focus.
                    aria-live={error ? 'polite' : undefined}
                    className={cn('mt-1.5 text-[12px]', error ? 'font-medium text-critical-fg' : 'text-text-faint')}
                >
                    {message}
                </p>
            )}
        </div>
    );
}

/* ---- FormSection -------------------------------------------------------------- */
/**
 * A titled group of fields. The grouping is the navigation: a form of a dozen
 * controls in one undifferentiated grid gives the reader nothing to scan by,
 * and no way to tell which fields belong to the same idea.
 */
export function FormSection({
    title,
    description,
    children,
}: {
    title: string;
    description?: string;
    children: ReactNode;
}) {
    return (
        <section className="space-y-3">
            <div>
                <h3 className="text-[12px] font-semibold uppercase tracking-wider text-text-faint">{title}</h3>
                {description && <p className="mt-0.5 text-[12.5px] text-text-muted">{description}</p>}
            </div>
            {children}
        </section>
    );
}

/* ---- DetailRow ---------------------------------------------------------------- */
/** A read-only label/value pair. Renders an em dash rather than nothing, so a blank field still reads as "we don't know" instead of a layout gap. */
export function DetailRow({ label, value }: { label: string; value: ReactNode }) {
    const empty = value === null || value === undefined || value === '' || value === false;

    return (
        <div className="flex items-baseline justify-between gap-4 py-1.5">
            <dt className="shrink-0 text-[12.5px] text-text-muted">{label}</dt>
            <dd className={cn('min-w-0 truncate text-right text-[13px]', empty ? 'text-text-faint' : 'text-text')}>
                {empty ? '—' : value}
            </dd>
        </div>
    );
}

/* ---- Avatar ------------------------------------------------------------------ */
function initialsOf(name: string) {
    return (
        name
            .split(/\s+/)
            .filter(Boolean)
            .map((part) => [...part][0] ?? '')
            .slice(0, 2)
            .join('')
            .toUpperCase() || '—'
    );
}

/**
 * Photo with an initials fallback. `src` is a short-TTL signed URL
 * (`MediaFile::temporarySignedUrl()`, 15 min) served from our own origin, so
 * one left open past its expiry starts 403ing — `onError` drops back to the
 * initials rather than leaving a broken-image glyph in the row.
 */
export function Avatar({
    src,
    name,
    size = 32,
    className,
}: {
    src?: string | null;
    name: string;
    size?: number;
    className?: string;
}) {
    const [failed, setFailed] = useState(false);

    // A new URL (re-fetch, different row) deserves a fresh attempt.
    useEffect(() => setFailed(false), [src]);

    const box = cn('shrink-0 overflow-hidden rounded-full', className);

    if (src && !failed) {
        return (
            <img
                src={src}
                alt=""
                width={size}
                height={size}
                loading="lazy"
                onError={() => setFailed(true)}
                className={cn(box, 'object-cover ring-1 ring-inset ring-border')}
                style={{ width: size, height: size }}
            />
        );
    }

    return (
        <div
            aria-hidden
            className={cn(
                box,
                'grid place-items-center bg-brand-100 font-bold text-brand-700 dark:bg-brand-500/15 dark:text-brand-300',
            )}
            style={{ width: size, height: size, fontSize: Math.max(10, Math.round(size * 0.36)) }}
        >
            {initialsOf(name)}
        </div>
    );
}

/* ---- Skeleton --------------------------------------------------------------- */
export function Skeleton({ className }: { className?: string }) {
    return <div className={cn('animate-pulse rounded-lg bg-surface-2', className)} />;
}

/* ---- Empty / error states ---------------------------------------------------- */
export function EmptyState({ icon, title, description }: { icon?: ReactNode; title: string; description?: string }) {
    return (
        <div className="grid place-items-center px-6 py-16 text-center">
            {icon && <div className="mb-3 grid h-12 w-12 place-items-center rounded-2xl bg-surface-2 text-text-faint">{icon}</div>}
            <h3 className="text-[14px] font-semibold text-text">{title}</h3>
            {description && <p className="mt-1 max-w-sm text-[13px] text-text-muted">{description}</p>}
        </div>
    );
}

export function ErrorState({ message, onRetry }: { message: string; onRetry?: () => void }) {
    return (
        <div className="grid place-items-center px-6 py-16 text-center">
            <div className="mb-3 grid h-12 w-12 place-items-center rounded-2xl bg-critical-bg text-critical-fg">!</div>
            <h3 className="text-[14px] font-semibold text-text">Something went wrong</h3>
            <p className="mt-1 max-w-sm text-[13px] text-text-muted">{message}</p>
            {onRetry && (
                <Button variant="outline" size="sm" className="mt-4" onClick={onRetry}>
                    Try again
                </Button>
            )}
        </div>
    );
}
