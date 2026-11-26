import { savedImageScope } from '@/components/domain/orders/ArtworkBatchDialog';
import type { ArtworkSavedImage } from '@/components/domain/orders/ArtworkBatchDialog';

/**
 * A table's pictures belong to its sheet, and the sheet is named by the table
 * (`{garment}_{tier}_{length}`). These keep the pictures with the table when
 * the table changes: a new length takes them along, and a deleted table takes
 * them away — otherwise they stay pinned to a sheet the bill no longer has,
 * and are printed nowhere.
 */
export type TableArtworkState = {
    /** Files picked on this visit, by the sheet they are for. */
    scoped: Record<string, File[]>;
    /** Saved pictures moved on this visit, by media id. */
    scopes: Record<string, string>;
    /** Pictures the bill already carries for this garment. */
    saved: ArtworkSavedImage[];
};

/** The table now names `to`: its pictures, new and saved, go there too. */
export function moveTableArtwork(
    state: TableArtworkState,
    from: string,
    to: string,
): Pick<TableArtworkState, 'scoped' | 'scopes'> {
    if (from === to) {
        return { scoped: state.scoped, scopes: state.scopes };
    }

    const scoped = { ...state.scoped };
    const moving = scoped[from] ?? [];

    delete scoped[from];

    if (moving.length > 0) {
        scoped[to] = [...(scoped[to] ?? []), ...moving];
    }

    const scopes = { ...state.scopes };

    state.saved.forEach((image) => {
        if (savedImageScope(image, state.scopes) === from) {
            scopes[String(image.id)] = to;
        }
    });

    return { scoped, scopes };
}

/**
 * The table is gone: the files picked for it are dropped, and the saved
 * pictures pinned to it are marked for removal. Pictures meant for every sheet
 * stay — they were never this table's alone.
 */
export function dropTableArtwork(
    state: TableArtworkState,
    key: string,
): { scoped: Record<string, File[]>; removedIds: number[] } {
    const scoped = { ...state.scoped };

    delete scoped[key];

    return {
        scoped,
        removedIds: state.saved
            .filter((image) => savedImageScope(image, state.scopes) === key)
            .map((image) => image.id),
    };
}
