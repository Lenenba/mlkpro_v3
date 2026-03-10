<?php

namespace App\Http\Requests\Works;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExtraQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $user = $this->user();
        $accountId = (int) ($user?->accountOwnerId() ?? $user?->id ?? 0);
        $itemType = $user?->company_type === 'products'
            ? Product::ITEM_TYPE_PRODUCT
            : Product::ITEM_TYPE_SERVICE;

        return [
            'job_title' => ['required', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['draft', 'sent', 'accepted', 'declined'])],
            'product' => ['required', 'array', 'min:1'],
            'product.*.id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->where(fn ($query) => $query
                    ->where('user_id', $accountId)
                    ->where('item_type', $itemType)),
            ],
            'product.*.quantity' => ['required', 'integer', 'min:1'],
            'product.*.price' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'messages' => ['nullable', 'string'],
            'taxes' => ['nullable', 'array'],
            'taxes.*' => ['integer', Rule::exists('taxes', 'id')],
        ];
    }
}
