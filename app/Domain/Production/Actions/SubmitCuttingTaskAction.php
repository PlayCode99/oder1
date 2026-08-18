<?php

declare(strict_types=1);

namespace App\Domain\Production\Actions;

use App\Models\CuttingOrder;
use App\Models\CuttingWorkerTask;
use App\Models\PieceworkPrice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitCuttingTaskAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(array $data, int $workerUserId): CuttingWorkerTask
    {
        return DB::transaction(function () use ($data, $workerUserId): CuttingWorkerTask {
            $cuttingOrder = CuttingOrder::with('order.items')->findOrFail((int) $data['cutting_order_id']);
            $priceMaster = PieceworkPrice::findOrFail((int) $data['price_master_id']);

            $quantityDone = (int) $data['quantity_done'];
            $totalWage = $quantityDone * (float) $priceMaster->price_per_unit;

            $totalRequired = (int) $cuttingOrder->order->items->sum('quantity');
            $existingDone = (int) CuttingWorkerTask::where('cutting_order_id', $cuttingOrder->id)->sum('quantity_done');

            if (($existingDone + $quantityDone) > $totalRequired) {
                throw ValidationException::withMessages([
                    'quantity_done' => 'Piecework claim rejected: Claimed quantity exceeds the total manufactured order limit.',
                ]);
            }

            return CuttingWorkerTask::create([
                'cutting_order_id' => $cuttingOrder->id,
                'price_master_id' => $priceMaster->id,
                'worker_user_id' => $workerUserId,
                'quantity_done' => $quantityDone,
                'total_wage' => $totalWage,
            ]);
        });
    }
}
