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
use App\Notifications\ActionEmailNotification;
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

        if ($quote->status === 'accepted') {
            return redirect()->back()->with('success', 'Quote already accepted.');
        }

        if ($quote->status === 'declined') {
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
            return redirect()->back()->withErrors([
                'deposit_amount' => 'Deposit is below the required amount.',
            ]);
        }

        $work = null;
        DB::transaction(function () use ($quote, $validated, $depositAmount, &$work) {
            $work = Work::where('quote_id', $quote->id)->first();
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

        $owner = User::find($quote->user_id);
        if ($owner && $owner->email) {
            $customerLabel = $customer->company_name
                ?: trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''));

            $owner->notify(new ActionEmailNotification(
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
            ));
        }

        return redirect()->back()->with('success', 'Quote accepted.');
    }

    public function decline(Request $request, Quote $quote)
    {
        $customer = $this->portalCustomer($request);
        if ($quote->customer_id !== $customer->id) {
            abort(403);
        }

        if ($quote->status === 'declined') {
            return redirect()->back()->with('success', 'Quote already declined.');
        }

        if ($quote->status === 'accepted') {
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

        $owner = User::find($quote->user_id);
        if ($owner && $owner->email) {
            $customerLabel = $customer->company_name
                ?: trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''));

            $owner->notify(new ActionEmailNotification(
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
            ));
        }

        return redirect()->back()->with('success', 'Quote declined.');
    }
}
