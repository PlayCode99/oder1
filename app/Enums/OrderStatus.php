<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Draft = 'draft';
    case Designing = 'designing';
    case WaitingCustomerConfirm = 'waiting_customer_confirm';
    case Confirmed = 'confirmed';
    case InProduction = 'in_production';
    case QcChecking = 'qc_checking';
    case QcRejected = 'qc_rejected';
    case Shipping = 'shipping';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function canBeEdited(): bool
    {
        return $this === self::Confirmed;
    }

    public function canEnterProduction(): bool
    {
        return in_array($this, [
            self::Draft,
            self::Designing,
            self::WaitingCustomerConfirm,
            self::Confirmed,
        ], true);
    }
}
