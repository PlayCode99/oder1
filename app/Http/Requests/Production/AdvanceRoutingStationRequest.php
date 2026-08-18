<?php

declare(strict_types=1);

namespace App\Http\Requests\Production;

use App\Enums\RoutingStationName;
use App\Enums\RoutingStatus;
use App\Enums\StationDepartment;
use App\Enums\UserRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdvanceRoutingStationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        if (in_array($user->role, [UserRole::Admin, UserRole::ProductionManager, UserRole::Qc], true)) {
            return true;
        }

        $station = RoutingStationName::tryFrom((string) $this->input('station_name'));
        if ($station === null) {
            return false;
        }

        // Counter operators (sales) can route production-room flows and immediate next-room dispatches.
        if (
            $user->role === UserRole::Sales
            && in_array($station, [
                RoutingStationName::Print,
                RoutingStationName::Embroidery,
                RoutingStationName::Screen,
                RoutingStationName::Flex,
                RoutingStationName::Cutting,
                RoutingStationName::Sewing,
            ], true)
        ) {
            return true;
        }

        if (
            in_array($user->role, [UserRole::ProductionManager, UserRole::Qc], true)
            && in_array($station, [
                RoutingStationName::Print,
                RoutingStationName::Embroidery,
                RoutingStationName::Screen,
                RoutingStationName::Flex,
                RoutingStationName::Cutting,
                RoutingStationName::Sewing,
            ], true)
        ) {
            return true;
        }

        $department = match ($station) {
            RoutingStationName::Design => StationDepartment::Design,
            RoutingStationName::Print => StationDepartment::Print,
            RoutingStationName::Embroidery => StationDepartment::Embroidery,
            RoutingStationName::Screen => StationDepartment::Screen,
            RoutingStationName::Flex => StationDepartment::Flex,
            RoutingStationName::Cutting => StationDepartment::Cutting,
            RoutingStationName::Sewing => StationDepartment::Sewing,
            RoutingStationName::Qc => StationDepartment::Qc,
            RoutingStationName::Shipping => StationDepartment::None,
        };

        return $user->station_department === $department;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'station_name' => ['required', 'string', Rule::in(array_column(RoutingStationName::cases(), 'value'))],
            'new_status' => ['required', 'string', Rule::in(array_column(RoutingStatus::cases(), 'value'))],
            'direct_complete' => ['nullable', 'boolean'],
            'print_machine' => [
                'nullable',
                'string',
                Rule::in(['printer_1', 'printer_2', 'printer_3']),
            ],
            'cutting_team_id' => [
                Rule::requiredIf(function (): bool {
                    return (string) $this->input('station_name') === RoutingStationName::Cutting->value
                        && (string) $this->input('new_status') === RoutingStatus::InProgress->value;
                }),
                'nullable',
                'integer',
                Rule::exists('cutting_teams', 'id'),
            ],
            'sewing_team_id' => [
                'nullable',
                'integer',
                Rule::exists('sewing_teams', 'id'),
            ],
            'embroidery_team_id' => [
                Rule::requiredIf(function (): bool {
                    return (string) $this->input('station_name') === RoutingStationName::Embroidery->value
                        && (string) $this->input('new_status') === RoutingStatus::InProgress->value;
                }),
                'nullable',
                'integer',
                Rule::exists('embroidery_teams', 'id'),
            ],
            'screen_team_id' => [
                'nullable',
                'integer',
                Rule::exists('screen_teams', 'id'),
            ],
            'heat_press_machine_id' => [
                'nullable',
                'integer',
                Rule::exists('heat_press_machines', 'id'),
            ],
            'rework_note' => [
                Rule::requiredIf(function (): bool {
                    return in_array((string) $this->input('station_name'), [RoutingStationName::Cutting->value, RoutingStationName::Sewing->value, RoutingStationName::Embroidery->value, RoutingStationName::Screen->value, RoutingStationName::Flex->value], true)
                        && (string) $this->input('new_status') === RoutingStatus::Rejected->value;
                }),
                'nullable',
                'string',
                'max:255',
            ],
            'shipping_delivery_info' => ['nullable', 'array'],
            'shipping_delivery_info.carrier_name' => ['nullable', 'string', 'max:255'],
            'shipping_delivery_info.tracking_no' => ['nullable', 'string', 'max:255'],
            'shipping_delivery_info.parcel_weight_kg' => ['nullable', 'string', 'max:50'],
            'shipping_delivery_info.parcel_shipping_cost' => ['nullable', 'string', 'max:50'],
            'shipping_delivery_info.onsite_sender_name' => ['nullable', 'string', 'max:255'],
            'shipping_delivery_info.onsite_vehicle_plate' => ['nullable', 'string', 'max:255'],
            'shipping_delivery_info.sender_signature' => ['nullable', 'string', 'max:255'],
        ];
    }
}
