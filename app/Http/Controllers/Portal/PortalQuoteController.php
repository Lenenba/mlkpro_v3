<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\QuoteProduct;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkChecklistItem;
use App\Services\UsageLimitService;
use App\Notifications\ActionEmailNotification;
use App\Support\NotificationDispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PortalQuoteController extends Controller
{
    private function portalCustomer(Request $request): Customer
    {
        $customer = $request->user()?->customerProfile;
        if (!$customer) {
            abort(403);
        }

        return $customer;
    }

    public function accept(Request $request, Quote $quote)
    {
        $customer = $this->portalCustomer($request);
        if ($quote->customer_id !== $customer->id) {
            abort(403);
        }

        if ($quote->isArchived()) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Archived quotes cannot be accepted.',
                ], 422);
            }

            return redirect()->back()->withErrors([
                'status' => 'Archived quotes cannot be accepted.',
            ]);
        }

        if ($quote->status === 'accepted') {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Quote already accepted.',
                    'quote' => [
                        'id' => $quote->id,
                        'status' => $quote->status,
                    ],
                ]);
            }

            return redirect()->back()->with('success', 'Quote already accepted.');
        }

        if ($quote->status === 'declined') {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Quote already declined.',
                ], 422);
            }

            return redirect()->back()->withErrors([
                'status' => 'Quote already declined.',
            ]);
        }

        $validated = $request->validate([
            'deposit_amount' => 'nullable|numeric|min:0',
            'method' => 'nullable|string|max:50',
            'reference' => 'nullable|string|max:120',
            'signed_at' => 'nullable|date',
        ]);

        $requiredDeposit = (float) ($quote->initial_deposit ?? 0);
        $depositAmount = (float) ($validated['deposit_amount'] ?? $requiredDeposit);

        if ($requiredDeposit > 0 && $depositAmount < $requiredDeposit) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Deposit is below the required amount.',
                ], 422);
            }

            return redirect()->back()->withErrors([
                'deposit_amount' => 'Deposit is below the required amount.',
            ]);
        }

        $existingWork = Work::where('quote_id', $quote->id)->first();
        if (!$existingWork) {
            $owner = User::find($quote->user_id);
            if ($owner) {
                app(UsageLimitService::class)->enforceLimit($owner, 'jobs');
            }
        }

        $work = null;
        DB::transaction(function () use ($quote, $validated, $depositAmount, $existingWork, &$work) {
            $work = $existingWork;
            if (!$work) {
                $work = Work::create([
                    'user_id' => $quote->user_id,
                    'customer_id' => $quote->customer_id,
                    'quote_id' => $quote->id,
                    'job_title' => $quote->job_title,
                    'instructions' => $quote->notes ?: ($quote->messages ?: ''),
                    'status' => Work::STATUS_TO_SCHEDULE,
                    'subtotal' => $quote->subtotal,
                    'total' => $quote->total,
                ]);
            } else {
                $work->update([
                    'job_title' => $quote->job_title,
                    'instructions' => $quote->notes ?: ($quote->messages ?: ''),
                    'subtotal' => $quote->subtotal,
                    'total' => $quote->total,
                ]);
            }

            $quote->update([
                'status' => 'accepted',
                'accepted_at' => now(),
                'signed_at' => $validated['signed_at'] ?? now(),
                'work_id' => $work->id,
            ]);

            if ($depositAmount > 0) {
                $hasDeposit = Transaction::where('quote_id', $quote->id)
                    ->where('type', 'deposit')
                    ->where('status', 'completed')
                    ->exists();

                if (!$hasDeposit) {
                    Transaction::create([
                        'quote_id' => $quote->id,
                        'work_id' => $work->id,
                        'customer_id' => $quote->customer_id,
                        'user_id' => $quote->user_id,
                        'amount' => $depositAmount,
                        'type' => 'deposit',
                        'method' => $validated['method'] ?? null,
                        'status' => 'completed',
                        'reference' => $validated['reference'] ?? null,
                        'paid_at' => now(),
                    ]);
                }
            }

            $this->syncWorkProductsFromQuote($quote, $work);

            $items = QuoteProduct::query()
                ->where('quote_id', $quote->id)
                ->with('product')
                ->orderBy('id')
                ->get();

            foreach ($items as $index => $item) {
                WorkChecklistItem::firstOrCreate(
                    [
                        'work_id' => $work->id,
                        'quote_product_id' => $item->id,
                    ],
                    [
                        'quote_id' => $quote->id,
                        'title' => $item->product?->name ?? 'Line item',
                        'description' => $item->description ?: $item->product?->description,
                        'status' => 'pending',
                        'sort_order' => $index,
                    ]
                );
            }
        });

        ActivityLog::record($request->user(), $quote, 'accepted', [
            'total' => $quote->total,
        ], 'Quote accepted by client');

        $quote->syncRequestStatusFromQuote();

        $owner = User::find($quote->user_id);
        if ($owner && $owner->email) {
            $customerLabel = $customer->company_name
                ?: trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''));

            NotificationDispatcher::send($owner, new ActionEmailNotification(
                'Quote accepted by client',
                $customerLabel ? $customerLabel . ' accepted a quote.' : 'A client accepted a quote.',
                [
                    ['label' => 'Quote', 'value' => $quote->number ?? $quote->id],
                    ['label' => 'Customer', 'value' => $customerLabel ?: 'Client'],
                    ['label' => 'Total', 'value' => '$' . number_format((float) $quote->total, 2)],
                ],
                route('customer.quote.show', $quote->id),
                'View quote',
                'Quote accepted by client'
            ), [
                'quote_id' => $quote->id,
            ]);
        }

        if ($this->shouldReturnJson($request)) {
            $quote->refresh();

            return response()->json([
                'message' => 'Quote accepted.',
                'quote' => [
                    'id' => $quote->id,
                    'status' => $quote->status,
                    'accepted_at' => $quote->accepted_at,
                ],
                'work' => $work ? [
                    'id' => $work->id,
                    'status' => $work->status,
                ] : null,
            ]);
        }

        return redirect()->back()->with('success', 'Quote accepted.');
    }

    public function decline(Request $request, Quote $quote)
    {
        $customer = $this->portalCustomer($request);
        if ($quote->customer_id !== $customer->id) {
            abort(403);
        }

        if ($quote->isArchived()) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Archived quotes cannot be declined.',
                ], 422);
            }

            return redirect()->back()->withErrors([
                'status' => 'Archived quotes cannot be declined.',
            ]);
        }

        if ($quote->status === 'declined') {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Quote already declined.',
                    'quote' => [
                        'id' => $quote->id,
                        'status' => $quote->status,
                    ],
                ]);
            }

            return redirect()->back()->with('success', 'Quote already declined.');
        }

        if ($quote->status === 'accepted') {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Quote already accepted.',
                ], 422);
            }

            return redirect()->back()->withErrors([
                'status' => 'Quote already accepted.',
            ]);
        }

        $quote->update([
            'status' => 'declined',
            'signed_at' => $quote->signed_at ?? now(),
            'accepted_at' => null,
        ]);

        ActivityLog::record($request->user(), $quote, 'declined', [
            'total' => $quote->total,
        ], 'Quote declined by client');

        $quote->syncRequestStatusFromQuote();

        $owner = User::find($quote->user_id);
        if ($owner && $owner->email) {
            $customerLabel = $customer->company_name
                ?: trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''));

            NotificationDispatcher::send($owner, new ActionEmailNotification(
                'Quote declined by client',
                $customerLabel ? $customerLabel . ' declined a quote.' : 'A client declined a quote.',
                [
                    ['label' => 'Quote', 'value' => $quote->number ?? $quote->id],
                    ['label' => 'Customer', 'value' => $customerLabel ?: 'Client'],
                    ['label' => 'Total', 'value' => '$' . number_format((float) $quote->total, 2)],
                ],
                route('customer.quote.show', $quote->id),
                'View quote',
                'Quote declined by client'
            ), [
                'quote_id' => $quote->id,
            ]);
        }

        if ($this->shouldReturnJson($request)) {
            $quote->refresh();

            return response()->json([
                'message' => 'Quote declined.',
                'quote' => [
                    'id' => $quote->id,
                    'status' => $quote->status,
                    'accepted_at' => $quote->accepted_at,
                ],
            ]);
        }

        return redirect()->back()->with('success', 'Quote declined.');
    }

    private function syncWorkProductsFromQuote(Quote $quote, Work $work): void
    {
        $quote->loadMissing('products');

        $pivotData = $quote->products->mapWithKeys(function ($product) use ($quote) {
            return [
                $product->id => [
                    'quote_id' => $quote->id,
                    'quantity' => (int) $product->pivot->quantity,
                    'price' => (float) $product->pivot->price,
                    'description' => $product->pivot->description,
                    'total' => (float) $product->pivot->total,
                ],
            ];
        });

        $work->products()->sync($pivotData->toArray());
    }
}
