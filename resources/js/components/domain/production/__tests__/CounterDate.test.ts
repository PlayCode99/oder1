import { describe, expect, it } from 'vitest';

import { counterDate } from '@/components/domain/production/ProductionBoardPage';

describe('a date on the job sheet, as the counter writes it', () => {
    it('reads day/month/Buddhist year', () => {
        expect(counterDate('2026-10-04')).toBe('04/10/2569');
        expect(counterDate('2026-10-15 00:00:00')).toBe('15/10/2569');
    });

    it('keeps the shop’s own day for an evening bill', () => {
        // 20:35 on the 4th, stored under a UTC label; read through a
        // Bangkok clock it would become the 5th.
        expect(counterDate('2026-10-04T20:35:23.000000Z')).toBe('04/10/2569');
    });

    it('says nothing when there is no date', () => {
        expect(counterDate(null)).toBe('-');
        expect(counterDate('')).toBe('-');
        expect(counterDate('ไม่ระบุ')).toBe('-');
    });
});
