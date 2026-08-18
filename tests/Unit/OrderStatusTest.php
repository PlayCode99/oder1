<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\OrderStatus;
use Tests\TestCase;

class OrderStatusTest extends TestCase
{
    public function test_all_order_statuses_are_editable(): void
    {
        $this->assertTrue(OrderStatus::Draft->canBeEdited());
        $this->assertTrue(OrderStatus::Designing->canBeEdited());
        $this->assertTrue(OrderStatus::WaitingCustomerConfirm->canBeEdited());
        $this->assertTrue(OrderStatus::Confirmed->canBeEdited());
        $this->assertTrue(OrderStatus::InProduction->canBeEdited());
        $this->assertTrue(OrderStatus::QcChecking->canBeEdited());
        $this->assertTrue(OrderStatus::QcRejected->canBeEdited());
        $this->assertTrue(OrderStatus::Shipping->canBeEdited());
        $this->assertTrue(OrderStatus::Completed->canBeEdited());
        $this->assertTrue(OrderStatus::Cancelled->canBeEdited());
    }
}
