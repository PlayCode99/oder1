<?php

namespace App\Enums;

enum CuttingOrderStatus: string
{
    case Draft = 'draft';
    case Cutting = 'cutting';
    case Inspected = 'inspected';
    case Completed = 'completed';
}
