import { describe, expect, it } from 'vitest';

import {
    artworkPlaceLabel,
    labelArtworkGallery,
} from '@/components/domain/orders/artworkGallery';

describe('where a picture on the bill belongs, in words', () => {
    it('names a table’s sheet', () => {
        expect(artworkPlaceLabel('shirt_kids_short')).toBe(
            'เสื้อเด็ก · แขนสั้น',
        );
        expect(artworkPlaceLabel('shirt_junior_long')).toBe(
            'เสื้อประถม - มัธยมต้น · แขนยาว',
        );
        expect(artworkPlaceLabel('pants_adults_long')).toBe(
            'กางเกงผู้ใหญ่ · ขายาว',
        );
        expect(artworkPlaceLabel('shirt_adults_unspecified')).toBe(
            'เสื้อผู้ใหญ่',
        );
    });

    it('names a colour house by the name the bill gives it', () => {
        expect(artworkPlaceLabel('sports_day_1', ['คณะแดง', 'คณะฟ้า'])).toBe(
            'คณะฟ้า',
        );
        expect(artworkPlaceLabel('sports_day_2', ['คณะแดง'])).toBe('คณะที่ 3');
        expect(artworkPlaceLabel('sports_day_0_shirt_kids', ['คณะแดง'])).toBe(
            'คณะแดง · เสื้อเด็ก',
        );
    });

    it('says when a picture is for every sheet', () => {
        expect(artworkPlaceLabel('shirt')).toBe('ทุกใบงานเสื้อ');
        expect(artworkPlaceLabel('pants')).toBe('ทุกใบงานกางเกง');
        expect(artworkPlaceLabel('all')).toBe('ทั้งบิล');
        expect(artworkPlaceLabel('pe_kids')).toBe('ตารางเด็ก');
    });
});

describe('the pictures the overview shows', () => {
    it('shows each picture once, with every sheet it is on', () => {
        expect(
            labelArtworkGallery(
                [
                    {
                        url: '/a.webp',
                        places: ['shirt_kids_short', 'shirt_adults_short'],
                    },
                ],
                ['/a.webp', '/a-copy.webp'],
            ),
        ).toEqual([
            {
                url: '/a.webp',
                labels: ['เสื้อเด็ก · แขนสั้น', 'เสื้อผู้ใหญ่ · แขนสั้น'],
            },
        ]);
    });

    it('falls back to the plain list, without repeats, when there is no gallery', () => {
        expect(
            labelArtworkGallery(undefined, ['/a.webp', null, '/a.webp', '']),
        ).toEqual([{ url: '/a.webp', labels: [] }]);
    });
});
