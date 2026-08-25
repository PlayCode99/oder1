<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\OrderStatus;
use Tests\TestCase;

class OrderStatusTest extends TestCase
{
    public function test_only_confirmed_orders_are_editable(): void
    {
        $this->assertFalse(OrderStatus::Draft->canBeEdited());
        $this->assertFalse(OrderStatus::Designing->canBeEdited());
        $this->assertFalse(OrderStatus::WaitingCustomerConfirm->canBeEdited());
        $this->assertTrue(OrderStatus::Confirmed->canBeEdited());
        $this->assertFalse(OrderStatus::InProduction->canBeEdited());
        $this->assertFalse(OrderStatus::QcChecking->canBeEdited());
        $this->assertFalse(OrderStatus::QcRejected->canBeEdited());
        $this->assertFalse(OrderStatus::Shipping->canBeEdited());
        $this->assertFalse(OrderStatus::Completed->canBeEdited());
        $this->assertFalse(OrderStatus::Cancelled->canBeEdited());
    }

    public function test_only_pre_production_statuses_can_enter_production(): void
    {
        $this->assertTrue(OrderStatus::Draft->canEnterProduction());
        $this->assertTrue(OrderStatus::Designing->canEnterProduction());
        $this->assertTrue(OrderStatus::WaitingCustomerConfirm->canEnterProduction());
        $this->assertTrue(OrderStatus::Confirmed->canEnterProduction());
        $this->assertFalse(OrderStatus::InProduction->canEnterProduction());
        $this->assertFalse(OrderStatus::QcChecking->canEnterProduction());
        $this->assertFalse(OrderStatus::QcRejected->canEnterProduction());
        $this->assertFalse(OrderStatus::Shipping->canEnterProduction());
        $this->assertFalse(OrderStatus::Completed->canEnterProduction());
        $this->assertFalse(OrderStatus::Cancelled->canEnterProduction());
    }
}
