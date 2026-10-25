<?php

namespace App\Support;

use App\Models\CatalogItem;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The one way a name becomes master data: the order form's "+" button and a
 * bill saved with a name typed by hand both come through here, so both find
 * the same entry for the same name.
 */
class CatalogItems
{
    /**
     * The entry already filed under this name, or a new one at the end of the
     * list. Names match ignoring case and the spaces around them; a hidden
     * entry that matches is shown again rather than filed twice.
     *
     * @return array{item: CatalogItem, created: bool}
     */
    public static function findOrCreate(string $storageKey, string $name, ?string $createdBy): array
    {
        $name = trim($name);

        $existing = CatalogItem::query()
            ->where('storage_key', $storageKey)
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($name)])
            ->orderByDesc('active')
            ->first();

        if ($existing) {
            if (! $existing->active) {
                $existing->active = true;
                $existing->save();
            }

            return ['item' => $existing, 'created' => false];
        }

        // The (storage_key, item_id) pair is unique-constrained at the DB
        // level, so a race between two concurrent adds for the same key can't
        // corrupt data — the loser just retries with a fresh next id.
        $attemptsLeft = 3;

        while (true) {
            $attemptsLeft--;

            try {
                $item = DB::transaction(function () use ($storageKey, $name, $createdBy): CatalogItem {
                    $existingRows = CatalogItem::query()
                        ->where('storage_key', $storageKey)
                        ->lockForUpdate();
                    $nextItemId = (int) ($existingRows->clone()->max('item_id') ?? 0) + 1;
                    $lastPosition = (int) ($existingRows->clone()->max('sort_order') ?? 0);

                    return CatalogItem::query()->create([
                        'storage_key' => $storageKey,
                        'item_id' => $nextItemId,
                        'name' => $name,
                        'sort_order' => $lastPosition + 1,
                        'created_by' => $createdBy,
                        'active' => true,
                    ]);
                });

                return ['item' => $item, 'created' => true];
            } catch (QueryException $exception) {
                if ($attemptsLeft <= 0) {
                    throw $exception;
                }
            }
        }
    }
}
