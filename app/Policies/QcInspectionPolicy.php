<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\StationDepartment;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\User;

class QcInspectionPolicy
{
    public function inspect(User $user, Order $order): bool
    {
        $role = $user->role instanceof UserRole ? $user->role : UserRole::tryFrom((string) $user->role);
        $station = $user->station_department instanceof StationDepartment
            ? $user->station_department
            : StationDepartment::tryFrom((string) $user->station_department);

        return in_array($role, [UserRole::Admin, UserRole::ProductionManager, UserRole::Qc], true)
            || $station === StationDepartment::Qc;
    }
}
