<?php

namespace Tests\Feature\Http\Controllers;

use App\Domain\OrderManagement\Actions\CreateOrderAction;
use App\Enums\AccessRole;
use App\Enums\OrderStatus;
use App\Enums\RoutingStationName;
use App\Enums\RoutingStatus;
use App\Enums\StationDepartment;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderDueDateChange;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * "เลื่อนส่ง": moving a bill's delivery date from the counter. The bill keeps
 * one date that every page reads, so they all follow; each move is kept with
 * its reason for the timeline and the note on sheets.
 */
class OrderDueDateTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-24 10:00:00'));
        $this->branch = Branch::create(['branch_code' => '02', 'branch_name' => 'ศรีบุญเรือง']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function user(UserRole $role = UserRole::Sales, ?Branch $branch = null): User
    {
        return User::factory()->create([
            'role' => $role,
            'access_role' => AccessRole::Counter,
            'station_department' => StationDepartment::None,
            'is_active' => true,
            'branch_id' => ($branch ?? $this->branch)->id,
            'name' => 'พนักงานเคาน์เตอร์',
        ]);
    }

    private function order(User $creator, string $due = '2026-10-28'): Order
    {
        $customer = Customer::firstOrCreate(['customer_code' => 'CUS-DUE'], ['customer_name' => 'ลูกค้าเลื่อนส่ง']);

        return (new CreateOrderAction)->execute([
            'customer_id' => $customer->id,
            'branch_id' => $this->branch->id,
            'job_name' => 'งานเลื่อนส่ง',
            'job_type' => 'ปัก',
            'order_date' => '2026-10-24 09:00:00',
            'due_date' => $due.' 00:00:00',
            'discount_percent' => 0,
            'items' => [[
                'item_type' => 'separate_shirt', 'size_group' => 'adults',
                'size_label' => 'M', 'quantity' => 12, 'unit_price' => 100,
            ]],
        ], $creator->id);
    }

    public function test_a_bill_in_production_can_be_postponed_and_every_page_reads_the_new_date(): void
    {
        $sales = $this->user();
        $order = $this->order($sales);
        // Already on the floor: editing is closed, postponing is not.
        $order->routings()->where('is_required', true)->first()->update(['status' => RoutingStatus::InProgress]);

        $this->actingAs($sales)
            ->post("/orders/{$order->id}/due-date", ['due_date' => '2026-11-05', 'reason' => 'ลูกค้าขอเลื่อน'])
            ->assertSessionHasNoErrors();

        $this->assertSame('2026-11-05', $order->fresh()->due_date->format('Y-m-d'));

        $change = OrderDueDateChange::query()->sole();
        $this->assertSame('2026-10-28', $change->from_date->format('Y-m-d'));
        $this->assertSame('2026-11-05', $change->to_date->format('Y-m-d'));
        $this->assertSame('ลูกค้าขอเลื่อน', $change->reason);
        $this->assertSame($sales->id, $change->changed_by);

        $this->actingAs($sales)->get('/counter?calendar_month=2026-11')
            ->assertInertia(fn (Assert $page) => $page
                ->where('orders.0.due_date', '2026-11-05')
                ->where('orders.0.details.postponed_from', '2026-10-28')
                ->where('orders.0.details.can_reschedule', true)
                ->where('orders.0.details.due_date_changes.0.reason', 'ลูกค้าขอเลื่อน')
                ->where('orders.0.details.due_date_changes.0.by', 'พนักงานเคาน์เตอร์')
                ->where('deliveryCalendar.days.2026-11-05.orders.0.order_code', $order->order_code)
                ->where('deliveryCalendar.days.2026-11-05.orders.0.postponed_from', '2026-10-28')
                ->has('deliveryDateLoads')
                ->has('dailyProductionCapacity'));
    }

    public function test_the_production_sheets_are_told_the_date_moved(): void
    {
        $admin = $this->user(UserRole::Admin);
        $order = $this->order($admin);

        $this->actingAs($admin)
            ->post("/orders/{$order->id}/due-date", ['due_date' => '2026-10-30', 'reason' => 'ผ้ามาช้า'])
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)->get('/production/cutting')
            ->assertInertia(fn (Assert $page) => $page
                ->where('orders.0.postponed_from', '2026-10-28'));
    }

    public function test_a_reason_is_required(): void
    {
        $sales = $this->user();
        $order = $this->order($sales);

        $this->actingAs($sales)
            ->post("/orders/{$order->id}/due-date", ['due_date' => '2026-11-05', 'reason' => '  '])
            ->assertSessionHasErrors('reason');

        $this->assertSame('2026-10-28', $order->fresh()->due_date->format('Y-m-d'));
        $this->assertSame(0, OrderDueDateChange::query()->count());
    }

    public function test_a_past_date_or_the_same_date_is_refused(): void
    {
        $sales = $this->user();
        $order = $this->order($sales);

        $this->actingAs($sales)
            ->post("/orders/{$order->id}/due-date", ['due_date' => '2026-10-23', 'reason' => 'ย้อนหลัง'])
            ->assertSessionHasErrors('due_date');
        $this->actingAs($sales)
            ->post("/orders/{$order->id}/due-date", ['due_date' => '2026-10-28', 'reason' => 'วันเดิม'])
            ->assertSessionHasErrors('due_date');

        $this->assertSame(0, OrderDueDateChange::query()->count());
    }

    public function test_an_earlier_date_from_today_on_is_allowed(): void
    {
        $sales = $this->user();
        $order = $this->order($sales);

        $this->actingAs($sales)
            ->post("/orders/{$order->id}/due-date", ['due_date' => '2026-10-24', 'reason' => 'ลูกค้าขอรับเร็วขึ้น'])
            ->assertSessionHasNoErrors();

        $this->assertSame('2026-10-24', $order->fresh()->due_date->format('Y-m-d'));
    }

    public function test_a_finished_or_cancelled_bill_keeps_its_date(): void
    {
        $sales = $this->user();
        $done = $this->order($sales);
        $done->routings()->where('station_name', RoutingStationName::Shipping)->update(['status' => RoutingStatus::Completed]);
        $cancelled = $this->order($sales);
        $cancelled->update(['order_status' => OrderStatus::Cancelled]);

        foreach ([$done, $cancelled] as $order) {
            $this->actingAs($sales)
                ->post("/orders/{$order->id}/due-date", ['due_date' => '2026-11-05', 'reason' => 'ลอง'])
                ->assertForbidden();
            $this->assertSame('2026-10-28', $order->fresh()->due_date->format('Y-m-d'));
        }
    }

    public function test_only_people_who_edit_bills_and_only_in_their_branch(): void
    {
        $order = $this->order($this->user());
        $worker = $this->user(UserRole::Worker);
        $otherBranch = $this->user(UserRole::Sales, Branch::create(['branch_code' => '03', 'branch_name' => 'เมืองเลย']));

        foreach ([$worker, $otherBranch] as $user) {
            $this->actingAs($user)
                ->post("/orders/{$order->id}/due-date", ['due_date' => '2026-11-05', 'reason' => 'ลอง'])
                ->assertForbidden();
        }

        $this->assertSame(0, OrderDueDateChange::query()->count());
    }
}
