<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Quote;
use App\Models\QuoteProduct;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkChecklistItem;
use App\Notifications\ActionEmailNotification;
use App\Support\NotificationDispatcher;
use App\Services\UsageLimitService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

class PublicQuoteController extends Controller
{
    private const LINK_TTL_DAYS = 7;

    public function show(Request $request, Quote $quote): Response
    {
        $quote->load([
            'customer:id,company_name,first_name,last_name,email,phone,portal_access,portal_user_id',
            'property:id,street1,street2,city,state,zip,country',
            'products',
            'taxes.tax',
        ]);

        $customer = $quote->customer;
        $owner = User::find($quote->user_id);

        $hasDecision = in_array($quote->status, ['accepted', 'declined'], true);
        $allowAccept = !$quote->isArchived() && !$hasDecision;
        $allowDecline = !$quote->isArchived() && !$hasDecision;
        $statusMessage = null;
        if ($quote->isArchived()) {
            $statusMessage = 'Archived quotes cannot be updated.';
        } elseif ($quote->status === 'accepted') {
            $statusMessage = 'This quote is already accepted.';
        } elseif ($quote->status === 'declined') {
            $statusMessage = 'This quote is already declined.';
        }

        $expiresAt = $this->resolveExpiry($request);
        $acceptUrl = URL::temporarySignedRoute(
            'public.quotes.accept',
            $expiresAt,
            ['quote' => $quote->id]
        );
        $declineUrl = URL::temporarySignedRoute(
            'public.quotes.decline',
            $expiresAt,
            ['quote' => $quote->id]
        );

        $items = $quote->products->map(function ($product) {
            return [
                'id' => $product->id,
                'name' => $product->name,
                'quantity' => (int) ($product->pivot->quantity ?? 0),
                'price' => (float) ($product->pivot->price ?? 0),
                'total' => (float) ($product->pivot->total ?? 0),
                'description' => $product->pivot->description ?? null,
            ];
        })->values();

        $taxes = ($quote->taxes ?? collect())->map(function ($tax) {
            return [
                'id' => $tax->id,
                'name' => $tax->tax?->name ?? 'Tax',
                'rate' => (float) $tax->rate,
                'amount' => (float) $tax->amount,
            ];
        })->values();

        return Inertia::render('Public/QuoteAction', [
            'quote' => [
                'id' => $quote->id,
                'number' => $quote->number,
                'status' => $quote->status,
                'job_title' => $quote->job_title,
                'subtotal' => (float) $quote->subtotal,
                'total' => (float) $quote->total,
                'initial_deposit' => (float) ($quote->initial_deposit ?? 0),
                'created_at' => $quote->created_at,
                'customer' => $customer ? [
                    'company_name' => $customer->company_name,
                    'first_name' => $customer->first_name,
                    'last_name' => $customer->last_name,
                    'email' => $customer->email,
                    'phone' => $customer->phone,
                ] : null,
                'property' => $quote->property ? [
                    'street1' => $quote->property->street1,
                    'street2' => $quote->property->street2,
                    'city' => $quote->property->city,
                    'state' => $quote->property->state,
                    'zip' => $quote->property->zip,
                    'country' => $quote->property->country,
                ] : null,
                'items' => $items,
                'taxes' => $taxes,
            ],
            'company' => [
                'name' => $owner?->company_name ?: config('app.name'),
                'logo_url' => $owner?->company_logo_url,
            ],
            'allowAccept' => $allowAccept,
            'allowDecline' => $allowDecline,
            'statusMessage' => $statusMessage,
            'acceptUrl' => $acceptUrl,
            'declineUrl' => $declineUrl,
        ]);
    }

    public function accept(Request $request, Quote $quote)
    {
        if ($quote->isArchived()) {
            return redirect()->back()->withErrors([
                'status' => 'Archived quotes cannot be accepted.',
            ]);
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

        ActivityLog::record(null, $quote, 'accepted', [
            'total' => $quote->total,
        ], 'Quote accepted by client (public link)');

        $quote->syncRequestStatusFromQuote();

        $owner = User::find($quote->user_id);
        if ($owner && $owner->email) {
            $customer = $quote->customer;
            $customerLabel = $customer?->company_name
                ?: trim(($customer?->first_name ?? '') . ' ' . ($customer?->last_name ?? ''));

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

        return redirect()->back()->with('success', 'Quote accepted.');
    }

    public function decline(Request $request, Quote $quote)
    {
        if ($quote->isArchived()) {
            return redirect()->back()->withErrors([
                'status' => 'Archived quotes cannot be declined.',
            ]);
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

        ActivityLog::record(null, $quote, 'declined', [
            'total' => $quote->total,
        ], 'Quote declined by client (public link)');

        $quote->syncRequestStatusFromQuote();

        $owner = User::find($quote->user_id);
        if ($owner && $owner->email) {
            $customer = $quote->customer;
            $customerLabel = $customer?->company_name
                ?: trim(($customer?->first_name ?? '') . ' ' . ($customer?->last_name ?? ''));

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

    private function resolveExpiry(Request $request): Carbon
    {
        $expires = $request->query('expires');
        if (is_numeric($expires)) {
            return Carbon::createFromTimestamp((int) $expires);
        }

        return now()->addDays(self::LINK_TTL_DAYS);
    }
}
