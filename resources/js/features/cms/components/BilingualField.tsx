import { Input, Label, Textarea } from '@/components/ui';
import { cn } from '@/lib/cn';

export type EditLocale = 'en' | 'bn';

export interface BilingualValue {
    en: string;
    bn: string;
}

/**
 * One editable string, in whichever language the editor is currently working
 * in.
 *
 * The CMS stores every string as a `field`/`field_bn` pair rather than a
 * translations table, and the public API falls back per field to English when
 * the Bangla half is blank (ContentLocale::pick). So a half-translated page is
 * a normal, supported state — this surfaces that rather than nagging about it,
 * and the inactive half is carried through untouched on every keystroke.
 */
export function BilingualField({
    label,
    locale,
    value,
    onChange,
    multiline = false,
    rows = 3,
    placeholder,
    help,
    id,
}: {
    label: string;
    locale: EditLocale;
    value: BilingualValue;
    onChange: (next: BilingualValue) => void;
    multiline?: boolean;
    rows?: number;
    placeholder?: string;
    help?: string;
    id?: string;
}) {
    const active = locale === 'bn' ? value.bn : value.en;
    const fallingBack = locale === 'bn' && value.bn.trim() === '' && value.en.trim() !== '';

    const set = (next: string) => onChange(locale === 'bn' ? { ...value, bn: next } : { ...value, en: next });

    const control = multiline ? (
        <Textarea id={id} rows={rows} value={active} placeholder={placeholder} onChange={(e) => set(e.target.value)} />
    ) : (
        <Input id={id} value={active} placeholder={placeholder} onChange={(e) => set(e.target.value)} />
    );

    return (
        <div>
            <div className="flex items-baseline justify-between">
                <Label htmlFor={id}>{label}</Label>
                <span
                    className={cn(
                        'mb-1.5 rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide',
                        locale === 'bn' ? 'bg-info-bg text-info-fg' : 'bg-surface-2 text-text-faint',
                    )}
                >
                    {locale === 'bn' ? 'বাংলা' : 'English'}
                </span>
            </div>
            {control}
            {fallingBack && (
                <p className="mt-1 text-[11.5px] text-text-faint">
                    Empty — the public site will show the English text here.
                </p>
            )}
            {help && !fallingBack && <p className="mt-1 text-[11.5px] text-text-faint">{help}</p>}
        </div>
    );
}

/** The EN / বাংলা switch that drives every {@link BilingualField} on a form. */
export function LocaleToggle({ locale, onChange }: { locale: EditLocale; onChange: (next: EditLocale) => void }) {
    return (
        <div className="flex rounded-lg border border-border bg-surface p-0.5">
            {(['en', 'bn'] as const).map((l) => (
                <button
                    key={l}
                    type="button"
                    onClick={() => onChange(l)}
                    className={cn(
                        'rounded-md px-2.5 py-1 text-[12px] font-semibold transition-colors',
                        locale === l ? 'bg-accent text-accent-fg' : 'text-text-muted hover:text-text',
                    )}
                >
                    {l === 'en' ? 'English' : 'বাংলা'}
                </button>
            ))}
        </div>
    );
}
