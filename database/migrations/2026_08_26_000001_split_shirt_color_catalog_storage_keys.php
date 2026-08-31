<?php

use App\Models\CatalogItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Historically every color field on the order form (fabric color, neck
     * color, placket outer/inner color, screen color, embroidery color —
     * for both shirt and pants) shared the single storage_key
     * 'jssport.shirt-colors'. That meant adding a color anywhere made it
     * appear in every color dropdown, regardless of what kind of color it
     * actually was.
     *
     * This migration splits that single bucket into distinct storage_keys,
     * one per color concept, while copying (not moving) the existing rows
     * into every new key. Copying rather than moving keeps every order
     * already saved with a color's item_id resolving to the same name it
     * always did — going forward, new colors added from any one field only
     * land in that field's own key.
     */
    private const SOURCE_KEY = 'jssport.shirt-colors';

    /**
     * @var array<int, string>
     */
    private const TARGET_KEYS = [
        'jssport.shirt-fabric-colors',
        'jssport.shirt-neck-colors',
        'jssport.shirt-placket-outer-colors',
        'jssport.shirt-placket-inner-colors',
        'jssport.shirt-screen-colors',
        'jssport.shirt-embroidery-colors',
    ];

    public function up(): void
    {
        $sourceRows = CatalogItem::query()
            ->where('storage_key', self::SOURCE_KEY)
            ->get(['item_id', 'name', 'created_by', 'active', 'created_at', 'updated_at']);

        if ($sourceRows->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($sourceRows): void {
            foreach (self::TARGET_KEYS as $targetKey) {
                foreach ($sourceRows as $row) {
                    CatalogItem::query()->updateOrCreate(
                        [
                            'storage_key' => $targetKey,
                            'item_id' => $row->item_id,
                        ],
                        [
                            'name' => $row->name,
                            'created_by' => $row->created_by,
                            'active' => $row->active,
                            'created_at' => $row->created_at,
                            'updated_at' => $row->updated_at,
                        ],
                    );
                }
            }
        });
    }

    /**
     * Removes only the copies this migration created. The original
     * 'jssport.shirt-colors' rows are left untouched.
     */
    public function down(): void
    {
        CatalogItem::query()
            ->whereIn('storage_key', self::TARGET_KEYS)
            ->delete();
    }
};
