import { describe, expect, it } from 'vitest';

import { canEditOrderStatus } from '@/pages/Counter';

describe('canEditOrderStatus', () => {
    it('allows editing for all order states', () => {
        expect(canEditOrderStatus({ orderStatus: 'confirmed' })).toBe(true);
        expect(canEditOrderStatus({ orderStatus: ' Confirmed ' })).toBe(true);
        expect(canEditOrderStatus({ orderStatus: 'CONFIRMED' })).toBe(true);
        expect(canEditOrderStatus({ orderStatus: 'waiting_customer_confirm' })).toBe(true);
        expect(canEditOrderStatus({ status: 'design' })).toBe(true);
        expect(canEditOrderStatus({ orderStatus: 'in_production' })).toBe(true);
        expect(canEditOrderStatus(null)).toBe(true);
    });
});
