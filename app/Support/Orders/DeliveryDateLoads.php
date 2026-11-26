<?php

namespace App\Support\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\ProductionDailySetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * How many pieces are already due on each day from today on, which the date
 * pickers show as "37/400" against the shop's daily capacity. Shared by the
 * order form and the counter's postpone dialog, so both count the same way.
 */
final class DeliveryDateLoads
{
    /**
     * @return Collection<int, array{date: string, total_quantity: int}>
     */
    public static function from(?int $excludedOrderId = null): Collection
    {
        return Order::query()
            ->whereNotIn('order_status', [OrderStatus::Cancelled, OrderStatus::Completed])
            ->when($excludedOrderId !== null, fn ($query) => $query->whereKeyNot($excludedOrderId))
            ->whereDate('due_date', '>=', today())
            ->join('order_items', 'orders.id', '=', 'order_items.order_id')
            ->selectRaw('DATE(orders.due_date) as due_date, SUM(order_items.quantity) as total_quantity')
            ->groupByRaw('DATE(orders.due_date)')
            ->orderBy('due_date')
            // Aggregate rows, not orders: drop to the query builder so the
            // computed columns are read from a plain row object.
            ->toBase()
            ->get()
            ->map(fn (object $row): array => [
                'date' => Carbon::parse((string) $row->due_date)->toDateString(),
                'total_quantity' => (int) $row->total_quantity,
            ])
            ->values();
    }

    public static function dailyCapacity(): int
    {
        return (int) (ProductionDailySetting::query()->first()->daily_capacity ?? 200);
    }
}
