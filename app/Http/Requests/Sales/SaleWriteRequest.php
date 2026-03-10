<?php

namespace App\Http\Requests\Sales;

use App\Models\Sale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class SaleWriteRequest extends FormRequest
{
    protected function accountId(): int
    {
        $user = $this->user();

        return (int) ($user?->accountOwnerId() ?? $user?->id ?? 0);
    }

    protected function saleStatuses(): array
    {
        return [
            Sale::STATUS_DRAFT,
            Sale::STATUS_PENDING,
            Sale::STATUS_PAID,
            Sale::STATUS_CANCELED,
        ];
    }

    protected function fulfillmentStatuses(): array
    {
        return [
            Sale::FULFILLMENT_PENDING,
            Sale::FULFILLMENT_PREPARING,
            Sale::FULFILLMENT_OUT_FOR_DELIVERY,
            Sale::FULFILLMENT_READY_FOR_PICKUP,
            Sale::FULFILLMENT_COMPLETED,
            Sale::FULFILLMENT_CONFIRMED,
        ];
    }

    protected function itemRules(): array
    {
        $accountId = $this->accountId();

        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->where(fn ($query) => $query->where('user_id', $accountId)),
            ],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
            'items.*.description' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function customerRule(): array
    {
        $accountId = $this->accountId();

        return [
            'nullable',
            'integer',
            Rule::exists('customers', 'id')->where(fn ($query) => $query->where('user_id', $accountId)),
        ];
    }
}
