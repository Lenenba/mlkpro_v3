<?php

namespace App\Http\Requests\Quotes;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class QuoteWriteRequest extends FormRequest
{
    protected function accountId(): int
    {
        $user = $this->user();

        return (int) ($user?->accountOwnerId() ?? $user?->id ?? 0);
    }

    protected function quoteRules(bool $requireCustomer = true): array
    {
        $accountId = $this->accountId();

        return [
            'job_title' => ['required', 'string'],
            'property_id' => ['nullable', 'integer'],
            'customer_id' => [
                $requireCustomer ? 'required' : 'nullable',
                'integer',
                Rule::exists('customers', 'id')->where(fn ($query) => $query->where('user_id', $accountId)),
            ],
            'status' => ['nullable', Rule::in(['draft', 'sent', 'accepted', 'declined'])],
            'product' => ['required', 'array', 'min:1'],
            'product.*.id' => [
                'nullable',
                'integer',
                Rule::exists('products', 'id')->where(fn ($query) => $query->where('user_id', $accountId)),
            ],
            'product.*.item_type' => ['nullable', Rule::in([Product::ITEM_TYPE_PRODUCT, Product::ITEM_TYPE_SERVICE])],
            'product.*.name' => ['required_without:product.*.id', 'string'],
            'product.*.description' => ['nullable', 'string'],
            'product.*.quantity' => ['required', 'integer', 'min:1'],
            'product.*.price' => ['required', 'numeric', 'min:0'],
            'product.*.source_details' => ['nullable'],
            'notes' => ['nullable', 'string'],
            'messages' => ['nullable', 'string'],
            'initial_deposit' => ['nullable', 'numeric', 'min:0'],
            'taxes' => ['nullable', 'array'],
            'taxes.*' => ['integer', Rule::exists('taxes', 'id')],
        ];
    }
}
