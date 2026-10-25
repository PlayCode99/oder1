<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\AccessRole;
use App\Enums\StationDepartment;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\CatalogItem;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A spec name typed on the order form, rather than picked, becomes master data
 * when the bill is saved — the same entry the form's "+" button would have
 * made — and the bill is written with that entry's id.
 */
class TypedSpecMasterDataTest extends TestCase
{
    use RefreshDatabase;

    private User $counter;

    protected function setUp(): void
    {
        parent::setUp();

        // A counter clerk, not an admin: anyone opening a bill may add.
        $branch = Branch::firstOrCreate(['branch_code' => 'BR-TYPED'], ['branch_name' => 'ทดสอบ']);
        $this->counter = User::factory()->create([
            'role' => UserRole::Sales, 'access_role' => AccessRole::Counter,
            'station_department' => StationDepartment::None, 'is_active' => true,
            'branch_id' => $branch->id, 'name' => 'พนักงานเคาน์เตอร์',
        ]);
    }

    /**
     * @param  array<string, mixed>  $shirtSpec
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payload(array $shirtSpec, array $flat, array $extra = []): array
    {
        $customer = Customer::firstOrCreate(['customer_code' => 'CUS-TYPED'], ['customer_name' => 'ทดสอบ']);
        $branch = Branch::firstOrCreate(['branch_code' => 'BR-TYPED'], ['branch_name' => 'ทดสอบ']);

        return [
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'customer_name' => 'ลูกค้าทดสอบ',
            'job_name' => 'งานพิมพ์ชื่อเอง',
            'job_type' => 'ปัก',
            'order_date' => now()->toDateTimeString(),
            'due_date' => now()->addDays(5)->toDateTimeString(),
            'discount_percent' => 0,
            'items' => [[
                'item_type' => 'separate_shirt', 'size_group' => 'adults',
                'size_tier' => 'adults', 'size_label' => 'M',
                'shirt_style' => 'short', 'quantity' => 2, 'unit_price' => 200,
            ]],
            'specification' => [
                ...$flat,
                'screen_print_detail' => json_encode([
                    'schema' => 'spec-v3',
                    'mode' => 'matrix',
                    'garment_specs' => ['shirt_adults_short' => $shirtSpec],
                    'garment_spec_labels' => ['shirt_adults_short' => []],
                    'shirt_specs' => $shirtSpec,
                    'pants_specs' => [],
                    'spec_labels' => ['shirt' => [], 'pants' => []],
                    ...$extra,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ],
        ];
    }

    private function entry(string $storageKey, string $name): ?CatalogItem
    {
        return CatalogItem::query()->where('storage_key', $storageKey)->where('name', $name)->first();
    }

    /** @return array<string, mixed> */
    private function savedDetail(Order $order): array
    {
        return json_decode((string) $order->specification->screen_print_detail, true);
    }

    public function test_typed_names_become_master_data_and_the_bill_keeps_their_ids(): void
    {
        $spec = [
            'pattern_id' => 'แพทเทิร์นใหม่',
            'fabric_id' => 'ผ้าใหม่',
            'neck_style_id' => 'คอจีน',
            'collar_id' => 'ปกใหม่',
            'sleeve_cuff_id' => 'ปลายแขนใหม่',
        ];

        $this->actingAs($this->counter)
            ->post('/orders', $this->payload($spec, [
                'pattern_id' => 'แพทเทิร์นใหม่', 'fabric_id' => 'ผ้าใหม่', 'neck_style_id' => 'คอจีน',
            ]))
            ->assertSessionHasNoErrors();

        $pattern = $this->entry('jssport.shirt-patterns', 'แพทเทิร์นใหม่');
        $fabric = $this->entry('jssport.shirt-fabrics', 'ผ้าใหม่');
        $neck = $this->entry('jssport.shirt-neck-styles', 'คอจีน');
        $collar = $this->entry('jssport.shirt-collars', 'ปกใหม่');
        $cuff = $this->entry('jssport.shirt-cuffs', 'ปลายแขนใหม่');

        foreach ([$pattern, $fabric, $neck, $collar, $cuff] as $created) {
            $this->assertNotNull($created);
            $this->assertTrue($created->active);
            $this->assertSame('พนักงานเคาน์เตอร์', $created->created_by);
        }

        // แบบคอ and ปก are separate lists now: the neck name is not a collar.
        $this->assertNull($this->entry('jssport.shirt-collars', 'คอจีน'));
        $this->assertNull($this->entry('jssport.shirt-neck-styles', 'ปกใหม่'));

        $order = Order::query()->with('specification')->sole();
        $this->assertSame($pattern->item_id, $order->specification->pattern_id);
        $this->assertSame($fabric->item_id, $order->specification->fabric_id);
        $this->assertSame($neck->item_id, $order->specification->neck_style_id);

        $detail = $this->savedDetail($order);

        foreach (['garment_specs.shirt_adults_short', 'shirt_specs'] as $path) {
            $saved = data_get($detail, $path);
            $this->assertSame((string) $neck->item_id, $saved['neck_style_id']);
            $this->assertSame((string) $collar->item_id, $saved['collar_id']);
            $this->assertSame((string) $cuff->item_id, $saved['sleeve_cuff_id']);
        }

        // The name is kept as the label the bill was saved with.
        $this->assertSame('คอจีน', $detail['garment_spec_labels']['shirt_adults_short']['neck_style_id']);
        $this->assertSame('คอจีน', $detail['spec_labels']['shirt']['neck_style_id']);
    }

