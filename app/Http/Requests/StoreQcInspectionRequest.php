<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\StationDepartment;
use App\Enums\UserRole;
use App\Models\Order;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreQcInspectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Order|null $order */
        $order = $this->route('order');
        $user = $this->user();

        if ($user === null || $order === null) {
            return false;
        }

        if (in_array($user->role, [UserRole::Qc, UserRole::Admin, UserRole::ProductionManager], true)) {
            return true;
        }

        return $user->station_department === StationDepartment::Qc;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'in:pass,reject'],
            'target_station' => ['nullable', 'string'],
            'remark' => ['required_if:decision,reject', 'string'],
        ];
    }
}
