<?php

namespace Tests\Feature\Domain\OrderManagement;

use App\Domain\OrderManagement\Actions\CreateOrderAction;
use App\Domain\Production\Actions\AdvanceRoutingStationAction;
use App\Enums\OrderStatus;
use App\Enums\RoutingStationName;
use App\Enums\RoutingStatus;
use App\Enums\StationDepartment;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderRouting;
use App\Models\OrderStatusHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class CreateOrderActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_requested_routing_flow_for_each_job_type(): void
    {
        $customer = Customer::create([
            'customer_code' => 'CUS-1001',
            'customer_name' => 'Flow Test Customer',
        ]);

        $branch = Branch::create([
            'branch_code' => 'HQ-1001',
            'branch_name' => 'Flow Test Branch',
        ]);

        $creator = User::factory()->create([
            'role' => UserRole::Sales,
            'station_department' => StationDepartment::None,
        ]);

        $cases = [
            'งานปัก' => ['cutting', 'embroidery', 'sewing', 'qc', 'shipping'],
            'งานซับลิเมชั่น' => ['cutting', 'print', 'screen', 'sewing', 'qc', 'shipping'],
            'งานสกรีน' => ['cutting', 'flex', 'sewing', 'qc', 'shipping'],
            'งานซับลิเมชั่น+ปัก' => ['cutting', 'print', 'screen', 'embroidery', 'sewing', 'qc', 'shipping'],
            'งานซับลิเมชั่น+ปัก+สกรีน' => ['cutting', 'print', 'screen', 'flex', 'embroidery', 'sewing', 'qc', 'shipping'],
            'งานซับลิเมชั่น+สกรีน' => ['cutting', 'print', 'screen', 'flex', 'sewing', 'qc', 'shipping'],
            'งานปัก+สกรีน' => ['cutting', 'flex', 'embroidery', 'sewing', 'qc', 'shipping'],
        ];

        foreach ($cases as $jobType => $expectedStations) {
            $order = (new CreateOrderAction())->execute(
                $this->basePayload($customer->id, $branch->id, $jobType),
                $creator->id,
            );

            $actualStations = $order->routings
                ->sortBy('id')
                ->map(fn (OrderRouting $routing): string => $routing->station_name->value)
                ->values()
                ->all();

            $this->assertSame(
                $expectedStations,
                $actualStations,
                sprintf('Routing flow mismatch for job type [%s]', $jobType),
            );
        }
    }

    public function test_it_creates_real_orders_for_every_supported_job_type_with_the_expected_routing_sequence_and_first_step_progression(): void
    {
        $customer = Customer::create([
            'customer_code' => 'CUS-REAL-1001',
            'customer_name' => 'Real Flow Customer',
        ]);

        $branch = Branch::create([
            'branch_code' => 'BR-REAL-1001',
            'branch_name' => 'Real Flow Branch',
        ]);

        $creator = User::factory()->create([
            'role' => UserRole::Sales,
            'station_department' => StationDepartment::None,
        ]);

        $cases = [
            'งานปัก' => ['cutting', 'embroidery', 'sewing', 'qc', 'shipping'],
            'งานซับลิเมชั่น' => ['cutting', 'print', 'screen', 'sewing', 'qc', 'shipping'],
            'งานสกรีน' => ['cutting', 'flex', 'sewing', 'qc', 'shipping'],
            'งานซับลิเมชั่น+ปัก' => ['cutting', 'print', 'screen', 'embroidery', 'sewing', 'qc', 'shipping'],
            'งานซับลิเมชั่น+ปัก+สกรีน' => ['cutting', 'print', 'screen', 'flex', 'embroidery', 'sewing', 'qc', 'shipping'],
            'งานซับลิเมชั่น+สกรีน' => ['cutting', 'print', 'screen', 'flex', 'sewing', 'qc', 'shipping'],
            'งานปัก+สกรีน' => ['cutting', 'flex', 'embroidery', 'sewing', 'qc', 'shipping'],
        ];

        foreach ($cases as $jobType => $expectedStations) {
            $order = (new CreateOrderAction())->execute(
                $this->basePayload($customer->id, $branch->id, $jobType),
                $creator->id,
            );

            $persistedStations = OrderRouting::query()
                ->where('order_id', $order->id)
                ->orderBy('id')
                ->get()
                ->map(fn (OrderRouting $routing): string => $routing->station_name instanceof RoutingStationName
                    ? $routing->station_name->value
                    : (string) $routing->station_name)
                ->all();

            $this->assertSame(
                $expectedStations,
                $persistedStations,
                sprintf('Persisted routing flow mismatch for job type [%s]', $jobType),
            );

            $this->assertDatabaseHas('orders', [
                'id' => $order->id,
                'order_status' => OrderStatus::Draft->value,
            ]);

            $firstStation = RoutingStationName::from($expectedStations[0]);
            $routing = (new AdvanceRoutingStationAction())->execute(
                $order,
                $firstStation,
                RoutingStatus::InProgress,
                $creator->id,
            );

            $this->assertSame(RoutingStatus::InProgress->value, $routing->status->value);

            $allRoutings = OrderRouting::query()
                ->where('order_id', $order->id)
                ->orderBy('id')
                ->get();

            $this->assertSame($expectedStations[0], $allRoutings->first()->station_name->value);
            $this->assertTrue(
                $allRoutings->slice(1)->every(fn (OrderRouting $routing): bool => $routing->status === RoutingStatus::Pending),
            );
        }
    }

    public function test_it_recalculates_financial_values_and_ignores_injected_totals(): void
    {
        $customer = Customer::create([
            'customer_code' => 'CUS-0001',
            'customer_name' => 'ACME Garment Buyer',
        ]);

        $branch = Branch::create([
            'branch_code' => 'HQ-001',
            'branch_name' => 'Main Factory & HQ',
        ]);

        $creator = User::factory()->create([
            'role' => UserRole::Sales,
            'station_department' => StationDepartment::None,
        ]);

        $payload = [
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'job_name' => 'School Uniform Batch A',
            'job_type' => 'uniform',
            'order_date' => '2026-07-11 09:00:00',
            'due_date' => '2026-07-20 18:00:00',
            'discount_percent' => 10,
            'total_amount' => 1,
            'discount_amount' => 99999,
            'net_amount' => 1,
            'items' => [
                [
                    'item_type' => 'shirt',
                    'size_group' => 'adults',
                    'size_label' => 'L',
                    'quantity' => 10,
                    'unit_price' => 50,
                    'total_price' => 1,
                ],
                [
                    'item_type' => 'shirt',
                    'size_group' => 'adults',
                    'size_label' => 'XL',
                    'quantity' => 5,
                    'unit_price' => 50,
                    'total_price' => 1,
                ],
            ],
            'routings' => ['design', 'print', 'sewing', 'qc'],
        ];

        $order = (new CreateOrderAction())->execute($payload, $creator->id);

        $this->assertSame(750.0, (float) $order->total_amount);
        $this->assertSame(75.0, (float) $order->discount_amount);
        $this->assertSame(675.0, (float) $order->net_amount);
        $this->assertSame(OrderStatus::Draft, $order->order_status);

        $this->assertCount(2, $order->items);
        $this->assertSame(500.0, (float) $order->items[0]->total_price);
        $this->assertSame(250.0, (float) $order->items[1]->total_price);

        $this->assertCount(1, $order->routings);
        $this->assertSame(
            ['design'],
            $order->routings
                ->sortBy('id')
                ->map(fn (OrderRouting $routing): string => $routing->station_name->value)
                ->values()
                ->all(),
        );
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'total_amount' => 750.0,
            'discount_amount' => 75.0,
            'net_amount' => 675.0,
        ]);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'size_label' => 'L',
            'total_price' => 500.0,
        ]);
    }

    public function test_it_rolls_back_everything_when_failure_happens_mid_transaction(): void
    {
        $customer = Customer::create([
            'customer_code' => 'CUS-0002',
            'customer_name' => 'Rollback Customer',
        ]);

        $branch = Branch::create([
            'branch_code' => 'BR-002',
            'branch_name' => 'City Sub-Branch',
        ]);

        $creator = User::factory()->create([
            'role' => UserRole::Sales,
            'station_department' => StationDepartment::None,
        ]);

        OrderItem::creating(function (OrderItem $item): void {
            if ($item->item_type === 'FAIL') {
                throw new RuntimeException('Simulated failure while creating order item.');
            }
        });

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Failed to create order.');

            (new CreateOrderAction())->execute([
                'customer_id' => $customer->id,
                'branch_id' => $branch->id,
                'job_name' => 'Rollback Case',
                'job_type' => 'uniform',
                'order_date' => '2026-07-11 09:00:00',
                'due_date' => '2026-07-18 18:00:00',
                'discount_percent' => 0,
                'items' => [
                    [
                        'item_type' => 'shirt',
                        'size_group' => 'adults',
                        'size_label' => 'M',
                        'quantity' => 5,
                        'unit_price' => 20,
                    ],
                    [
                        'item_type' => 'FAIL',
                        'size_group' => 'adults',
                        'size_label' => 'L',
                        'quantity' => 3,
                        'unit_price' => 25,
                    ],
                ],
                'routings' => ['design', 'print'],
            ], $creator->id);
        } finally {
            OrderItem::flushEventListeners();
        }

        $this->assertSame(0, Order::count());
        $this->assertSame(0, OrderItem::count());
        $this->assertSame(0, OrderRouting::count());
        $this->assertSame(0, OrderStatusHistory::count());
    }

    public function test_it_keeps_only_the_latest_primary_artwork_file(): void
    {
        Storage::fake('public');

        $customer = Customer::create([
            'customer_code' => 'CUS-ART-0001',
            'customer_name' => 'Artwork Customer',
        ]);

        $branch = Branch::create([
            'branch_code' => 'BR-ART-0001',
            'branch_name' => 'Artwork Branch',
        ]);

        $creator = User::factory()->create([
            'role' => UserRole::Sales,
            'station_department' => StationDepartment::None,
        ]);

        $action = new CreateOrderAction();

        $firstOrder = $action->execute([
            ...$this->basePayload($customer->id, $branch->id, 'งานปัก'),
            'design_artwork' => UploadedFile::fake()->image('first-artwork.jpg', 120, 120),
        ], $creator->id);

        $secondOrder = $action->execute([
            ...$this->basePayload($customer->id, $branch->id, 'งานปัก'),
            'design_artwork' => UploadedFile::fake()->image('second-artwork.jpg', 140, 140),
        ], $creator->id);

        $firstOrder->refresh();
        $secondOrder->refresh();

        $firstArtworkMedia = $firstOrder->getFirstMedia('artwork');
        $secondArtworkMedia = $secondOrder->getFirstMedia('artwork');

        $this->assertNotNull($firstArtworkMedia);
        $this->assertNotNull($secondArtworkMedia);
        $this->assertSame('first-artwork.jpg', $firstArtworkMedia->file_name);
        $this->assertSame('second-artwork.jpg', $secondArtworkMedia->file_name);
        $this->assertCount(1, $firstOrder->getMedia('artwork'));
        $this->assertCount(1, $secondOrder->getMedia('artwork'));
    }

    public function test_it_stores_shirt_artwork_as_webp_when_uploaded_alone(): void
    {
        Storage::fake('public');

        $customer = Customer::create([
            'customer_code' => 'CUS-ART-SHIRT-0001',
            'customer_name' => 'Shirt Artwork Customer',
        ]);

        $branch = Branch::create([
            'branch_code' => 'BR-ART-SHIRT-0001',
            'branch_name' => 'Shirt Artwork Branch',
        ]);

        $creator = User::factory()->create([
            'role' => UserRole::Sales,
            'station_department' => StationDepartment::None,
        ]);

        $order = (new CreateOrderAction())->execute([
            ...$this->basePayload($customer->id, $branch->id, 'งานปัก'),
            'shirt_artwork' => UploadedFile::fake()->image('shirt-source.jpg', 120, 120),
        ], $creator->id);

        $shirtArtworkMedia = $order->refresh()->getFirstMedia('shirt_artwork');

        $this->assertNotNull($shirtArtworkMedia);
        $this->assertStringEndsWith('.webp', $shirtArtworkMedia->file_name);
        $this->assertNull($order->getFirstMedia('pants_artwork'));
    }

    public function test_it_stores_pants_artwork_as_webp_when_uploaded_alone(): void
    {
        Storage::fake('public');

        $customer = Customer::create([
            'customer_code' => 'CUS-ART-PANTS-0001',
            'customer_name' => 'Pants Artwork Customer',
        ]);

        $branch = Branch::create([
            'branch_code' => 'BR-ART-PANTS-0001',
            'branch_name' => 'Pants Artwork Branch',
        ]);

        $creator = User::factory()->create([
            'role' => UserRole::Sales,
            'station_department' => StationDepartment::None,
        ]);

        $order = (new CreateOrderAction())->execute([
            ...$this->basePayload($customer->id, $branch->id, 'งานปัก'),
            'pants_artwork' => UploadedFile::fake()->image('pants-source.jpg', 120, 120),
        ], $creator->id);

        $pantsArtworkMedia = $order->refresh()->getFirstMedia('pants_artwork');

        $this->assertNotNull($pantsArtworkMedia);
        $this->assertStringEndsWith('.webp', $pantsArtworkMedia->file_name);
        $this->assertNull($order->getFirstMedia('shirt_artwork'));
    }

    public function test_it_keeps_artwork_channels_isolated_for_create_and_replace(): void
    {
        Storage::fake('public');

        $customer = Customer::create([
            'customer_code' => 'CUS-ART-ISO-0001',
            'customer_name' => 'Artwork Isolation Customer',
        ]);

        $branch = Branch::create([
            'branch_code' => 'BR-ART-ISO-0001',
            'branch_name' => 'Artwork Isolation Branch',
        ]);

        $creator = User::factory()->create([
            'role' => UserRole::Sales,
            'station_department' => StationDepartment::None,
        ]);

        $order = (new CreateOrderAction())->execute([
            ...$this->basePayload($customer->id, $branch->id, 'งานปัก'),
            'design_artwork' => UploadedFile::fake()->image('general-source.jpg', 120, 120),
            'shirt_artwork' => UploadedFile::fake()->image('shirt-source.jpg', 120, 120),
            'pants_artwork' => UploadedFile::fake()->image('pants-source.jpg', 120, 120),
        ], $creator->id);

        $order->refresh();

        $this->assertCount(1, $order->getMedia('artwork'));
        $this->assertCount(1, $order->getMedia('shirt_artwork'));
        $this->assertCount(1, $order->getMedia('pants_artwork'));

        $this->assertSame('general-source.jpg', $order->getFirstMedia('artwork')?->file_name);
        $this->assertStringEndsWith('.webp', (string) $order->getFirstMedia('shirt_artwork')?->file_name);
        $this->assertStringEndsWith('.webp', (string) $order->getFirstMedia('pants_artwork')?->file_name);

        $order->addMedia(UploadedFile::fake()->image('shirt-replaced.jpg', 140, 140))->toMediaCollection('shirt_artwork');
        $order->refresh();

        $this->assertCount(1, $order->getMedia('shirt_artwork'));
        $this->assertCount(1, $order->getMedia('pants_artwork'));
        $this->assertCount(1, $order->getMedia('artwork'));
        $this->assertSame('general-source.jpg', $order->getFirstMedia('artwork')?->file_name);
        $this->assertStringEndsWith('.jpg', (string) $order->getFirstMedia('shirt_artwork')?->file_name);
        $this->assertStringEndsWith('.webp', (string) $order->getFirstMedia('pants_artwork')?->file_name);
    }

    /**
     * @return array<string, mixed>
     */
    private function basePayload(int $customerId, int $branchId, string $jobType): array
    {
        return [
            'customer_id' => $customerId,
            'branch_id' => $branchId,
            'job_name' => 'Flow Test Order',
            'job_type' => $jobType,
            'order_date' => '2026-07-11 09:00:00',
            'due_date' => '2026-07-20 18:00:00',
            'discount_percent' => 0,
            'items' => [
                [
                    'item_type' => 'shirt',
                    'size_group' => 'adults',
                    'size_label' => 'L',
                    'quantity' => 10,
                    'unit_price' => 50,
                ],
            ],
            // Intentionally ignored by action, preserved to validate zero-trust backend flow mapping.
            'routings' => ['design', 'print', 'qc'],
        ];
    }
}
