<?php

namespace App\Actions\Quotes;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpsertQuoteAction
{
    public function __construct(
        private readonly BuildQuoteItemsAction $buildQuoteItems
    ) {}

    public function execute(array $validated, User $actor, ?Quote $quote = null): array
    {
        $accountId = (int) ($actor->accountOwnerId() ?? $actor->id);
        $accountOwner = $accountId === (int) $actor->id
            ? $actor
            : User::query()->find($accountId);

        $itemType = $accountOwner?->company_type === 'products'
            ? Product::ITEM_TYPE_PRODUCT
            : Product::ITEM_TYPE_SERVICE;

        $customer = Customer::byUser($accountId)->findOrFail((int) $validated['customer_id']);
        $propertyId = $validated['property_id'] ?? null;
        if ($propertyId && ! $customer->properties()->whereKey($propertyId)->exists()) {
            throw ValidationException::withMessages([
                'property_id' => 'Invalid property for this customer.',
            ]);
        }

        $items = collect($this->buildQuoteItems->execute(
            $validated['product'],
            $itemType,
            $accountId,
            $accountId,
            (int) $actor->id
        ));

        $subtotal = (float) $items->sum('total');
        $taxLines = $this->buildTaxLines($subtotal, $validated['taxes'] ?? []);
        $total = round($subtotal + $taxLines->sum('amount'), 2);
        $deposit = min((float) ($validated['initial_deposit'] ?? 0), $total);
        $previousStatus = $quote?->status;

        DB::transaction(function () use (&$quote, $customer, $propertyId, $validated, $items, $subtotal, $total, $deposit, $taxLines, $accountId) {
            if ($quote) {
                $quote->update([
                    'customer_id' => $customer->id,
                    'job_title' => $validated['job_title'],
                    'property_id' => $propertyId,
                    'subtotal' => $subtotal,
                    'total' => $total,
                    'notes' => $validated['notes'] ?? null,
                    'messages' => $validated['messages'] ?? null,
                    'initial_deposit' => $deposit,
                    'status' => $validated['status'] ?? $quote->status ?? 'draft',
                    'is_fixed' => false,
                ]);
            } else {
                $quote = $customer->quotes()->create([
                    'user_id' => $accountId,
                    'property_id' => $propertyId,
                    'job_title' => $validated['job_title'],
                    'subtotal' => $subtotal,
                    'total' => $total,
                    'notes' => $validated['notes'] ?? null,
                    'messages' => $validated['messages'] ?? null,
                    'initial_deposit' => $deposit,
                    'status' => $validated['status'] ?? 'draft',
                    'is_fixed' => false,
                ]);
            }

            $pivotData = $items->mapWithKeys(fn (array $item) => [
                $item['id'] => [
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                    'total' => $item['total'],
                    'description' => $item['description'],
                    'source_details' => $item['source_details'] ? json_encode($item['source_details']) : null,
                ],
            ]);

            $quote->products()->sync($pivotData);
            $quote->taxes()->delete();

            if ($taxLines->isNotEmpty()) {
                $quote->taxes()->createMany($taxLines->toArray());
            }
        });

        return [
            'quote' => $quote,
            'customer' => $customer,
            'previous_status' => $previousStatus,
        ];
    }

    private function buildTaxLines(float $subtotal, array $taxIds)
    {
        return Tax::whereIn('id', $taxIds)
            ->get()
            ->map(function (Tax $tax) use ($subtotal) {
                $amount = round($subtotal * ((float) $tax->rate / 100), 2);

                return [
                    'tax_id' => $tax->id,
                    'rate' => (float) $tax->rate,
                    'amount' => $amount,
                ];
            });
    }
}
