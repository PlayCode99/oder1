<?php

namespace App\Support\Orders;

use App\Http\Controllers\OrderController;
use App\Support\CatalogItems;

/**
 * A spec field the counter typed rather than picked is a name, not a catalog
 * id. When the bill is saved, each such name is filed as master data — through
 * the same lookup the form's "+" button uses — and the bill is written with
 * the entry's id, so the next bill can pick it from the list.
 *
 * This runs inside the bill's own transaction: a bill that fails to save
 * leaves no new entries behind.
 */
class TypedSpecMasterData
{
    /**
     * Which catalog each spec field picks from, as the order form maps them.
     *
     * @var array<string, string>
     */
    private const FIELD_SOURCE = [
        'pattern_id' => 'patterns',
        'fabric_id' => 'fabrics',
        'fabric_color_id' => 'fabric_colors',
        'neck_style_id' => 'neck_styles',
        'neck_color_id' => 'neck_colors',
        'collar_id' => 'collars',
        'placket_style_id' => 'placket_styles',
        'placket_outer_color_id' => 'placket_outer_colors',
        'placket_inner_color_id' => 'placket_inner_colors',
        'sleeve_cuff_id' => 'sleeve_cuffs',
        'panel_style_id' => 'panel_styles',
        'screen_color_id' => 'screen_colors',
        'embroidery_color_id' => 'embroidery_colors',
        'sublimation_id' => 'sublimations',
        'leg_style_id' => 'leg_styles',
        'leg_cuff_id' => 'leg_cuffs',
    ];

    /** @var array<string, array{id: string, name: string}> */
    private array $resolved = [];

    public function __construct(private readonly ?string $createdBy) {}