    public function test_a_name_already_on_the_list_is_used_not_filed_twice(): void
    {
        CatalogItem::query()->create([
            'storage_key' => 'jssport.shirt-fabrics', 'item_id' => 40,
            'name' => 'ผ้าไมโคร', 'sort_order' => 40, 'active' => true,
        ]);
        $before = CatalogItem::query()->where('storage_key', 'jssport.shirt-fabrics')->count();

        $this->actingAs($this->counter)
            ->post('/orders', $this->payload(
                ['pattern_id' => '1', 'fabric_id' => '  ผ้าไมโคร '],
                ['pattern_id' => '1', 'fabric_id' => '  ผ้าไมโคร '],
            ))
            ->assertSessionHasNoErrors();

        $this->assertSame($before, CatalogItem::query()->where('storage_key', 'jssport.shirt-fabrics')->count());
        $this->assertSame(40, Order::query()->with('specification')->sole()->specification->fabric_id);
    }

    public function test_a_hidden_entry_typed_again_is_shown_again(): void
    {
        CatalogItem::query()->create([
            'storage_key' => 'jssport.shirt-cuffs', 'item_id' => 9,
            'name' => 'ปลายแขนเก่า', 'sort_order' => 9, 'active' => false,
        ]);

        $this->actingAs($this->counter)
            ->post('/orders', $this->payload(
                ['pattern_id' => '1', 'fabric_id' => '1', 'sleeve_cuff_id' => 'ปลายแขนเก่า'],
                ['pattern_id' => 1, 'fabric_id' => 1],
            ))
            ->assertSessionHasNoErrors();

        $this->assertTrue($this->entry('jssport.shirt-cuffs', 'ปลายแขนเก่า')->active);
        $this->assertSame(1, CatalogItem::query()->where('storage_key', 'jssport.shirt-cuffs')->where('name', 'ปลายแขนเก่า')->count());
    }

    public function test_picked_ids_and_empty_fields_are_written_as_they_came(): void
    {
        $before = CatalogItem::query()->count();
        $spec = ['pattern_id' => '1', 'fabric_id' => '2', 'neck_style_id' => '', 'collar_id' => '-'];

        $this->actingAs($this->counter)
            ->post('/orders', $this->payload($spec, ['pattern_id' => 1, 'fabric_id' => 2]))
            ->assertSessionHasNoErrors();

        $this->assertSame($before, CatalogItem::query()->count());
        $saved = $this->savedDetail(Order::query()->with('specification')->sole())['shirt_specs'];
        $this->assertSame($spec, $saved);
    }

    public function test_a_bill_that_is_refused_files_nothing(): void
    {
        $before = CatalogItem::query()->count();
        $payload = $this->payload(['pattern_id' => 'แพทเทิร์นใหม่', 'fabric_id' => 'ผ้าใหม่'], [
            'pattern_id' => 'แพทเทิร์นใหม่', 'fabric_id' => 'ผ้าใหม่',
        ]);
        unset($payload['job_name']);

        $this->actingAs($this->counter)->post('/orders', $payload)->assertSessionHasErrors('job_name');

        $this->assertSame($before, CatalogItem::query()->count());
        $this->assertSame(0, Order::query()->count());
    }

