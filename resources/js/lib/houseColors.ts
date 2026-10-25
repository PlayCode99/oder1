/**
 * The colours a sports-day house can be painted in, picked on Form 3 and used
 * for that house's tables on the form, the counter, the receipt and the
 * production sheets. A fixed set, so every choice still reads in print.
 */
export type HouseColorOption = { label: string; value: string };

export const HOUSE_COLOR_OPTIONS: HouseColorOption[] = [
    { label: 'แดง', value: '#DC2626' },
    { label: 'เหลือง', value: '#FACC15' },
    { label: 'เขียว', value: '#16A34A' },
    { label: 'ฟ้า', value: '#38BDF8' },
    { label: 'น้ำเงิน', value: '#1D4ED8' },
    { label: 'ชมพู', value: '#EC4899' },
    { label: 'ม่วง', value: '#7C3AED' },
    { label: 'ส้ม', value: '#F97316' },
    { label: 'ขาว', value: '#FFFFFF' },
    { label: 'ดำ', value: '#111827' },
];

/** The chosen colour as #RRGGBB, or null when none (or nothing valid) was picked. */
export function normalizeHouseColor(value: unknown): string | null {
    if (typeof value !== 'string') {
        return null;
    }

    const trimmed = value.trim().toUpperCase();

    return /^#[0-9A-F]{6}$/.test(trimmed) ? trimmed : null;
}

/**
 * Text that reads on the colour. Relative luminance decides it, so a pale
 * house — เหลือง, ขาว — keeps dark text instead of white on white. The cut is
 * 0.5 rather than higher: white on ส้ม or เหลือง measures about 3:1, thin on
 * paper, while dark text on the same ground is about 7:1.
 */
export function readableTextOn(background: string): string {
    const channel = (offset: number): number =>
        Number.parseInt(background.slice(offset, offset + 2), 16) / 255;
    const luminance =
        0.2126 * channel(1) + 0.7152 * channel(3) + 0.0722 * channel(5);

    return luminance > 0.5 ? '#0F172A' : '#FFFFFF';
}

/**
 * A house's colour as a table is painted with it: the ground, readable text,
 * and an edge that still shows against white paper when the house is ขาว.
 */
export function houseColorTheme(color: string): {
    background: string;
    text: string;
    border: string;
} {
    const text = readableTextOn(color);

    return {
        background: color,
        text,
        border: color === '#FFFFFF' ? '#94A3B8' : color,
    };
}
