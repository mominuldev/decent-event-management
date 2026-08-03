import { createContext, useCallback, useContext, useState, type ReactNode } from 'react';
import { CheckCircle2, XCircle, X } from 'lucide-react';
import { cn } from '@/lib/cn';

type ToastTone = 'success' | 'critical' | 'info';
interface ToastItem {
    id: number;
    tone: ToastTone;
    message: string;
}

const ToastCtx = createContext<{ push: (tone: ToastTone, message: string) => void } | null>(null);

let seq = 0;

export function ToastProvider({ children }: { children: ReactNode }) {
    const [items, setItems] = useState<ToastItem[]>([]);

    const push = useCallback((tone: ToastTone, message: string) => {
        const id = ++seq;
        setItems((prev) => [...prev, { id, tone, message }]);
        setTimeout(() => setItems((prev) => prev.filter((t) => t.id !== id)), 5000);
    }, []);

    const dismiss = (id: number) => setItems((prev) => prev.filter((t) => t.id !== id));

    return (
        <ToastCtx.Provider value={{ push }}>
            {children}
            <div className="fixed bottom-4 right-4 z-50 flex w-full max-w-sm flex-col gap-2">
                {items.map((t) => (
                    <div
                        key={t.id}
                        role="alert"
                        className={cn(
                            'flex items-start gap-2.5 rounded-xl border px-3.5 py-3 text-[13px] shadow-[var(--shadow-pop)]',
                            t.tone === 'success' && 'border-success-border bg-success-bg text-success-fg',
                            t.tone === 'critical' && 'border-critical-border bg-critical-bg text-critical-fg',
                            t.tone === 'info' && 'border-info-border bg-info-bg text-info-fg',
                        )}
                    >
                        {t.tone === 'success' ? <CheckCircle2 size={18} className="mt-0.5 shrink-0" /> : <XCircle size={18} className="mt-0.5 shrink-0" />}
                        <span className="flex-1">{t.message}</span>
                        <button onClick={() => dismiss(t.id)} aria-label="Dismiss" className="shrink-0 opacity-70 hover:opacity-100">
                            <X size={15} />
                        </button>
                    </div>
                ))}
            </div>
        </ToastCtx.Provider>
    );
}

export function useToast() {
    const ctx = useContext(ToastCtx);
    if (!ctx) throw new Error('useToast must be used within ToastProvider');
    return ctx;
}
