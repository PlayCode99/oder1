<?php

namespace Tests\Feature\Domain\OrderManagement;

use App\Domain\OrderManagement\Actions\CreateOrderAction;
use App\Domain\OrderManagement\Actions\UpdateOrderAction;
use App\Domain\Production\Actions\AdvanceRoutingStationAction;
use App\Enums\RoutingStationName;
use App\Enums\RoutingStatus;
use App\Enums\StationDepartment;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\OrderRouting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateOrderActionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Regression test for the critical bug where editing an order (e.g. to fix
     * a customer phone number) wiped every order_routings row back to "pending",
     * destroying in-progress/completed production state. UpdateOrderAction must
     * never delete or recreate routings — only AdvanceRoutingStationAction
     * (production flow) and ProcessQcInspectionAction (QC) are allowed to.
     */
    public function test_editing_an_order_never_resets_routing_progress_already_in_production(): void
    {
        $customer = Customer::create([
            'customer_code' => 'CUS-UPD-0001',
            'customer_name' => 'Update Regression Customer',
        ]);

        $branch = Branch::create([
            'branch_code' => 'BR-UPD-0001',
            'branch_name' => 'Update Regression Branch',
        ]);

        $creator = User::factory()->create([
            'role' => UserRole::Sales,
            'station_department' => StationDepartment::None,
        ]);

        $order = (new CreateOrderAction())->execute([
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'job_name' => 'Regression Order',
            'job_type' => 'งานปัก',
            'order_date' => '2026-08-01 09:00:00',
            'due_date' => '2026-08-10 18:00:00',
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
        ], $creator->id);

        // Advance the first station (cutting) to in_progress, then complete it,
        // and start the second station (embroidery), to simulate real production
        // progress that must never be lost by an unrelated order edit.
        $cutting = (new AdvanceRoutingStationAction())->execute(
            $order, RoutingStationName::Cutting, RoutingStatus::InProgress, $creator->id,
        );
        $cutting = (new AdvanceRoutingStationAction())->execute(
            $order, RoutingStationName::Cutting, RoutingStatus::Completed, $creator->id,
        );
        $embroidery = (new AdvanceRoutingStationAction())->execute(
            $order, RoutingStationName::Embroidery, RoutingStatus::InProgress, $creator->id,
        );

        $this->assertSame(RoutingStatus::Completed, $cutting->status);
        $this->assertNotNull($cutting->completed_at);
        $this->assertSame(RoutingStatus::InProgress, $embroidery->status);
        $this->assertNotNull($embroidery->started_at);

        $routingCountBeforeEdit = OrderRouting::query()->where('order_id', $order->id)->count();
        $this->assertGreaterThan(0, $routingCountBeforeEdit);

        // Simulate the counter staff editing unrelated fields (phone number, discount)
        // on the order while it is mid-production, exactly like the real UI does:
        // it recomputes and resubmits a 'routings' array derived from job_type.
        $updatedOrder = (new UpdateOrderAction())->execute($order, [
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'job_name' => 'Regression Order (updated)',
            'job_type' => 'งานปัก',
            'order_date' => '2026-08-01 09:00:00',
            'due_date' => '2026-08-12 18:00:00',
            'discount_percent' => 5,
            'items' => [
                [
                    'item_type' => 'shirt',
                    'size_group' => 'adults',
                    'size_label' => 'L',
                    'quantity' => 10,
                    'unit_price' => 50,
                ],
            ],
            // Frontend always resubmits a freshly recomputed routing plan; the
            // action must ignore it entirely and keep existing routings intact.
            'routings' => ['cutting', 'embroidery', 'sewing', 'qc', 'shipping'],
        ], $creator->id);

        $this->assertSame(
            $routingCountBeforeEdit,
            OrderRouting::query()->where('order_id', $order->id)->count(),
            'Editing an order must not change the number of routing rows.',
        );

        $cutting->refresh();
        $embroidery->refresh();

        $this->assertSame(RoutingStatus::Completed, $cutting->status, 'Completed station must stay completed after an order edit.');
        $this->assertNotNull($cutting->completed_at);
        $this->assertSame(RoutingStatus::InProgress, $embroidery->status, 'In-progress station must stay in progress after an order edit.');
        $this->assertNotNull($embroidery->started_at);

        // The unrelated fields the edit actually targeted should still be applied.
        $this->assertSame('Regression Order (updated)', $updatedOrder->job_name);
        $this->assertSame('2026-08-12 18:00:00', $updatedOrder->due_date->format('Y-m-d H:i:s'));
    }
}
