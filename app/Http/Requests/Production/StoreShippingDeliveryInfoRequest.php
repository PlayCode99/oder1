<?php

declare(strict_types=1);

namespace App\Http\Requests\Production;

use App\Enums\UserRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreShippingDeliveryInfoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        return in_array($user->role, [UserRole::Admin, UserRole::ProductionManager, UserRole::Qc, UserRole::Sales], true);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'carrier_name' => ['nullable', 'string', 'max:255'],
            'tracking_no' => ['nullable', 'string', 'max:255'],
            'parcel_weight_kg' => ['nullable', 'string', 'max:50'],
            'parcel_shipping_cost' => ['nullable', 'string', 'max:50'],
            'onsite_sender_name' => ['nullable', 'string', 'max:255'],
            'onsite_vehicle_plate' => ['nullable', 'string', 'max:255'],
            'sender_signature' => ['nullable', 'string', 'max:255'],
        ];
    }
}