    public function test_pants_names_go_to_the_pants_lists_and_houses_to_the_colours(): void
    {
        $pantsSpec = ['pattern_id' => 'ขาสามส่วน', 'fabric_id' => '1', 'leg_style_id' => 'ขาบาน'];
        $payload = $this->payload(['pattern_id' => '1', 'fabric_id' => '1'], ['pattern_id' => 1, 'fabric_id' => 1], [
            'garment_specs' => ['pants_adults_short' => $pantsSpec],
            'pants_specs' => $pantsSpec,
            'sports_day_groups' => [['team_name' => 'คณะฟ้า', 'fabric_color_id' => 'ฟ้าอมเขียว', 'rows' => []]],
        ]);

        $this->actingAs($this->counter)->post('/orders', $payload)->assertSessionHasNoErrors();

        $this->assertNotNull($this->entry('jssport.pants-patterns', 'ขาสามส่วน'));
        $this->assertNull($this->entry('jssport.shirt-patterns', 'ขาสามส่วน'));
        $this->assertNotNull($this->entry('jssport.pants-leg-style', 'ขาบาน'));

        $colour = $this->entry('jssport.shirt-fabric-colors', 'ฟ้าอมเขียว');
        $this->assertNotNull($colour);
        $detail = $this->savedDetail(Order::query()->with('specification')->sole());
        $this->assertSame((string) $colour->item_id, $detail['sports_day_groups'][0]['fabric_color_id']);
    }

    public function test_editing_a_bill_files_a_newly_typed_name_too(): void
    {
        $this->actingAs($this->counter)
            ->post('/orders', $this->payload(['pattern_id' => '1', 'fabric_id' => '1'], ['pattern_id' => 1, 'fabric_id' => 1]))
            ->assertSessionHasNoErrors();
        $order = Order::query()->sole();

        $this->actingAs($this->counter)
            ->put("/orders/{$order->id}", $this->payload(
                ['pattern_id' => '1', 'fabric_id' => '1', 'neck_style_id' => 'คอเต่า'],
                ['pattern_id' => 1, 'fabric_id' => 1, 'neck_style_id' => 'คอเต่า'],
            ))
            ->assertSessionHasNoErrors();

        $neck = $this->entry('jssport.shirt-neck-styles', 'คอเต่า');
        $this->assertNotNull($neck);
        $this->assertSame($neck->item_id, $order->fresh('specification')->specification->neck_style_id);
    }

    public function test_a_page_that_posted_nan_for_an_optional_column_still_saves(): void
    {
        $this->actingAs($this->counter)
            ->post('/orders', $this->payload(
                ['pattern_id' => '1', 'fabric_id' => '1', 'neck_style_id' => 'คอจีน'],
                ['pattern_id' => 1, 'fabric_id' => 1, 'neck_style_id' => 'NaN'],
            ))
            ->assertSessionHasNoErrors();

        // The column came as nothing, but the spec still names the style.
        $this->assertNotNull($this->entry('jssport.shirt-neck-styles', 'คอจีน'));
    }

    public function test_the_neck_style_list_starts_as_the_old_shared_list_with_the_same_ids(): void
    {
        $read = fn (string $key): array => CatalogItem::query()
            ->where('storage_key', $key)->orderBy('item_id')
            ->get(['item_id', 'name', 'active'])->map->only(['item_id', 'name', 'active'])->all();

        $this->assertNotSame([], $read('jssport.shirt-neck-styles'));
        $this->assertSame($read('jssport.shirt-collars'), $read('jssport.shirt-neck-styles'));
    }

    /**
     * The summary text columns hold an entry's id, as the form sends a picked
     * one. A typed name must land the same way on the first save, or the bill
     * reads one way when new and another once it is saved again.
     */
    public function test_a_typed_name_in_a_summary_column_is_stored_as_its_id(): void
    {
        $pantsSpec = ['pattern_id' => '1', 'fabric_id' => '1', 'leg_cuff_id' => 'ปลายขาใหม่'];
        $payload = $this->payload(['pattern_id' => '1', 'fabric_id' => '1', 'sleeve_cuff_id' => 'ปลายแขนใหม่'], [
            'pattern_id' => 1, 'fabric_id' => 1, 'leg_hem' => 'ปลายขาใหม่', 'sleeve_hem' => 'ปลายแขนใหม่',
        ], ['garment_specs' => ['pants_adults_short' => $pantsSpec], 'pants_specs' => $pantsSpec]);

        $this->actingAs($this->counter)->post('/orders', $payload)->assertSessionHasNoErrors();

        $spec = Order::query()->with('specification')->sole()->specification;

        $this->assertSame((string) $this->entry('jssport.pants-leg-hem', 'ปลายขาใหม่')->item_id, $spec->leg_hem);
        $this->assertSame((string) $this->entry('jssport.shirt-cuffs', 'ปลายแขนใหม่')->item_id, $spec->sleeve_hem);
    }
}
