export type BranchHeaderColorOption = {
    label: string;
    value: string;
};

export const DEFAULT_BRANCH_HEADER_COLOR = '#174395';

export const BRANCH_HEADER_COLOR_OPTIONS: BranchHeaderColorOption[] = [
    { label: 'น้ำเงินกรม', value: '#174395' },
    { label: 'แดงเข้ม', value: '#B91C1C' },
    { label: 'เขียวเข้ม', value: '#166534' },
    { label: 'ม่วงเข้ม', value: '#6D28D9' },
    { label: 'ส้มอิฐ', value: '#C2410C' },
    { label: 'เทาเข้ม', value: '#334155' },
    { label: 'ชมพูเข้ม', value: '#BE185D' },
    { label: 'ฟ้าเข้ม', value: '#0369A1' },
];

function isHexColor(value: string): boolean {
    return /^#[0-9A-Fa-f]{6}$/.test(value);
}

export function normalizeBranchHeaderColor(color: unknown): string {
    if (typeof color !== 'string') {
        return DEFAULT_BRANCH_HEADER_COLOR;
    }

    const trimmed = color.trim();

    return isHexColor(trimmed) ? trimmed : DEFAULT_BRANCH_HEADER_COLOR;
}

/**
 * The colour a branch's sheets are headed in: the one chosen for the branch
 * on its settings page, or the default blue when none was. It used to be read
 * from one browser's local storage, so every other machine printed every
 * branch in the default.
 */
export function resolveBranchHeaderColor(
    color: string | null | undefined,
): string {
    return normalizeBranchHeaderColor(color);
}
