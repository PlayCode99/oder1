<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\StationDepartment;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Same-day delivery: the bill is opened at a real clock time (e.g. 15:28) while
 * the delivery date is only ever a date, so it arrives as 00:00. Comparing the
 * two as full timestamps makes "delivered today" look like it happens before
 * the order was taken, which it does not — the rule is a day-level one.
 */
class OrderDueDateValidationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var array<string, string>
     */
    private const SPECIFICATION = [
        'pattern_id' => '1',
        'fabric_id' => '1',
        'screen_print_detail' => '{"schema":"spec-v2"}',
    ];

    private function payload(string $orderDate, string $dueDate): array
    {
        $customer = Customer::firstOrCreate(
            ['customer_code' => 'CUS-DUE-1'],
            ['customer_name' => 'Due Date Customer'],
        );
        $branch = Branch::firstOrCreate(
            ['branch_code' => 'BR-DUE-1'],
            ['branch_name' => 'Due Date Branch'],
        );

        return [
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'branch_id' => $branch->id,
            'job_name' => 'Due Date Order',
            'job_type' => 'งานปัก',
            'order_date' => $orderDate,
            'due_date' => $dueDate,
            'discount_percent' => 0,
            'items' => [[
                'item_type' => 'shirt', 'size_group' => 'adults', 'size_label' => 'L',
                'quantity' => 5, 'unit_price' => 50,
            ]],
            'specification' => self::SPECIFICATION,
        ];
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role' => UserRole::Admin,
            'station_department' => StationDepartment::None,
        ]);
    }

    public function test_same_day_delivery_is_accepted_even_though_the_bill_has_a_clock_time(): void
    {
        $response = $this->actingAs($this->admin())
            ->post('/orders', $this->payload('2026-08-31 15:28:00', '2026-08-31 00:00:00'));

        $response->assertSessionHasNoErrors();
        $this->assertSame(1, Order::query()->count());
    }

    public function test_a_delivery_date_before_the_billing_date_is_still_rejected(): void
    {
        $response = $this->actingAs($this->admin())
            ->post('/orders', $this->payload('2026-08-31 15:28:00', '2026-08-30 00:00:00'));

        $response->assertSessionHasErrors('due_date');
        $this->assertSame(0, Order::query()->count());
    }

    public function test_a_later_delivery_date_is_accepted(): void
    {
        $response = $this->actingAs($this->admin())
            ->post('/orders', $this->payload('2026-08-31 15:28:00', '2026-09-05 00:00:00'));

        $response->assertSessionHasNoErrors();
        $this->assertSame(1, Order::query()->count());
    }
}
