<?php

namespace App\Http\Requests;

use App\Enums\PromotionDiscountType;
use App\Enums\PromotionStatus;
use App\Enums\PromotionTargetType;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $accountId = $this->accountId();
        $promotion = $this->route('promotion');

        $targetTypeRules = ['required', Rule::in(PromotionTargetType::values())];
        $targetIdRules = ['nullable', 'integer'];

        $targetType = PromotionTargetType::tryFrom((string) $this->input('target_type'));
        if ($targetType?->requiresTargetId()) {
            $targetIdRules[0] = 'required';
        }

        if ($targetType === PromotionTargetType::CLIENT) {
            $targetIdRules[] = Rule::exists('customers', 'id')->where(
                fn ($query) => $query->where('user_id', $accountId)
            );
        }

        if ($targetType === PromotionTargetType::PRODUCT) {
            $targetIdRules[] = Rule::exists('products', 'id')->where(
                fn ($query) => $query
                    ->where('user_id', $accountId)
                    ->where('item_type', Product::ITEM_TYPE_PRODUCT)
            );
        }

        if ($targetType === PromotionTargetType::SERVICE) {
            $targetIdRules[] = Rule::exists('products', 'id')->where(
                fn ($query) => $query
                    ->where('user_id', $accountId)
                    ->where('item_type', Product::ITEM_TYPE_SERVICE)
            );
        }

        $discountType = PromotionDiscountType::tryFrom((string) $this->input('discount_type'));
        $discountValueRules = ['required', 'numeric', 'gt:0'];
        if ($discountType === PromotionDiscountType::PERCENTAGE) {
            $discountValueRules[] = 'max:100';
        }

        return [
            'name' => ['required', 'string', 'max:150'],
            'code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('promotions', 'code')
                    ->where(fn ($query) => $query->where('user_id', $accountId))
                    ->ignore($promotion?->id),
            ],
            'target_type' => $targetTypeRules,
            'target_id' => $targetIdRules,
            'discount_type' => ['required', Rule::in(PromotionDiscountType::values())],
            'discount_value' => $discountValueRules,
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'status' => ['required', Rule::in(PromotionStatus::values())],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'minimum_order_amount' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $code = trim((string) $this->input('code', ''));

        $this->merge([
            'code' => $code !== '' ? mb_strtoupper($code) : null,
            'target_id' => $this->input('target_type') === PromotionTargetType::GLOBAL->value
                ? null
                : $this->input('target_id'),
            'usage_limit' => $this->normalizeNullableNumber($this->input('usage_limit')),
            'minimum_order_amount' => $this->normalizeNullableNumber($this->input('minimum_order_amount')),
        ]);
    }

    private function accountId(): int
    {
        $user = $this->user();

        return (int) ($user?->accountOwnerId() ?? $user?->id ?? 0);
    }

    private function normalizeNullableNumber(mixed $value): mixed
    {
        if ($value === '' || $value === null) {
            return null;
        }

        return $value;
    }
}
