<?php

namespace App\Domain\OrderManagement\Actions\GarmentPricing;

use App\Models\GarmentOperation;

class DeleteGarmentOperationAction
{
    public function execute(GarmentOperation $operation): void
    {
        $operation->delete();
    }
}
