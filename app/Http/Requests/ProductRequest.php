<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $accountId = $this->user()?->accountOwnerId();

        return [
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'margin_percent' => 'nullable|numeric|min:0|max:100',
            'description' => 'nullable|string',
            'tracking_type' => 'nullable|in:none,lot,serial',
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
            'stock' => 'required|integer|min:0',
            'minimum_stock' => 'required|integer|min:0',
            'sku' => 'nullable|string|max:100',
            'barcode' => 'nullable|string|max:100',
            'unit' => 'nullable|string|max:50',
            'supplier_name' => 'nullable|string|max:255',
            'supplier_email' => 'nullable|email|max:255',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'is_active' => 'nullable|boolean',
            'image' => 'nullable|image|mimes:jpg,png,jpeg,webp|max:5000',
            'image_url' => [
                'nullable',
                'string',
                'max:2048',
                function ($attribute, $value, $fail) {
                    if ($value === null || $value === '') {
                        return;
                    }

                    $trimmed = trim((string) $value);
                    if ($trimmed === '') {
                        return;
                    }

                    if (filter_var($trimmed, FILTER_VALIDATE_URL)) {
                        return;
                    }

                    if (str_starts_with($trimmed, '/')
                        || str_starts_with($trimmed, 'storage/')
                        || str_starts_with($trimmed, 'products/')) {
                        return;
                    }

                    $fail('The image url field must be a valid URL.');
                },
            ],
            'images' => 'nullable|array',
            'images.*' => 'image|mimes:jpg,png,jpeg,webp|max:5000',
            'remove_image_ids' => 'nullable|array',
            'remove_image_ids.*' => 'integer',
        ];
    }
}
