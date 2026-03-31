<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\PlanScan;
use App\Models\Product;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlanScanQuoteService
{
    public function __construct(private readonly PlanScanService $planScanService) {}

    public function createQuoteFromScan(
        PlanScan $planScan,
        User $user,
        string $variantKey,
        ?int $customerId = null,
        ?int $propertyId = null
    ): Quote {
        if ($planScan->status !== PlanScanService::STATUS_READY) {
            throw ValidationException::withMessages([
                'status' => ['Plan scan is not ready yet.'],
            ]);
        }

        if ($planScan->ai_review_required) {
            throw ValidationException::withMessages([
                'review' => ['Review the AI extraction before creating a quote from this scan.'],
            ]);
        }

        $accountId = $user->accountOwnerId();
        $resolvedCustomerId = $customerId ?? $planScan->customer_id;
        $resolvedPropertyId = $propertyId ?? $planScan->property_id;

        if (! $resolvedCustomerId) {
            throw ValidationException::withMessages([
                'customer_id' => ['Select a customer to create the quote.'],
            ]);
        }

        $customer = Customer::byUser($accountId)
            ->with('properties')
            ->find($resolvedCustomerId);

        if (! $customer) {
            throw ValidationException::withMessages([
                'customer_id' => ['Invalid customer for this account.'],
            ]);
        }

        if ($resolvedPropertyId && ! $customer->properties->contains('id', $resolvedPropertyId)) {
            throw ValidationException::withMessages([
                'property_id' => ['Invalid property for this customer.'],
            ]);
        }

        $variant = collect($planScan->variants ?? [])->firstWhere('key', $variantKey);
        if (! $variant) {
            throw ValidationException::withMessages([
                'variant' => ['Variant not available.'],
            ]);
        }

        app(UsageLimitService::class)->enforceLimit($user, 'plan_scan_quotes');
        app(UsageLimitService::class)->enforceLimit($user, 'quotes');

        $owner = $accountId === $user->id ? $user : User::query()->find($accountId);
        $itemType = ($owner?->company_type === 'products')
            ? Product::ITEM_TYPE_PRODUCT
            : Product::ITEM_TYPE_SERVICE;

        $lines = collect($variant['items'] ?? [])->map(function (array $item) use ($accountId, $itemType, $variantKey) {
            $product = $this->planScanService->resolveOrCreateProduct($accountId, $itemType, $item);
            $quantity = (int) ($item['quantity'] ?? 1);
            $price = (float) ($item['unit_price'] ?? 0);
            $total = round($quantity * $price, 2);
            $sourceDetails = [
                'strategy' => $variantKey,
                'selected_source' => $item['selected_source'] ?? null,
                'sources' => $item['sources'] ?? [],
                'source_query' => $item['source_query'] ?? null,
                'selection_basis' => $item['selection_basis'] ?? null,
                'selection_reason' => $item['selection_reason'] ?? null,
                'benchmarks' => $item['source_benchmarks'] ?? null,
                'best_source' => $item['best_source'] ?? null,
                'preferred_source' => $item['preferred_source'] ?? null,
                'preferred_suppliers' => $item['preferred_suppliers'] ?? [],
                'source_status' => $item['source_status'] ?? null,
            ];

            return [
                'id' => $product->id,
                'quantity' => $quantity,
                'price' => $price,
                'total' => $total,
                'description' => $item['description'] ?? null,
                'source_details' => $sourceDetails,
            ];
        });

        $subtotal = round($lines->sum('total'), 2);
        $total = $subtotal;
        $jobTitle = $planScan->job_title ?: ('Plan scan '.($planScan->trade_type ?: 'project'));

        $quote = null;
        DB::transaction(function () use (&$quote, $planScan, $customer, $resolvedPropertyId, $accountId, $jobTitle, $subtotal, $total, $lines) {
            $quote = Quote::create([
                'user_id' => $accountId,
                'customer_id' => $customer->id,
                'property_id' => $resolvedPropertyId,
                'job_title' => $jobTitle,
                'subtotal' => $subtotal,
                'total' => $total,
                'status' => 'draft',
                'is_fixed' => false,
            ]);

            $pivotData = $lines->mapWithKeys(function (array $line) {
                return [
                    $line['id'] => [
                        'quantity' => $line['quantity'],
                        'price' => $line['price'],
                        'total' => $line['total'],
                        'description' => $line['description'],
                        'source_details' => $line['source_details'] ? json_encode($line['source_details']) : null,
                    ],
                ];
            });

            $quote->syncProductLines($pivotData);

            $planScan->increment('quotes_generated');
        });

        ActivityLog::record($user, $quote, 'created', [
            'source' => 'plan_scan',
            'plan_scan_id' => $planScan->id,
        ], 'Quote created from plan scan');

        ActivityLog::record($user, $planScan, 'converted', [
            'quote_id' => $quote?->id,
            'variant' => $variantKey,
        ], 'Plan scan converted to quote');

        return $quote->fresh(['products', 'customer']);
    }
}
