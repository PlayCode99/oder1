<?php

use App\Enums\AccessRole;

return [
    'dashboard_menu' => 'dashboard',
    'branch_cross_view_code' => '01',
    'menus_by_role' => [
        AccessRole::Counter->value => ['counter', 'delivery'],
        AccessRole::CuttingStaff->value => ['cutting'],
        AccessRole::PrintingStaff->value => ['printing'],
        AccessRole::PressStaff->value => ['pressing'],
        AccessRole::EmbroideryStaff->value => ['embroidery'],
        AccessRole::SewingStaff->value => ['sewing'],
        AccessRole::ScreenFlexStaff->value => ['screen_flex'],
        AccessRole::QcStaff->value => ['qc'],
        AccessRole::DeliveryStaff->value => ['delivery'],
        AccessRole::AdminProduction->value => ['cutting', 'printing', 'pressing', 'embroidery', 'sewing', 'screen_flex', 'qc', 'delivery'],
        AccessRole::AdminSystem->value => ['counter', 'cutting', 'printing', 'pressing', 'embroidery', 'sewing', 'screen_flex', 'qc', 'delivery', 'orders', 'settings_data', 'users_management', 'production'],
        AccessRole::Owner->value => ['*'],
    ],
];
