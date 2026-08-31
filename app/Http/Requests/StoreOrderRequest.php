<?php

namespace App\Http\Requests;

use App\Enums\RoutingStationName;
use App\Models\Order;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', Order::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
            'contact_detail' => ['nullable', 'string', 'max:255'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'job_name' => ['required', 'string', 'max:255'],
            'job_type' => ['required', 'string'],
            'delivery_method' => ['nullable', 'string', Rule::in(['pickup', 'shipping', 'onsite'])],
            'shipping_address' => ['nullable', 'string'],
            'order_date' => ['required', 'date'],
            // Compared by day, not by timestamp: the bill carries the real time it
            // was opened (e.g. 15:28) while a delivery date is always midnight, so
            // a plain after_or_equal would reject same-day delivery.
            'due_date' => ['required', 'date', function (string $attribute, mixed $value, callable $fail): void {
                $orderDate = $this->input('order_date');

                if (! is_string($orderDate) || $orderDate === '' || ! is_string($value) || $value === '') {
                    return;
                }

                try {
                    $due = Carbon::parse($value)->startOfDay();
                    $opened = Carbon::parse($orderDate)->startOfDay();
                } catch (\Throwable) {
                    return;
                }

                if ($due->lt($opened)) {
                    $fail('วันที่รับสินค้าต้องไม่ก่อนวันที่เปิดบิล');
                }
            }],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'deposit_amount' => ['nullable', 'numeric', 'min:0'],
            'payment_method' => ['nullable', 'string', Rule::in(['cash', 'transfer'])],
            'design_artwork' => ['nullable', 'file', 'mimes:webp,png,jpg,pdf', 'max:5120'],
            'shirt_artwork' => ['nullable', 'array'],
            'shirt_artwork.*' => ['file', 'image', 'mimes:webp,png,jpg,jpeg', 'max:5120'],
            'pants_artwork' => ['nullable', 'array'],
            'pants_artwork.*' => ['file', 'image', 'mimes:webp,png,jpg,jpeg', 'max:5120'],
            'reference_designs' => ['nullable', 'array'],
            'reference_designs.*' => ['file', 'mimes:webp,png,jpg,pdf', 'max:5120'],

            // Set when the form was opened via "เปิดบิลอีกครั้ง": the artwork of
            // the source order is copied onto the new one server-side, because
            // the browser only ever holds display URLs for already-saved images.
            'duplicate_from_id' => ['nullable', 'integer', 'exists:orders,id'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.item_type' => ['required', 'string'],
            'items.*.size_group' => ['required', 'string', Rule::in(['kids', 'adults', 'oversize'])],
            'items.*.size_label' => ['required', 'string', 'max:50'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],

            'specification' => ['sometimes', 'array'],
            'specification.pattern_id' => ['required', 'integer'],
            'specification.fabric_id' => ['required', 'integer'],
            'specification.neck_style_id' => ['nullable', 'integer'],
            'specification.collar_color' => ['nullable', 'string', 'max:255'],
            'specification.leg_style' => ['nullable', 'string', 'max:255'],
            'specification.leg_hem' => ['nullable', 'string', 'max:255'],
            'specification.placket_style' => ['nullable', 'string', 'max:255'],
            'specification.placket_color' => ['nullable', 'string', 'max:255'],
            'specification.sleeve_style' => ['nullable', 'string', 'max:255'],
            'specification.sleeve_hem' => ['nullable', 'string', 'max:255'],
            'specification.sublimation_detail' => ['nullable', 'string'],
            'specification.screen_print_detail' => ['required', 'string'],
            'specification.embroidery_code' => ['nullable', 'string', 'max:255'],

            'routings' => ['sometimes', 'array', 'min:1'],
            'routings.*' => ['required', 'string', Rule::in(array_column(RoutingStationName::cases(), 'value'))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'design_artwork.mimes' => 'ไฟล์ Art Work ทั่วไปต้องเป็นชนิด webp, png, jpg หรือ pdf เท่านั้น',
            'design_artwork.max' => 'ไฟล์ Art Work ทั่วไปต้องมีขนาดไม่เกิน 5MB',
            'shirt_artwork.*.image' => 'ไฟล์ Art Work เสื้อ ต้องเป็นไฟล์รูปภาพเท่านั้น',
            'shirt_artwork.*.mimes' => 'ไฟล์ Art Work เสื้อ ต้องเป็นชนิด webp, png หรือ jpg เท่านั้น',
            'shirt_artwork.*.max' => 'ไฟล์ Art Work เสื้อ ต้องมีขนาดไม่เกิน 5MB',
            'pants_artwork.*.image' => 'ไฟล์ Art Work กางเกง ต้องเป็นไฟล์รูปภาพเท่านั้น',
            'pants_artwork.*.mimes' => 'ไฟล์ Art Work กางเกง ต้องเป็นชนิด webp, png หรือ jpg เท่านั้น',
            'pants_artwork.*.max' => 'ไฟล์ Art Work กางเกง ต้องมีขนาดไม่เกิน 5MB',
        ];
    }
}
