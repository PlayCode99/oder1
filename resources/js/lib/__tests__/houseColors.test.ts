import { describe, expect, it } from 'vitest';

import {
    HOUSE_COLOR_OPTIONS,
    houseColorTheme,
    normalizeHouseColor,
} from '@/lib/houseColors';

describe('a sports-day house colour', () => {
    it('is read only when it is a colour', () => {
        expect(normalizeHouseColor('#dc2626')).toBe('#DC2626');
        expect(normalizeHouseColor('')).toBeNull();
        expect(normalizeHouseColor('red')).toBeNull();
        expect(normalizeHouseColor(undefined)).toBeNull();
    });

    it('keeps its text readable, dark on pale houses and white on dark ones', () => {
        expect(houseColorTheme('#FACC15').text).toBe('#0F172A');
        expect(houseColorTheme('#FFFFFF').text).toBe('#0F172A');
        expect(houseColorTheme('#1D4ED8').text).toBe('#FFFFFF');
        expect(houseColorTheme('#DC2626').text).toBe('#FFFFFF');
    });

    it('gives a white house an edge that shows on white paper', () => {
        expect(houseColorTheme('#FFFFFF').border).not.toBe('#FFFFFF');
    });

    it('offers ten colours, none twice', () => {
        expect(HOUSE_COLOR_OPTIONS).toHaveLength(10);
        expect(
            new Set(HOUSE_COLOR_OPTIONS.map((option) => option.value)).size,
        ).toBe(10);
    });
});
