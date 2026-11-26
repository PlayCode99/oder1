import { describe, expect, it } from 'vitest';

import {
    dropTableArtwork,
    moveTableArtwork,
} from '@/pages/Orders/tableArtwork';

const file = (name: string) => new File(['x'], name);

describe('a table’s pictures follow the table', () => {
    it('take a new length along, new and saved alike', () => {
        const kept = file('kept.png');
        const moving = file('moving.png');
        const result = moveTableArtwork(
            {
                scoped: {
                    shirt_kids_short: [moving],
                    shirt_adults_short: [kept],
                },
                scopes: {},
                saved: [
                    { id: 1, url: '/1', batch: 'shirt_kids_short' },
                    { id: 2, url: '/2', batch: 'shirt_adults_short' },
                    { id: 3, url: '/3', batch: null },
                ],
            },
            'shirt_kids_short',
            'shirt_kids_long',
        );

        expect(result.scoped).toEqual({
            shirt_kids_long: [moving],
            shirt_adults_short: [kept],
        });
        // Only the moved table's saved picture is re-pinned; a picture for
        // every sheet and another table's stay where they are.
        expect(result.scopes).toEqual({ '1': 'shirt_kids_long' });
    });

    it('follows a picture moved on this visit, not where it was saved', () => {
        const result = moveTableArtwork(
            {
                scoped: {},
                scopes: { '2': 'shirt_kids_short' },
                saved: [{ id: 2, url: '/2', batch: 'shirt_adults_short' }],
            },
            'shirt_kids_short',
            'shirt_kids_long',
        );

        expect(result.scopes).toEqual({ '2': 'shirt_kids_long' });
    });

    it('go with a deleted table, leaving pictures meant for every sheet', () => {
        const other = file('other.png');
        const result = dropTableArtwork(
            {
                scoped: {
                    pants_kids_short: [file('gone.png')],
                    pants_adults_long: [other],
                },
                scopes: {},
                saved: [
                    { id: 7, url: '/7', batch: 'pants_kids_short' },
                    { id: 8, url: '/8', batch: null },
                    { id: 9, url: '/9', batch: 'pants_adults_long' },
                ],
            },
            'pants_kids_short',
        );

        expect(result.scoped).toEqual({ pants_adults_long: [other] });
        expect(result.removedIds).toEqual([7]);
    });
});
