import { describe, expect, it } from 'vitest';

import { sharedArtworkUrl } from '@/components/domain/production/ProductionBoardPage';

describe('the picture a sheet with none of its own may show', () => {
    it('is never a picture pinned to another sheet', () => {
        expect(
            sharedArtworkUrl(
                {
                    artwork_url: null,
                    pants_artwork_url: '/junior.webp',
                    pants_artwork_media: [
                        {
                            id: 29,
                            url: '/junior.webp',
                            batch: 'pants_junior_long',
                        },
                    ],
                },
                'pants',
            ),
        ).toBeNull();
    });

    it('is a picture meant for every sheet of the garment', () => {
        expect(
            sharedArtworkUrl(
                {
                    shirt_artwork_media: [
                        { id: 1, url: '/kids.webp', batch: 'shirt_kids_short' },
                        { id: 2, url: '/all.webp', batch: null },
                    ],
                },
                'shirt',
            ),
        ).toBe('/all.webp');
    });

    it('is the bill’s general artwork when nothing else fits', () => {
        expect(
            sharedArtworkUrl(
                { artwork_url: '/general.webp', shirt_artwork_media: [] },
                'shirt',
            ),
        ).toBe('/general.webp');
    });

    it('is the first picture on a bill served before pictures were pinned', () => {
        expect(
            sharedArtworkUrl({ shirt_artwork_url: '/old.webp' }, 'shirt'),
        ).toBe('/old.webp');
    });
});