    /**
     * The submitted specification with every typed name replaced by its
     * entry's id, and that name recorded as the label the bill was saved with.
     *
     * @param  array<string, mixed>  $specification
     * @return array<string, mixed>
     */
    public function apply(array $specification): array
    {
        $raw = $specification['screen_print_detail'] ?? null;
        $detail = is_string($raw) ? json_decode($raw, true) : null;

        if (is_array($detail)) {
            $before = count($this->resolved);
            $applied = $this->applyToDetail($detail);

            // Written back only when a name was filed: decoding and encoding
            // again would turn an empty `{}` into `[]` for nothing.
            if (count($this->resolved) !== $before || $applied !== $detail) {
                $specification['screen_print_detail'] = json_encode($applied, JSON_UNESCAPED_UNICODE);
            }
        }

        $this->applyToFlatColumns($specification, is_array($detail) ? $detail : []);

        return $specification;
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function applyToDetail(array $detail): array
    {
        foreach (['shirt', 'pants'] as $garment) {
            $specsKey = "{$garment}_specs";

            if (is_array($detail[$specsKey] ?? null)) {
                $labels = $detail['spec_labels'][$garment] ?? [];
                [$detail[$specsKey], $labels] = $this->applyToSpecs($detail[$specsKey], $garment, is_array($labels) ? $labels : []);

                if ($labels !== []) {
                    $detail['spec_labels'][$garment] = $labels;
                }
            }
        }

        // One spec per sheet, keyed `{garment}_{tier}_{length}`.
        if (is_array($detail['garment_specs'] ?? null)) {
            foreach ($detail['garment_specs'] as $sheetKey => $specs) {
                if (! is_array($specs)) {
                    continue;
                }

                $garment = str_starts_with((string) $sheetKey, 'pants_') ? 'pants' : 'shirt';
                $labels = $detail['garment_spec_labels'][$sheetKey] ?? [];
                [$detail['garment_specs'][$sheetKey], $labels] = $this->applyToSpecs($specs, $garment, is_array($labels) ? $labels : []);

                if ($labels !== []) {
                    $detail['garment_spec_labels'][$sheetKey] = $labels;
                }
            }
        }

        // Form 3: each house's fabric colour, from the shirt's colour list.
        if (is_array($detail['sports_day_groups'] ?? null)) {
            foreach ($detail['sports_day_groups'] as $index => $group) {
                if (! is_array($group) || ! array_key_exists('fabric_color_id', $group)) {
                    continue;
                }

                $entry = $this->resolve('shirt', 'fabric_colors', $group['fabric_color_id']);

                if ($entry !== null) {
                    $detail['sports_day_groups'][$index]['fabric_color_id'] = $entry['id'];
                }
            }
        }

        return $detail;
    }

    /**
     * @param  array<string, mixed>  $specs
     * @param  array<string, mixed>  $labels
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function applyToSpecs(array $specs, string $garment, array $labels): array
    {
        foreach (self::FIELD_SOURCE as $field => $source) {
            if (! array_key_exists($field, $specs)) {
                continue;
            }

            $entry = $this->resolve($garment, $source, $specs[$field]);

            if ($entry !== null) {
                $specs[$field] = $entry['id'];
                $labels[$field] = $entry['name'];
            }
        }

        return [$specs, $labels];
    }

    /**
     * The three integer columns read the representative spec. A name typed
     * there is filed exactly as the spec's own copy of it was.
     *
     * @param  array<string, mixed>  $specification
     * @param  array<string, mixed>  $detail
     */
    private function applyToFlatColumns(array &$specification, array $detail): void
    {
        foreach (['pattern_id' => 'patterns', 'fabric_id' => 'fabrics', 'neck_style_id' => 'neck_styles'] as $column => $source) {
            if (! array_key_exists($column, $specification)) {
                continue;
            }

            // The flat pattern is the shirt's when the bill sells shirts and
            // the trousers' otherwise; a typed pattern names its own list.
            $garment = 'shirt';

            if ($column === 'pattern_id' && $this->isTyped($specification[$column])) {
                $shirtPattern = $detail['shirt_specs']['pattern_id'] ?? null;
                $pantsPattern = $detail['pants_specs']['pattern_id'] ?? null;
                $typed = trim((string) $specification[$column]);

                if (! $this->matchesTyped($shirtPattern, $typed) && $this->matchesTyped($pantsPattern, $typed)) {
                    $garment = 'pants';
                }
            }

            $entry = $this->resolve($garment, $source, $specification[$column]);

            if ($entry !== null) {
                $specification[$column] = (int) $entry['id'];
            }
        }

        // The text columns hold the picked entry's id as a string, as the
        // form sends it; a typed name is stored the same way, so a bill reads
        // alike whether it was saved once or saved again later.
        $textColumns = [
            'collar_color' => ['shirt', 'neck_colors'],
            'placket_style' => ['shirt', 'placket_styles'],
            'placket_color' => ['shirt', 'placket_outer_colors'],
            'sleeve_hem' => ['shirt', 'sleeve_cuffs'],
            'sublimation_detail' => ['shirt', 'sublimations'],
            'leg_style' => ['pants', 'leg_styles'],
            'leg_hem' => ['pants', 'leg_cuffs'],
        ];

        foreach ($textColumns as $column => [$garment, $source]) {
            if (! array_key_exists($column, $specification)) {
                continue;
            }

            $entry = $this->resolve($garment, $source, $specification[$column]);

            if ($entry !== null) {
                $specification[$column] = $entry['id'];
            }
        }
    }

    /**
     * The entry a typed value stands for; null for a picked id, an empty
     * field, or "-" (nothing), which are written as they came.
     *
     * @return array{id: string, name: string}|null
     */
    private function resolve(string $garment, string $source, mixed $value): ?array
    {
        if (! $this->isTyped($value)) {
            return null;
        }

        $catalogs = $garment === 'pants'
            ? OrderController::PANTS_CATALOG_KEYS
            : OrderController::SHIRT_CATALOG_KEYS;
        $storageKey = $catalogs[$source] ?? null;

        if ($storageKey === null) {
            return null;
        }

        $name = trim((string) $value);
        $memoKey = $storageKey.'|'.mb_strtolower($name);

        if (! isset($this->resolved[$memoKey])) {
            ['item' => $item] = CatalogItems::findOrCreate($storageKey, $name, $this->createdBy);
            $this->resolved[$memoKey] = ['id' => (string) $item->item_id, 'name' => (string) $item->name];
        }

        return $this->resolved[$memoKey];
    }

    private function isTyped(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $text = trim($value);

        return $text !== '' && $text !== '-' && ! ctype_digit($text);
    }

    private function matchesTyped(mixed $value, string $typed): bool
    {
        return is_string($value) && mb_strtolower(trim($value)) === mb_strtolower($typed);
    }
}
