<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Enums\OrderStatus;
use App\Enums\RoutingStatus;
use App\Enums\StationDepartment;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderRouting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ShippingDeliveryInfoPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_shipping_delivery_info_persists_to_database(): void
    {
        $manager = User::factory()->create([
            'role' => UserRole::ProductionManager,
            'station_department' => StationDepartment::None,
        ]);

        $order = $this->createBaseOrder();

        $payload = [
            'carrier_name' => 'Kerry Express',
            'tracking_no' => 'TH123456789',
            'parcel_weight_kg' => '12.50',
            'parcel_shipping_cost' => '185.00',
            'onsite_sender_name' => '',
            'onsite_vehicle_plate' => '',
            'sender_signature' => 'John Doe',
        ];

        $this->actingAs($manager)
            ->postJson(route('production.shipping.delivery-info.store', $order), $payload)
            ->assertOk();

        $order->refresh();

        $this->assertSame($payload['carrier_name'], $order->shipping_delivery_info['carrier_name'] ?? null);
        $this->assertSame($payload['tracking_no'], $order->shipping_delivery_info['tracking_no'] ?? null);
        $this->assertSame($payload['parcel_weight_kg'], $order->shipping_delivery_info['parcel_weight_kg'] ?? null);
        $this->assertSame($payload['parcel_shipping_cost'], $order->shipping_delivery_info['parcel_shipping_cost'] ?? null);
        $this->assertSame($payload['sender_signature'], $order->shipping_delivery_info['sender_signature'] ?? null);
    }

    public function test_store_shipping_delivery_info_returns_inertia_redirect_and_still_persists(): void
    {
        $manager = User::factory()->create([
            'role' => UserRole::ProductionManager,
            'station_department' => StationDepartment::None,
        ]);

        $order = $this->createBaseOrder();

        $payload = [
            'carrier_name' => 'Flash Express',
            'tracking_no' => 'TH9988776655',
            'parcel_weight_kg' => '5',
            'parcel_shipping_cost' => '99',
            'onsite_sender_name' => 'Courier A',
            'onsite_vehicle_plate' => 'AB-1234',
            'sender_signature' => 'Sender Sign',
        ];

        $this->actingAs($manager)
            ->withHeaders(['X-Inertia' => 'true'])
            ->post(route('production.shipping.delivery-info.store', $order), $payload)
            ->assertStatus(303);

        $order->refresh();

        $this->assertSame($payload['tracking_no'], $order->shipping_delivery_info['tracking_no'] ?? null);
        $this->assertSame($payload['onsite_vehicle_plate'], $order->shipping_delivery_info['onsite_vehicle_plate'] ?? null);
    }

    public function test_advance_route_can_persist_nested_shipping_delivery_info_payload(): void
    {
        $manager = User::factory()->create([
            'role' => UserRole::ProductionManager,
            'station_department' => StationDepartment::None,
        ]);

        $order = $this->createBaseOrder();

        $payload = [
            'station_name' => 'shipping',
            'new_status' => 'in_progress',
            'shipping_delivery_info' => [
                'carrier_name' => 'J&T',
                'tracking_no' => 'TRACK-001',
                'parcel_weight_kg' => '3.5',
                'parcel_shipping_cost' => '70',
                'onsite_sender_name' => 'Onsite Sender',
                'onsite_vehicle_plate' => 'XYZ-0001',
                'sender_signature' => 'QA Tester',
            ],
        ];

        $this->actingAs($manager)
            ->postJson(route('production.routing.advance', $order), $payload)
            ->assertOk();

        $order->refresh();

        $this->assertSame('TRACK-001', $order->shipping_delivery_info['tracking_no'] ?? null);
        $this->assertSame('QA Tester', $order->shipping_delivery_info['sender_signature'] ?? null);
    }

    public function test_pickup_shipping_can_complete_directly_without_in_progress_step(): void
    {
        $manager = User::factory()->create([
            'role' => UserRole::ProductionManager,
            'station_department' => StationDepartment::None,
        ]);

        $order = $this->createBaseOrder();
        $order->update([
            'delivery_method' => 'pickup',
        ]);

        $payload = [
            'station_name' => 'shipping',
            'new_status' => 'completed',
            'direct_complete' => true,
            'shipping_delivery_info' => [
                'sender_signature' => 'Store Pickup Receiver',
            ],
        ];

        $this->actingAs($manager)
            ->postJson(route('production.routing.advance', $order), $payload)
            ->assertOk();

        $order->refresh();

        $shippingRouting = $order->routings()
            ->where('station_name', 'shipping')
            ->firstOrFail();

        $this->assertSame(RoutingStatus::Completed, $shippingRouting->status);
        $this->assertSame('Store Pickup Receiver', $order->shipping_delivery_info['sender_signature'] ?? null);
    }

    public function test_non_pickup_shipping_can_complete_directly_without_in_progress_step_when_direct_complete_is_true(): void
    {
        $manager = User::factory()->create([
            'role' => UserRole::ProductionManager,
            'station_department' => StationDepartment::None,
        ]);

        $order = $this->createBaseOrder();
        $order->update([
            'delivery_method' => 'shipping',
        ]);

        $payload = [
            'station_name' => 'shipping',
            'new_status' => 'completed',
            'direct_complete' => true,
            'shipping_delivery_info' => [
                'carrier_name' => 'J&T',
                'tracking_no' => 'TRACK-DIRECT-001',
                'parcel_weight_kg' => '3.5',
                'parcel_shipping_cost' => '70',
                'onsite_sender_name' => '',
                'onsite_vehicle_plate' => '',
                'sender_signature' => 'Shipping Staff',
            ],
        ];

        $this->actingAs($manager)
            ->postJson(route('production.routing.advance', $order), $payload)
            ->assertOk();

        $order->refresh();

        $shippingRouting = $order->routings()
            ->where('station_name', 'shipping')
            ->firstOrFail();

        $this->assertSame(RoutingStatus::Completed, $shippingRouting->status);
        $this->assertSame('TRACK-DIRECT-001', $order->shipping_delivery_info['tracking_no'] ?? null);
    }

    public function test_shipping_room_is_scoped_to_own_branch_for_non_branch_01_users(): void
    {
        $branch01 = Branch::create([
            'branch_code' => '01',
            'branch_name' => 'Branch 01',
        ]);

        $branch02 = Branch::create([
            'branch_code' => '02',
            'branch_name' => 'Branch 02',
        ]);

        $manager = User::factory()->create([
            'role' => UserRole::ProductionManager,
            'station_department' => StationDepartment::None,
            'branch_id' => $branch02->id,
        ]);

        $ownOrder = $this->createShippingOrderForBranch($branch02, 'ORD-SHIP-BR02');
        $otherOrder = $this->createShippingOrderForBranch($branch01, 'ORD-SHIP-BR01');

        $response = $this->actingAs($manager)
            ->get(route('production.shipping'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Production/Shipping')
            ->where('branches', [
                ['value' => 'Branch 02', 'label' => 'Branch 02'],
            ])
            ->where('orders.0.order_code', $ownOrder->order_code)
            ->missing('orders.1')
        );

        $this->assertNotSame($ownOrder->branch_id, $otherOrder->branch_id);
    }

    public function test_shipping_room_allows_branch_01_users_to_view_all_branches(): void
    {
        $branch01 = Branch::create([
            'branch_code' => '01',
            'branch_name' => 'Branch 01',
        ]);

        $branch02 = Branch::create([
            'branch_code' => '02',
            'branch_name' => 'Branch 02',
        ]);

        $manager = User::factory()->create([
            'role' => UserRole::ProductionManager,
            'station_department' => StationDepartment::None,
            'branch_id' => $branch01->id,
        ]);

        $orderFromBranch01 = $this->createShippingOrderForBranch($branch01, 'ORD-SHIP-BR01-VIEW');
        $orderFromBranch02 = $this->createShippingOrderForBranch($branch02, 'ORD-SHIP-BR02-VIEW');

        $response = $this->actingAs($manager)
            ->get(route('production.shipping'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Production/Shipping')
            ->has('branches', 2)
            ->has('orders', 2)
        );

        $orderCodes = collect($response->viewData('page')['props']['orders'] ?? [])->pluck('order_code')->all();

        $this->assertContains($orderFromBranch01->order_code, $orderCodes);
        $this->assertContains($orderFromBranch02->order_code, $orderCodes);
    }

    private function createBaseOrder(): Order
    {
        $customer = Customer::create([
            'customer_code' => 'CUS-SHIP-001',
            'customer_name' => 'Shipping Customer',
        ]);

        $branch = Branch::create([
            'branch_code' => 'BR-SHIP-001',
            'branch_name' => 'Shipping Branch',
        ]);

        $creator = User::factory()->create([
            'role' => UserRole::Sales,
            'station_department' => StationDepartment::None,
        ]);

        $order = Order::create([
            'order_code' => 'ORD-SHIP-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'creator_user_id' => $creator->id,
            'job_name' => 'Shipping Flow Order',
            'job_type' => 'งานปัก',
            'order_date' => now()->subDay(),
            'due_date' => now()->addDays(7),
            'order_status' => OrderStatus::InProduction,
            'total_amount' => 1000,
            'discount_percent' => 0,
            'discount_amount' => 0,
            'net_amount' => 1000,
        ]);

        $order->routings()->create([
            'station_name' => 'shipping',
            'is_required' => true,
            'status' => RoutingStatus::Pending,
        ]);

        return $order;
    }

    private function createShippingOrderForBranch(Branch $branch, string $orderCode): Order
    {
        $customer = Customer::create([
            'customer_code' => 'CUS-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'customer_name' => 'Shipping Customer '.$orderCode,
        ]);

        $creator = User::factory()->create([
            'role' => UserRole::Sales,
            'station_department' => StationDepartment::None,
            'branch_id' => $branch->id,
        ]);

        $order = Order::create([
            'order_code' => $orderCode,
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'creator_user_id' => $creator->id,
            'job_name' => 'Shipping Flow '.$orderCode,
            'job_type' => 'งานปัก',
            'order_date' => now()->subDay(),
            'due_date' => now()->addDays(7),
            'order_status' => OrderStatus::InProduction,
            'delivery_method' => 'shipping',
            'total_amount' => 1000,
            'discount_percent' => 0,
            'discount_amount' => 0,
            'net_amount' => 1000,
        ]);

        OrderRouting::query()->create([
            'order_id' => $order->id,
            'station_name' => 'shipping',
            'is_required' => true,
            'status' => RoutingStatus::Pending,
        ]);

        return $order;
    }
}
