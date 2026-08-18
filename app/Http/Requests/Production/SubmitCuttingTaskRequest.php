<?php

declare(strict_types=1);

namespace App\Http\Requests\Production;

use App\Enums\StationDepartment;
use App\Enums\UserRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SubmitCuttingTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        if (in_array($user->role, [UserRole::Admin, UserRole::ProductionManager], true)) {
            return true;
        }

        return $user->role === UserRole::Worker
            && in_array($user->station_department, [StationDepartment::Cutting, StationDepartment::Sewing], true);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'cutting_order_id' => ['required', 'integer', 'exists:cutting_orders,id'],
            'price_master_id' => ['required', 'integer', 'exists:piecework_prices,id'],
            'worker_user_id' => ['required', 'integer', 'exists:users,id'],
            'quantity_done' => ['required', 'integer', 'min:1'],
        ];
    }
}
