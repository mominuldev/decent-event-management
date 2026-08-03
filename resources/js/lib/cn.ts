import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

/** Merge conditional class names, de-duplicating conflicting Tailwind utilities. */
export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

/** Format an integer with thousands separators. */
export function num(n: number) {
    return n.toLocaleString('en-US');
}

/** Format integer paisa as BDT currency — money is always integer paisa, never a float. */
export function money(paisa: number) {
    return '৳' + (paisa / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
