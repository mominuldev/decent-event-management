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
