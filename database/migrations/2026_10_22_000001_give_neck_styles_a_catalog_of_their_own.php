<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * แบบคอ used to read from the collar list (ปก). It now has a list of its own.
 *
 * The new list starts as a copy of the shared one with the same ids, so every
 * bill already saved with a neck style still names the same style. From here
 * the two lists change independently.
 */
return new class extends Migration
{
    private const SHARED = 'jssport.shirt-collars';

    private const NECK_STYLES = 'jssport.shirt-neck-styles';

    public function up(): void
    {
        if (DB::table('catalog_items')->where('storage_key', self::NECK_STYLES)->exists()) {
            return;
        }

        $now = now();
        $rows = DB::table('catalog_items')
            ->where('storage_key', self::SHARED)
            ->orderBy('item_id')
            ->get()
            ->map(fn (object $row): array => [
                'storage_key' => self::NECK_STYLES,
                'item_id' => $row->item_id,
                'name' => $row->name,
                'sort_order' => $row->sort_order,
                'created_by' => $row->created_by,
                'active' => $row->active,
                'created_at' => $row->created_at ?? $now,
                'updated_at' => $now,
            ])
            ->all();

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('catalog_items')->insert($chunk);
        }
    }

    public function down(): void
    {
        DB::table('catalog_items')->where('storage_key', self::NECK_STYLES)->delete();
    }
};
