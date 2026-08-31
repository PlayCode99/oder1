<?php

declare(strict_types=1);

namespace App\Http\Controllers\Production;

use App\Enums\GarmentCategory;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CatalogItem;
use App\Models\CuttingTeam;
use App\Models\EmbroideryTeam;
use App\Models\GarmentOperation;
use App\Models\GarmentType;
use App\Models\HeatPressMachine;
use App\Models\Order;
use App\Models\PieceworkPrice;
use App\Models\ScreenTeam;
use App\Models\SewingTeam;
use App\Support\UserAccessControl;
use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductionKanbanController extends Controller
{
    /**
     * @var array<string, array<string, string>>|null
     */
    private ?array $catalogLookupCache = null;

    /**
     * @return array<string, array<string, string>>
     */
    private function catalogLookups(): array
    {
        if ($this->catalogLookupCache !== null) {
            return $this->catalogLookupCache;
        }

        $keys = [
            'jssport.shirt-patterns',
            'jssport.shirt-fabrics',
            'jssport.shirt-collars',
            'jssport.shirt-colors',
            'jssport.shirt-fabric-colors',
            'jssport.shirt-neck-colors',
            'jssport.shirt-placket-outer-colors',
            'jssport.shirt-placket-inner-colors',
            'jssport.shirt-screen-colors',
            'jssport.shirt-embroidery-colors',
            'jssport.shirt-plackets',
            'jssport.shirt-cuffs',
            'jssport.shirt-panels',
            'jssport.shirt-sublimation',
            'jssport.pants-patterns',
            'jssport.pants-leg-style',
            'jssport.pants-leg-hem',
        ];

        $grouped = CatalogItem::query()
            ->whereIn('storage_key', $keys)
            ->where('active', true)
            ->get(['storage_key', 'item_id', 'name'])
            ->groupBy('storage_key');

        $lookup = [];

        foreach ($keys as $key) {
            $lookup[$key] = ($grouped[$key] ?? collect())
                ->mapWithKeys(fn (CatalogItem $item): array => [(string) $item->item_id => $item->name])
                ->all();
        }

        $this->catalogLookupCache = $lookup;

        return $lookup;
    }

    private function mapCatalogValue(array $storageKeys, mixed $rawValue): string
    {
        $value = trim((string) $rawValue);

        if ($value === '') {
            return '';
        }

        $lookups = $this->catalogLookups();

        foreach ($storageKeys as $storageKey) {
            $mapped = $lookups[$storageKey][$value] ?? null;

            if (is_string($mapped) && trim($mapped) !== '') {
                return $mapped;
            }
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<int, array{key: string, label: string, type: string, storage_keys?: array<int, string>}>  $definitions
     * @return array<int, array{label: string, value: string}>
     */
    private function buildSpecificationRows(array $values, array $definitions): array
    {
        $rows = [];

        foreach ($definitions as $definition) {
            $raw = $values[$definition['key']] ?? null;

            $value = '';

            if ($definition['type'] === 'catalog') {
                $value = $this->mapCatalogValue($definition['storage_keys'] ?? [], $raw);
            } else {
                $value = trim((string) $raw);
            }

            if ($value === '') {
                continue;
            }

            $rows[] = [
                'label' => $definition['label'],
                'value' => $value,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $specification
     * @return array{shirt: array<int, array{label: string, value: string}>, pants: array<int, array{label: string, value: string}>}
     */
    private function mapSpecificationSections(array $specification): array
    {
        $raw = $specification['screen_print_detail'] ?? null;
        $decoded = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;

        if (is_string($decoded)) {
            $decoded = json_decode($decoded, true);
        }

        $shirtSpecs = is_array($decoded['shirt_specs'] ?? null)
            ? $decoded['shirt_specs']
            : (is_array($decoded['shirtSpecs'] ?? null) ? $decoded['shirtSpecs'] : []);

        $pantsSpecs = is_array($decoded['pants_specs'] ?? null)
            ? $decoded['pants_specs']
            : (is_array($decoded['pantsSpecs'] ?? null) ? $decoded['pantsSpecs'] : []);

        if ($shirtSpecs === [] && $pantsSpecs === []) {
            $shirtSpecs = [
                'pattern_id' => $specification['pattern_id'] ?? null,
                'fabric_id' => $specification['fabric_id'] ?? null,
                'neck_style_id' => $specification['neck_style_id'] ?? null,
                'sleeve_style_text' => $specification['sleeve_style'] ?? null,
                'sleeve_cuff_id' => $specification['sleeve_hem'] ?? null,
                'placket_style_id' => $specification['placket_style'] ?? null,
                'placket_outer_color_id' => $specification['placket_color'] ?? null,
                'sublimation_id' => $specification['sublimation_detail'] ?? null,
                'embroidery_code_text' => $specification['embroidery_code'] ?? null,
            ];

            $pantsSpecs = [
                'pattern_id' => $specification['pattern_id'] ?? null,
                'fabric_id' => $specification['fabric_id'] ?? null,
                'leg_style_id' => $specification['leg_style'] ?? null,
                'leg_cuff_id' => $specification['leg_hem'] ?? null,
                'sublimation_id' => $specification['sublimation_detail'] ?? null,
                'embroidery_code_text' => $specification['embroidery_code'] ?? null,
            ];
        }

        $shirtRows = $this->buildSpecificationRows($shirtSpecs, [
            ['key' => 'pattern_id', 'label' => 'แพทเทิร์น', 'type' => 'catalog', 'storage_keys' => ['jssport.shirt-patterns']],
            ['key' => 'fabric_id', 'label' => 'เนื้อผ้า', 'type' => 'catalog', 'storage_keys' => ['jssport.shirt-fabrics']],
            ['key' => 'fabric_color_id', 'label' => 'สีผ้า', 'type' => 'catalog', 'storage_keys' => ['jssport.shirt-fabric-colors', 'jssport.shirt-colors']],
            ['key' => 'neck_style_id', 'label' => 'แบบคอ', 'type' => 'catalog', 'storage_keys' => ['jssport.shirt-collars']],
            ['key' => 'neck_color_id', 'label' => 'สีแบบคอ', 'type' => 'catalog', 'storage_keys' => ['jssport.shirt-neck-colors', 'jssport.shirt-colors']],
            ['key' => 'collar_id', 'label' => 'ปก', 'type' => 'catalog', 'storage_keys' => ['jssport.shirt-collars']],
            ['key' => 'placket_style_id', 'label' => 'แบบสาบ', 'type' => 'catalog', 'storage_keys' => ['jssport.shirt-plackets']],
            ['key' => 'placket_outer_color_id', 'label' => 'สีสาบ (นอก)', 'type' => 'catalog', 'storage_keys' => ['jssport.shirt-placket-outer-colors', 'jssport.shirt-colors']],
            ['key' => 'placket_inner_color_id', 'label' => 'สีสาบ (ใน)', 'type' => 'catalog', 'storage_keys' => ['jssport.shirt-placket-inner-colors', 'jssport.shirt-colors']],
            ['key' => 'sleeve_cuff_id', 'label' => 'ปลายแขน', 'type' => 'catalog', 'storage_keys' => ['jssport.shirt-cuffs']],
            ['key' => 'panel_style_id', 'label' => 'แบบต่อ', 'type' => 'catalog', 'storage_keys' => ['jssport.shirt-panels']],
            ['key' => 'screen_color_id', 'label' => 'สีสกรีน', 'type' => 'catalog', 'storage_keys' => ['jssport.shirt-screen-colors', 'jssport.shirt-colors']],
            ['key' => 'embroidery_color_id', 'label' => 'สีงานปัก', 'type' => 'catalog', 'storage_keys' => ['jssport.shirt-embroidery-colors', 'jssport.shirt-colors']],
            ['key' => 'sublimation_id', 'label' => 'ซับลิเมชั่น', 'type' => 'catalog', 'storage_keys' => ['jssport.shirt-sublimation']],
            ['key' => 'sleeve_style_text', 'label' => 'แบบแขน', 'type' => 'text'],
            ['key' => 'piping_style_text', 'label' => 'แบบกุ้น', 'type' => 'text'],
            ['key' => 'stripe_style_text', 'label' => 'แบบลา', 'type' => 'text'],
            ['key' => 'screen_text', 'label' => 'ข้อความสกรีน', 'type' => 'text'],
            ['key' => 'embroidery_code_text', 'label' => 'รหัสงานปัก', 'type' => 'text'],
            ['key' => 'embroidery_note_text', 'label' => 'รายละเอียดปัก', 'type' => 'text'],
        ]);

        $pantsRows = $this->buildSpecificationRows($pantsSpecs, [
            ['key' => 'pattern_id', 'label' => 'แพทเทิร์น', 'type' => 'catalog', 'storage_keys' => ['jssport.pants-patterns']],
            ['key' => 'fabric_id', 'label' => 'เนื้อผ้า', 'type' => 'catalog', 'storage_keys' => ['jssport.shirt-fabrics']],
            ['key' => 'fabric_color_id', 'label' => 'สีผ้า', 'type' => 'catalog', 'storage_keys' => ['jssport.shirt-fabric-colors', 'jssport.shirt-colors']],
            ['key' => 'leg_style_id', 'label' => 'แบบขา', 'type' => 'catalog', 'storage_keys' => ['jssport.pants-leg-style']],
            ['key' => 'leg_cuff_id', 'label' => 'ปลายขา', 'type' => 'catalog', 'storage_keys' => ['jssport.pants-leg-hem']],
            ['key' => 'screen_color_id', 'label' => 'สีสกรีน', 'type' => 'catalog', 'storage_keys' => ['jssport.shirt-screen-colors', 'jssport.shirt-colors']],
            ['key' => 'embroidery_color_id', 'label' => 'สีงานปัก', 'type' => 'catalog', 'storage_keys' => ['jssport.shirt-embroidery-colors', 'jssport.shirt-colors']],
            ['key' => 'sublimation_id', 'label' => 'ซับลิเมชั่น', 'type' => 'catalog', 'storage_keys' => ['jssport.shirt-sublimation']],
            ['key' => 'panel_style_text', 'label' => 'แบบต่อ', 'type' => 'text'],
            ['key' => 'stripe_style_text', 'label' => 'แบบลา', 'type' => 'text'],
            ['key' => 'screen_text', 'label' => 'ข้อความสกรีน', 'type' => 'text'],
            ['key' => 'embroidery_code_text', 'label' => 'รหัสงานปัก', 'type' => 'text'],
            ['key' => 'embroidery_note_text', 'label' => 'รายละเอียดปัก', 'type' => 'text'],
        ]);

        return [
            'shirt' => $shirtRows,
            'pants' => $pantsRows,
        ];
    }

    /**
     * @param  array<string, mixed>  $specification
     * @return array<string, mixed>
     */
    private function decodeSpecPayload(array $specification): array
    {
        $raw = $specification['screen_print_detail'] ?? null;

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (is_string($decoded)) {
            $decoded = json_decode($decoded, true);
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  Collection<int, GarmentType>  $garmentTypesById
     */
    private function resolveGarmentTypeByCategory(Order $order, Collection $garmentTypesById, GarmentCategory $category): ?GarmentType
    {
        $payload = $this->decodeSpecPayload($order->specification?->toArray() ?? []);
        $sectionKey = $category === GarmentCategory::Shirt ? 'shirt_specs' : 'pants_specs';
        $typeIdKey = $category === GarmentCategory::Shirt ? 'shirt_type_id' : 'pants_type_id';
        $specs = is_array($payload[$sectionKey] ?? null)
            ? $payload[$sectionKey]
            : (is_array($payload[lcfirst($category->name).'Specs'] ?? null) ? $payload[lcfirst($category->name).'Specs'] : []);

        $typeId = isset($specs[$typeIdKey]) ? (int) $specs[$typeIdKey] : 0;

        if ($typeId > 0) {
            $matchedById = $garmentTypesById->keyBy('id')->get($typeId);

            if ($matchedById instanceof GarmentType) {
                return $matchedById;
            }
        }

        $jobType = trim((string) ($order->job_type ?? ''));

        if ($jobType !== '') {
            $matchedByName = $garmentTypesById
                ->first(fn (GarmentType $type): bool => str_contains(mb_strtolower($type->name), mb_strtolower($jobType))
                    || str_contains(mb_strtolower($jobType), mb_strtolower($type->name)));

            if ($matchedByName instanceof GarmentType) {
                return $matchedByName;
            }
        }

        return $garmentTypesById->first();
    }

    /**
     * @param  Collection<int, GarmentType>  $shirtTypesById
     */
    private function resolveShirtType(Order $order, Collection $shirtTypesById): ?GarmentType
    {
        return $this->resolveGarmentTypeByCategory($order, $shirtTypesById, GarmentCategory::Shirt);
    }

    /**
     * @param  Collection<int, GarmentType>  $pantsTypesById
     */
    private function resolvePantsType(Order $order, Collection $pantsTypesById): ?GarmentType
    {
        return $this->resolveGarmentTypeByCategory($order, $pantsTypesById, GarmentCategory::Pants);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function specSectionHasValues(array $payload): bool
    {
        foreach ($payload as $value) {
            if ($value === null) {
                continue;
            }

            if (is_string($value) && trim($value) === '') {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $specification
     * @return array{shirt: bool, pants: bool}
     */
    private function resolveSpecificationGarmentAvailability(array $specification): array
    {
        $decoded = $this->decodeSpecPayload($specification);
        $shirtSpecs = is_array($decoded['shirt_specs'] ?? null)
            ? $decoded['shirt_specs']
            : (is_array($decoded['shirtSpecs'] ?? null) ? $decoded['shirtSpecs'] : []);
        $pantsSpecs = is_array($decoded['pants_specs'] ?? null)
            ? $decoded['pants_specs']
            : (is_array($decoded['pantsSpecs'] ?? null) ? $decoded['pantsSpecs'] : []);

        $legacyShirt = [
            'pattern_id' => $specification['pattern_id'] ?? null,
            'fabric_id' => $specification['fabric_id'] ?? null,
            'neck_style_id' => $specification['neck_style_id'] ?? null,
            'sleeve_style' => $specification['sleeve_style'] ?? null,
            'sleeve_hem' => $specification['sleeve_hem'] ?? null,
            'placket_style' => $specification['placket_style'] ?? null,
            'placket_color' => $specification['placket_color'] ?? null,
            'sublimation_detail' => $specification['sublimation_detail'] ?? null,
            'embroidery_code' => $specification['embroidery_code'] ?? null,
        ];

        $legacyPants = [
            'leg_style' => $specification['leg_style'] ?? null,
            'leg_hem' => $specification['leg_hem'] ?? null,
        ];

        return [
            'shirt' => $this->specSectionHasValues($shirtSpecs) || $this->specSectionHasValues($legacyShirt),
            'pants' => $this->specSectionHasValues($pantsSpecs) || $this->specSectionHasValues($legacyPants),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function resolvePricingGarmentGroups(string $itemType, bool $hasShirtSpecData, bool $hasPantsSpecData): array
    {
        $normalized = mb_strtolower(trim($itemType));

        if (str_contains($normalized, 'pant') || str_contains($normalized, 'กางเกง')) {
            return ['pants'];
        }

        if (str_contains($normalized, 'shirt') || str_contains($normalized, 'เสื้อ')) {
            return ['shirt'];
        }

        if (in_array($normalized, ['', 'garment', 'set', 'combo'], true)) {
            if ($hasShirtSpecData && $hasPantsSpecData) {
                return ['shirt', 'pants'];
            }

            if ($hasPantsSpecData && ! $hasShirtSpecData) {
                return ['pants'];
            }
        }

        return ['shirt'];
    }

    private function normalizePricingSizeGroup(string $sizeGroup): ?string
    {
        $normalized = mb_strtolower(trim($sizeGroup));

        if ($normalized === 'kids') {
            return 'kids';
        }

        if (in_array($normalized, ['adults', 'oversize'], true)) {
            return 'adults';
        }

        return null;
    }

    /**
     * @return array{shirt_kids: int, shirt_adults: int, pants_kids: int, pants_adults: int}
     */
    private function summarizeOrderQuantitiesByPricingGroup(Order $order): array
    {
        $totals = [
            'shirt_kids' => 0,
            'shirt_adults' => 0,
            'pants_kids' => 0,
            'pants_adults' => 0,
        ];
        $garmentAvailability = $this->resolveSpecificationGarmentAvailability($order->specification?->toArray() ?? []);

        foreach ($order->items ?? collect() as $item) {
            $sizeGroup = $this->normalizePricingSizeGroup((string) ($item->size_group ?? ''));

            if ($sizeGroup === null) {
                continue;
            }

            $garmentGroups = $this->resolvePricingGarmentGroups(
                (string) ($item->item_type ?? ''),
                $garmentAvailability['shirt'],
                $garmentAvailability['pants'],
            );

            foreach ($garmentGroups as $garmentGroup) {
                $key = $garmentGroup.'_'.$sizeGroup;
                $totals[$key] += (int) ($item->quantity ?? 0);
            }
        }

        return $totals;
    }

    /**
     * @param  Collection<string, Collection<int, GarmentType>>  $garmentTypesByCategory
     * @return array<string, mixed>|null
     */
    private function buildProductionPricingSummary(Order $order, Collection $garmentTypesByCategory): ?array
    {
        $shirtTypes = $garmentTypesByCategory->get(GarmentCategory::Shirt->value, collect());
        $pantsTypes = $garmentTypesByCategory->get(GarmentCategory::Pants->value, collect());

        $shirtType = $this->resolveShirtType($order, $shirtTypes);
        $pantsType = $this->resolvePantsType($order, $pantsTypes);

        $quantities = $this->summarizeOrderQuantitiesByPricingGroup($order);
        $shirtChildQuantity = (int) $quantities['shirt_kids'];
        $shirtAdultQuantity = (int) $quantities['shirt_adults'];
        $pantsChildQuantity = (int) $quantities['pants_kids'];
        $pantsAdultQuantity = (int) $quantities['pants_adults'];

        $buildComponents = static fn (GarmentType $garmentType): Collection => $garmentType->operations
            ->map(fn (GarmentOperation $operation): array => [
                'name' => $operation->name,
                'child_price' => (float) ($operation->child_price ?? 0),
                'adult_price' => (float) ($operation->adult_price ?? 0),
            ])
            ->values();

        $shirtComponents = $shirtType instanceof GarmentType ? $buildComponents($shirtType) : collect();
        $pantsComponents = $pantsType instanceof GarmentType ? $buildComponents($pantsType) : collect();

        $shirtChildUnitTotal = (float) $shirtComponents->sum('child_price');
        $shirtAdultUnitTotal = (float) $shirtComponents->sum('adult_price');
        $pantsChildUnitTotal = (float) $pantsComponents->sum('child_price');
        $pantsAdultUnitTotal = (float) $pantsComponents->sum('adult_price');

        $shirtChildTotal = $shirtChildUnitTotal * $shirtChildQuantity;
        $shirtAdultTotal = $shirtAdultUnitTotal * $shirtAdultQuantity;
        $pantsChildTotal = $pantsChildUnitTotal * $pantsChildQuantity;
        $pantsAdultTotal = $pantsAdultUnitTotal * $pantsAdultQuantity;

        $groups = collect([
            [
                'key' => 'shirt_kids',
                'label' => 'เสื้อไซต์เด็ก',
                'garment' => 'shirt',
                'size_group' => 'kids',
                'quantity' => $shirtChildQuantity,
                'unit_total' => $shirtChildUnitTotal,
                'subtotal' => $shirtChildTotal,
            ],
            [
                'key' => 'shirt_adults',
                'label' => 'เสื้อไซต์ผู้ใหญ่',
                'garment' => 'shirt',
                'size_group' => 'adults',
                'quantity' => $shirtAdultQuantity,
                'unit_total' => $shirtAdultUnitTotal,
                'subtotal' => $shirtAdultTotal,
            ],
            [
                'key' => 'pants_kids',
                'label' => 'กางเกงเด็ก',
                'garment' => 'pants',
                'size_group' => 'kids',
                'quantity' => $pantsChildQuantity,
                'unit_total' => $pantsChildUnitTotal,
                'subtotal' => $pantsChildTotal,
            ],
            [
                'key' => 'pants_adults',
                'label' => 'กางเกงผู้ใหญ่',
                'garment' => 'pants',
                'size_group' => 'adults',
                'quantity' => $pantsAdultQuantity,
                'unit_total' => $pantsAdultUnitTotal,
                'subtotal' => $pantsAdultTotal,
            ],
        ])->filter(fn (array $group): bool => (int) $group['quantity'] > 0)->values()->all();

        $grandTotal = $shirtChildTotal + $shirtAdultTotal + $pantsChildTotal + $pantsAdultTotal;

        return [
            'shirt_type_id' => $shirtType?->id ?? null,
            'pants_type_id' => $pantsType?->id ?? null,
            'shirt_type_name' => $shirtType?->name ?? null,
            'pants_type_name' => $pantsType?->name ?? null,
            'child_quantity' => $shirtChildQuantity + $pantsChildQuantity,
            'adult_quantity' => $shirtAdultQuantity + $pantsAdultQuantity,
            'shirt_child_quantity' => $shirtChildQuantity,
            'shirt_adult_quantity' => $shirtAdultQuantity,
            'pants_child_quantity' => $pantsChildQuantity,
            'pants_adult_quantity' => $pantsAdultQuantity,
            'components' => $shirtComponents->all(),
            'pants_components' => $pantsComponents->all(),
            'child_unit_total' => $shirtChildUnitTotal,
            'adult_unit_total' => $shirtAdultUnitTotal,
            'pants_child_unit_total' => $pantsChildUnitTotal,
            'pants_adult_unit_total' => $pantsAdultUnitTotal,
            'child_total' => $shirtChildTotal,
            'adult_total' => $shirtAdultTotal,
            'pants_child_total' => $pantsChildTotal,
            'pants_adult_total' => $pantsAdultTotal,
            'shirt_grand_total' => $shirtChildTotal + $shirtAdultTotal,
            'pants_grand_total' => $pantsChildTotal + $pantsAdultTotal,
            'grand_total' => $grandTotal,
            'groups' => $groups,
            'group_count' => count($groups),
        ];
    }

    public function index(Request $request): Response
    {
        $department = (string) $request->query('department', 'all');

        return $this->renderKanban('Production/Kanban', $department, true, 'Production Kanban', '/production/kanban');
    }

    public function printRoom(): Response
    {
        return $this->renderKanban('Production/PrintRoom', 'print_room', false, 'ห้องพิมพ์', '/production/print-room');
    }

    public function heatPress(): Response
    {
        return $this->renderKanban('Production/HeatPress', 'heat_press', false, 'ห้องอัด', '/production/heat-press');
    }

    public function embroidery(): Response
    {
        return $this->renderKanban('Production/Embroidery', 'embroidery', false, 'ห้องปัก', '/production/embroidery');
    }

    public function cutting(): Response
    {
        return $this->renderKanban('Production/Cutting', 'cutting', false, 'ห้องตัด', '/production/cutting');
    }

    public function sewing(): Response
    {
        return $this->renderKanban('Production/Sewing', 'sewing', false, 'ห้องเย็บ', '/production/sewing');
    }

    public function screenFlex(): Response
    {
        return $this->renderKanban('Production/ScreenFlex', 'screen_flex', false, 'สกรีน , เฟล็กซ์', '/production/screen-flex');
    }

    public function qc(): Response
    {
        return $this->renderKanban('Production/Qc', 'qc', false, 'ห้องตรวจสอบ', '/production/qc');
    }

    public function shipping(): Response
    {
        return $this->renderKanban('Production/Shipping', 'shipping', false, 'ห้องจัดส่ง', '/production/shipping');
    }

    private function renderKanban(
        string $pageComponent,
        string $department,
        bool $showDepartmentFilter,
        string $pageTitle,
        string $pageHref,
    ): Response
    {
        $allowedDepartments = ['all', 'design', 'print_room', 'heat_press', 'embroidery', 'cutting', 'sewing', 'screen_flex', 'qc', 'shipping'];
        $initialDepartmentFilter = in_array($department, $allowedDepartments, true) ? $department : 'all';
        $actor = request()->user();

        $ordersQuery = Order::with([
            'customer',
            'branch',
            'items',
            'routings.cuttingTeam',
            'routings.sewingTeam',
            'routings.embroideryTeam',
            'routings.screenTeam',
            'routings.heatPressMachine',
            'routings.assignedUser',
            'receipts.cashierUser',
            'statusHistories.user',
            'creatorUser',
            'media',
            'specification',
        ])
            ->whereNotIn('order_status', [OrderStatus::Completed, OrderStatus::Cancelled])
            ->latest('due_date');

        if ($initialDepartmentFilter === 'shipping' && $actor !== null) {
            UserAccessControl::applyBranchScope($ordersQuery, $actor);
        }

        $ordersPaginator = $ordersQuery
            ->paginate(15)
            ->withQueryString();

        $orders = collect($ordersPaginator->items());

        $cuttingTeams = CuttingTeam::query()
            ->where('is_active', true)
            ->orderBy('team_name')
            ->orderBy('id')
            ->get(['id', 'team_name', 'is_active', 'created_at', 'updated_at']);

        $embroideryTeams = EmbroideryTeam::query()
            ->where('is_active', true)
            ->orderBy('team_name')
            ->orderBy('id')
            ->get(['id', 'team_name', 'is_active', 'created_at', 'updated_at']);

        $sewingTeams = SewingTeam::query()
            ->where('is_active', true)
            ->orderBy('team_name')
            ->orderBy('id')
            ->get(['id', 'team_name', 'is_active', 'created_at', 'updated_at']);

        $screenTeams = ScreenTeam::query()
            ->where('is_active', true)
            ->orderBy('station_name')
            ->orderBy('team_name')
            ->orderBy('id')
            ->get(['id', 'team_name', 'station_name', 'is_active', 'created_at', 'updated_at']);

        $heatPressMachines = HeatPressMachine::query()
            ->where('is_active', true)
            ->orderBy('machine_name')
            ->orderBy('id')
            ->get(['id', 'machine_name', 'is_active', 'created_at', 'updated_at']);

        $priceMasters = PieceworkPrice::select('id', 'code', 'name', 'price_per_unit')->get();

        $fabricLookup = CatalogItem::query()
            ->where('storage_key', 'jssport.shirt-fabrics')
            ->where('active', true)
            ->get(['item_id', 'name'])
            ->mapWithKeys(fn (CatalogItem $item): array => [
                (string) $item->item_id => $item->name,
            ])
            ->all();

        if ($initialDepartmentFilter === 'shipping' && $actor !== null) {
            $branches = collect(UserAccessControl::branchOptionsVisibleTo($actor))
                ->map(fn (array $branch): array => [
                    'value' => $branch['branch_name'],
                    'label' => $branch['branch_name'],
                ])
                ->values();
        } else {
            $branches = Branch::select('id', 'branch_name')
                ->orderBy('branch_name')
                ->get()
                ->map(fn ($branch) => [
                    'value' => $branch->branch_name,
                    'label' => $branch->branch_name,
                ]);
        }

        $specSectionsMap = $orders
            ->mapWithKeys(fn (Order $order): array => [
                (string) $order->id => $this->mapSpecificationSections($order->specification?->toArray() ?? []),
            ])
            ->all();

        $garmentTypesByCategory = GarmentType::query()
            ->with(['operations' => fn ($query) => $query
                ->where('is_active', true)
                ->orderBy('display_order')
                ->orderBy('id')])
            ->where('is_active', true)
            ->orderBy('category')
            ->orderBy('display_order')
            ->orderBy('id')
            ->get()
            ->groupBy('category');

        $productionPricingMap = $orders
            ->mapWithKeys(fn (Order $order): array => [
                (string) $order->id => $this->buildProductionPricingSummary($order, $garmentTypesByCategory),
            ])
            ->all();

        return Inertia::render($pageComponent, [
            'orders' => $orders->values(),
            'pagination' => [
                'current_page' => $ordersPaginator->currentPage(),
                'last_page' => $ordersPaginator->lastPage(),
                'per_page' => $ordersPaginator->perPage(),
                'total' => $ordersPaginator->total(),
                'from' => $ordersPaginator->firstItem(),
                'to' => $ordersPaginator->lastItem(),
            ],
            'branches' => $branches,
            'priceMasters' => $priceMasters,
            'fabricLookup' => $fabricLookup,
            'specCatalogLookups' => $this->catalogLookups(),
            'specSectionsMap' => $specSectionsMap,
            'useBackendSpecMapOnly' => (bool) config('production.specs.use_backend_map_only', false),
            'productionPricingMap' => $productionPricingMap,
            'cuttingTeams' => $cuttingTeams,
            'sewingTeams' => $sewingTeams,
            'embroideryTeams' => $embroideryTeams,
            'screenTeams' => $screenTeams,
            'heatPressMachines' => $heatPressMachines,
            'initialDepartmentFilter' => $initialDepartmentFilter,
            'showDepartmentFilter' => $showDepartmentFilter,
            'pageTitle' => $pageTitle,
            'pageHref' => $pageHref,
        ]);
    }
}
