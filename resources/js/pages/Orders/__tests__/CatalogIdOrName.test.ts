import { describe, expect, it } from 'vitest';

import { catalogIdOrName } from '@/pages/Orders/Create';

describe('a spec column as the bill posts it', () => {
    it('is the id of an entry picked from the list', () => {
        expect(catalogIdOrName('2')).toBe(2);
        expect(catalogIdOrName(14)).toBe(14);
    });

    it('is the name itself when it was typed by hand, never NaN', () => {
        expect(catalogIdOrName('คอจีน')).toBe('คอจีน');
        expect(catalogIdOrName('  ผ้าใหม่  ')).toBe('ผ้าใหม่');
    });

    it('is empty when nothing was given', () => {
        expect(catalogIdOrName('')).toBeNull();
        expect(catalogIdOrName('-')).toBeNull();
        expect(catalogIdOrName(null)).toBeNull();
    });
});
