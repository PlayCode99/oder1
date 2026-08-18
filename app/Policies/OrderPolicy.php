<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    public function create(User $user): bool
    {
        return in_array($user->role, [UserRole::Admin, UserRole::Sales], true);
    }

    public function viewAny(User $user): bool
    {
        return $user->exists;
    }

    public function edit(User $user, Order $order): bool
    {
        return $this->update($user, $order);
    }

    public function update(User $user, Order $order): bool
    {
        if (! in_array($user->role, [UserRole::Admin, UserRole::Sales], true)) {
            return false;
        }

        return $order->order_status->canBeEdited();
    }

    public function confirmDesign(User $user, Order $order): bool
    {
        return in_array($user->role, [UserRole::Admin, UserRole::Sales, UserRole::Designer], true);
    }

    public function approveReceipt(User $user, Order $order): bool
    {
        return in_array($user->role, [UserRole::Admin, UserRole::Finance], true);
    }
}
