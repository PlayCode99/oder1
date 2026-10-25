import { describe, expect, it } from 'vitest';

import { billDateText } from '@/pages/Counter';

describe('a bill date on the counter', () => {
    it('reads day/month/Buddhist year, with the time when asked', () => {
        expect(billDateText('2026-10-22 11:06:03', true)).toBe(
            '22/10/2569 11:06 น.',
        );
        expect(billDateText('2026-10-31 00:00:00')).toBe('31/10/2569');
    });

    it('keeps the shop’s own day for a late-evening bill', () => {
        expect(billDateText('2026-10-04T23:35:00.000000Z', true)).toBe(
            '04/10/2569 23:35 น.',
        );
    });

    it('says nothing when there is no date', () => {
        expect(billDateText(null)).toBe('-');
        expect(billDateText('')).toBe('-');
    });
});
