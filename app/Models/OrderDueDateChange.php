<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One move of a bill's delivery date.
 *
 * @property int $id
 * @property int $order_id
 * @property Carbon $from_date
 * @property Carbon $to_date
 * @property string $reason
 * @property int|null $changed_by
 */
class OrderDueDateChange extends Model
{
    protected $fillable = ['order_id', 'from_date', 'to_date', 'reason', 'changed_by'];

    protected function casts(): array
    {
        return [
            'from_date' => 'date',
            'to_date' => 'date',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    /**
     * As the counter's timeline and the printed sheets read it.
     *
     * @return array{from: string, to: string, reason: string, by: string, at: string|null}
     */
    public function toDisplay(): array
    {
        return [
            'from' => $this->from_date->format('Y-m-d'),
            'to' => $this->to_date->format('Y-m-d'),
            'reason' => $this->reason,
            'by' => $this->changedBy?->name ?? '-',
            'at' => $this->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
