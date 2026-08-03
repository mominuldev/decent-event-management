import { useEffect, useState, type ReactNode } from 'react';
import { X } from 'lucide-react';
import { cn } from '@/lib/cn';
import { Button, Label, Textarea } from '@/components/ui';

/** Base overlay + panel. Esc and backdrop click both close. */
export function Dialog({
    open,
    onClose,
    title,
    description,
    children,
    className,
}: {
    open: boolean;
    onClose: () => void;
    title: string;
    description?: string;
    children?: ReactNode;
    className?: string;
}) {
    useEffect(() => {
        if (!open) return;
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [open, onClose]);

    if (!open) return null;

    return (
        <div className="fixed inset-0 z-50 grid place-items-center px-4">
            <div className="absolute inset-0 bg-black/50 backdrop-blur-sm" onClick={onClose} />
            <div
                role="dialog"
                aria-modal="true"
                aria-label={title}
                className={cn(
                    'relative w-full max-w-md rounded-2xl border border-border bg-surface p-5 shadow-[var(--shadow-pop)]',
                    className,
                )}
            >
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h2 className="text-[16px] font-semibold text-text">{title}</h2>
                        {description && <p className="mt-1 text-[13px] text-text-muted">{description}</p>}
                    </div>
                    <button
                        aria-label="Close"
                        onClick={onClose}
                        className="grid h-8 w-8 shrink-0 place-items-center rounded-lg text-text-muted hover:bg-surface-2 hover:text-text"
                    >
                        <X size={16} />
                    </button>
                </div>
                <div className="mt-4">{children}</div>
            </div>
        </div>
    );
}

/**
 * Destructive-action confirm with a required typed reason (docs §3.2: "void
 * and reissue require confirmation with a typed reason"; "every destructive
 * action confirms, and states what will happen in plain words").
 */
export function ConfirmDialog({
    open,
    onClose,
    onConfirm,
    title,
    description,
    confirmLabel = 'Confirm',
    tone = 'danger',
    reasonLabel,
    reasonPlaceholder = 'Explain why…',
    minReasonLength = 3,
}: {
    open: boolean;
    onClose: () => void;
    onConfirm: (reason?: string) => Promise<void>;
    title: string;
    description: string;
    confirmLabel?: string;
    tone?: 'danger' | 'primary';
    /** Omit to skip the reason field entirely (a plain yes/no confirm). */
    reasonLabel?: string;
    reasonPlaceholder?: string;
    minReasonLength?: number;
}) {
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (open) {
            setReason('');
            setError(null);
        }
    }, [open]);

    const requiresReason = Boolean(reasonLabel);
    const canConfirm = !requiresReason || reason.trim().length >= minReasonLength;

    async function handleConfirm() {
        setBusy(true);
        setError(null);
        try {
            await onConfirm(requiresReason ? reason.trim() : undefined);
            onClose();
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Something went wrong. Please try again.');
        } finally {
            setBusy(false);
        }
    }

    return (
        <Dialog open={open} onClose={onClose} title={title} description={description}>
            <div className="space-y-4">
                {requiresReason && (
                    <div>
                        <Label htmlFor="confirm-reason">{reasonLabel}</Label>
                        <Textarea
                            id="confirm-reason"
                            rows={3}
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                            placeholder={reasonPlaceholder}
                            autoFocus
                        />
                    </div>
                )}
                {error && <p className="text-[13px] text-critical-fg">{error}</p>}
                <div className="flex justify-end gap-2">
                    <Button variant="outline" size="sm" onClick={onClose} disabled={busy}>
                        Cancel
                    </Button>
                    <Button variant={tone} size="sm" onClick={() => void handleConfirm()} disabled={busy || !canConfirm}>
                        {busy ? 'Working…' : confirmLabel}
                    </Button>
                </div>
            </div>
        </Dialog>
    );
}
