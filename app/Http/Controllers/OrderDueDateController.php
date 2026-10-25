<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderDueDateChange;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "เลื่อนส่ง": moves a bill's delivery date. The bill keeps one date, which
 * every sheet, receipt and calendar reads, so they all follow the move; the
 * move itself is kept, with its reason, for the timeline and for the note on
 * sheets printed after it.
 */
class OrderDueDateController extends Controller
{
    public function update(Request $request, Order $order): RedirectResponse
    {
        $this->authorize('reschedule', $order);

        $validated = $request->validate([
            'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'reason' => ['required', 'string', 'max:500'],
        ], [
            'due_date.required' => 'กรุณาเลือกวันส่งใหม่',
            'due_date.after_or_equal' => 'เลือกวันที่ผ่านมาแล้วไม่ได้',
            'reason.required' => 'กรุณากรอกเหตุผลที่เลื่อนส่ง',
            'reason.max' => 'เหตุผลยาวได้ไม่เกิน 500 ตัวอักษร',
        ]);

        $from = $order->due_date?->format('Y-m-d');
        $to = (string) $validated['due_date'];

        if ($from === $to) {
            return back()->withErrors(['due_date' => 'วันส่งใหม่ตรงกับวันเดิม']);
        }

        DB::transaction(function () use ($order, $from, $to, $validated, $request): void {
            // The bill's dates are the shop's wall clock with no time of day,
            // stored as midnight — the same shape the order form saves.
            $order->forceFill(['due_date' => Carbon::createFromFormat('Y-m-d', $to)->startOfDay()])->save();

            OrderDueDateChange::query()->create([
                'order_id' => $order->id,
                'from_date' => $from ?? $to,
                'to_date' => $to,
                'reason' => trim((string) $validated['reason']),
                'changed_by' => $request->user()?->id,
            ]);
        });

        return back()->with('success', 'เลื่อนส่ง '.$order->order_code.' เรียบร้อยแล้ว');
    }
}
