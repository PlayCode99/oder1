<?php

namespace App\Domain\OrderManagement\Actions\ShirtData;

use App\Models\SewingOperation;

class DeleteSewingOperationAction
{
    public function execute(SewingOperation $sewingOperation): void
    {
        $sewingOperation->delete();
    }
}
