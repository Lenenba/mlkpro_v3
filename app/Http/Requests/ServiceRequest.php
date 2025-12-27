<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $accountId = $this->user()?->accountOwnerId();

        return [
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'margin_percent' => 'nullable|numeric|min:0|max:100',
            'description' => 'nullable|string',
            'category_id' => [
                'required',
                Rule::exists('product_categories', 'id')->where(function ($query) use ($accountId) {
                    if (!$accountId) {
                        return;
                    }

                    $query->where(function ($query) use ($accountId) {
                        $query->where('user_id', $accountId)
                            ->orWhereNull('user_id');
                    });
                }),
            ],
            'sku' => 'nullable|string|max:100',
            'barcode' => 'nullable|string|max:100',
            'unit' => 'nullable|string|max:50',
            'supplier_name' => 'nullable|string|max:255',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'is_active' => 'nullable|boolean',
            'image' => 'nullable|image|mimes:jpg,png,jpeg,webp|max:5000',
            'materials' => 'nullable|array',
            'materials.*.id' => 'nullable|integer',
            'materials.*.product_id' => 'nullable|integer|exists:products,id',
            'materials.*.label' => 'nullable|string|max:255',
            'materials.*.description' => 'nullable|string|max:2000',
            'materials.*.unit' => 'nullable|string|max:50',
            'materials.*.quantity' => 'nullable|numeric|min:0',
            'materials.*.unit_price' => 'nullable|numeric|min:0',
            'materials.*.billable' => 'nullable|boolean',
            'materials.*.sort_order' => 'nullable|integer|min:0',
        ];
    }
}
