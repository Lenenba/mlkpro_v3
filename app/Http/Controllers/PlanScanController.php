<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\PlanScan;
use App\Models\Product;
use App\Models\Quote;
use App\Services\PlanScanService;
use App\Services\UsageLimitService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class PlanScanController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

        if (!$user || $user->id !== $accountId) {
            abort(403);
        }

        $baseQuery = PlanScan::query()
            ->byUser($accountId)
            ->with('customer');

        $scans = (clone $baseQuery)
            ->latest()
            ->simplePaginate(10)
            ->withQueryString();

        $stats = [
            'total' => (clone $baseQuery)->count(),
            'ready' => (clone $baseQuery)->where('status', PlanScanService::STATUS_READY)->count(),
            'processing' => (clone $baseQuery)->where('status', PlanScanService::STATUS_PROCESSING)->count(),
            'failed' => (clone $baseQuery)->where('status', PlanScanService::STATUS_FAILED)->count(),
        ];

        return Inertia::render('PlanScan/Index', [
            'scans' => $scans,
            'stats' => $stats,
        ]);
    }

    public function create(Request $request, PlanScanService $service)
    {
        $user = $request->user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

        if (!$user || $user->id !== $accountId) {
            abort(403);
        }

        $customers = Customer::byUser($accountId)
            ->with(['properties' => function ($query) {
                $query->orderByDesc('is_default')->orderBy('id');
            }])
            ->orderBy('company_name')
            ->orderBy('last_name')
            ->get(['id', 'company_name', 'first_name', 'last_name', 'email', 'phone'])
            ->map(function ($customer) {
                return [
                    'id' => $customer->id,
                    'company_name' => $customer->company_name,
                    'first_name' => $customer->first_name,
                    'last_name' => $customer->last_name,
                    'email' => $customer->email,
                    'phone' => $customer->phone,
                    'properties' => $customer->properties->map(function ($property) {
                        return [
                            'id' => $property->id,
                            'type' => $property->type,
                            'is_default' => (bool) $property->is_default,
                            'street1' => $property->street1,
                            'street2' => $property->street2,
                            'city' => $property->city,
                            'state' => $property->state,
                            'zip' => $property->zip,
                            'country' => $property->country,
                        ];
                    })->values(),
                ];
            })
            ->values();

        return Inertia::render('PlanScan/Create', [
            'customers' => $customers,
            'tradeOptions' => $service->tradeOptions(),
            'priorityOptions' => $service->priorityOptions(),
        ]);
    }

    public function store(Request $request, PlanScanService $service)
    {
        $user = $request->user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

        if (!$user || $user->id !== $accountId) {
            abort(403);
        }

        $tradeIds = collect($service->tradeOptions())->pluck('id')->all();
        $priorityIds = collect($service->priorityOptions())->pluck('id')->all();

        $validated = $request->validate([
            'plan_file' => 'required|file|max:5120|mimes:pdf,png,jpg,jpeg,webp',
            'job_title' => 'nullable|string|max:255',
            'trade_type' => ['required', Rule::in($tradeIds)],
            'customer_id' => ['nullable', Rule::exists('customers', 'id')],
            'property_id' => ['nullable', Rule::exists('properties', 'id')],
            'surface_m2' => 'nullable|numeric|min:0|max:10000',
            'rooms' => 'nullable|integer|min:0|max:200',
            'priority' => ['nullable', Rule::in($priorityIds)],
        ]);

        $customerId = $validated['customer_id'] ?? null;
        $propertyId = $validated['property_id'] ?? null;

        if ($propertyId && !$customerId) {
            return back()->withErrors(['property_id' => 'Select a customer first.']);
        }

        if ($customerId) {
            $customer = Customer::byUser($accountId)->findOrFail($customerId);
            if ($propertyId && !$customer->properties()->whereKey($propertyId)->exists()) {
                return back()->withErrors(['property_id' => 'Invalid property for this customer.']);
            }
        }

        $file = $request->file('plan_file');
        $path = $file->store('plan-scans', 'public');

        $scan = PlanScan::create([
            'user_id' => $accountId,
            'customer_id' => $customerId,
            'property_id' => $propertyId,
            'job_title' => $validated['job_title'] ?? null,
            'trade_type' => $validated['trade_type'],
            'status' => PlanScanService::STATUS_PROCESSING,
            'plan_file_path' => $path,
            'plan_file_name' => $file->getClientOriginalName(),
        ]);

        try {
            $metrics = [
                'surface_m2' => $validated['surface_m2'] ?? null,
                'rooms' => $validated['rooms'] ?? null,
                'priority' => $validated['priority'] ?? 'balanced',
            ];

            $analysis = $service->analyze($scan, $metrics, $metrics['priority']);

            $scan->update([
                'status' => PlanScanService::STATUS_READY,
                'metrics' => $analysis['metrics'],
                'analysis' => $analysis['analysis'],
                'variants' => $analysis['variants'],
                'confidence_score' => $analysis['confidence_score'],
                'analyzed_at' => now(),
            ]);

            ActivityLog::record($user, $scan, 'analyzed', [
                'trade_type' => $scan->trade_type,
                'confidence_score' => $scan->confidence_score,
            ], 'Plan scan analyzed');
        } catch (\Throwable $exception) {
            $scan->update([
                'status' => PlanScanService::STATUS_FAILED,
                'error_message' => $exception->getMessage(),
            ]);

            return redirect()
                ->route('plan-scans.show', $scan)
                ->with('error', 'Plan scan failed. Please try again.');
        }

        return redirect()
            ->route('plan-scans.show', $scan)
            ->with('success', 'Plan scan ready.');
    }

    public function show(Request $request, PlanScan $planScan)
    {
        $user = $request->user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

        if (!$user || $planScan->user_id !== $accountId) {
            abort(403);
        }

        $customers = Customer::byUser($accountId)
            ->with(['properties' => function ($query) {
                $query->orderByDesc('is_default')->orderBy('id');
            }])
            ->orderBy('company_name')
            ->orderBy('last_name')
            ->get(['id', 'company_name', 'first_name', 'last_name', 'email', 'phone'])
            ->map(function ($customer) {
                return [
                    'id' => $customer->id,
                    'company_name' => $customer->company_name,
                    'first_name' => $customer->first_name,
                    'last_name' => $customer->last_name,
                    'email' => $customer->email,
                    'phone' => $customer->phone,
                    'properties' => $customer->properties->map(function ($property) {
                        return [
                            'id' => $property->id,
                            'type' => $property->type,
                            'is_default' => (bool) $property->is_default,
                            'street1' => $property->street1,
                            'street2' => $property->street2,
                            'city' => $property->city,
                            'state' => $property->state,
                            'zip' => $property->zip,
                            'country' => $property->country,
                        ];
                    })->values(),
                ];
            })
            ->values();

        return Inertia::render('PlanScan/Show', [
            'scan' => $planScan->load(['customer', 'property']),
            'customers' => $customers,
        ]);
    }

    public function convert(Request $request, PlanScan $planScan, PlanScanService $service)
    {
        $user = $request->user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

        if (!$user || $planScan->user_id !== $accountId) {
            abort(403);
        }

        if ($planScan->status !== PlanScanService::STATUS_READY) {
            return back()->withErrors(['status' => 'Plan scan is not ready yet.']);
        }

        $validated = $request->validate([
            'variant' => ['required', Rule::in(['eco', 'standard', 'premium'])],
            'customer_id' => ['nullable', Rule::exists('customers', 'id')],
            'property_id' => ['nullable', Rule::exists('properties', 'id')],
        ]);

        $customerId = $planScan->customer_id ?? $validated['customer_id'] ?? null;
        $propertyId = $planScan->property_id ?? $validated['property_id'] ?? null;

        if (!$customerId) {
            return back()->withErrors(['customer_id' => 'Select a customer to create the quote.']);
        }

        $customer = Customer::byUser($accountId)->findOrFail($customerId);
        if ($propertyId && !$customer->properties()->whereKey($propertyId)->exists()) {
            return back()->withErrors(['property_id' => 'Invalid property for this customer.']);
        }

        $variant = collect($planScan->variants ?? [])->firstWhere('key', $validated['variant']);
        if (!$variant) {
            return back()->withErrors(['variant' => 'Variant not available.']);
        }

        app(UsageLimitService::class)->enforceLimit($user, 'plan_scan_quotes');
        app(UsageLimitService::class)->enforceLimit($user, 'quotes');

        $owner = $accountId === $user->id ? $user : User::query()->find($accountId);
        $itemType = ($owner?->company_type === 'products')
            ? Product::ITEM_TYPE_PRODUCT
            : Product::ITEM_TYPE_SERVICE;

        $variantKey = $validated['variant'];
        $lines = collect($variant['items'] ?? [])->map(function (array $item) use ($service, $accountId, $itemType, $variantKey) {
            $product = $service->resolveOrCreateProduct($accountId, $itemType, $item);
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
        $jobTitle = $planScan->job_title ?: ('Plan scan ' . ($planScan->trade_type ?: 'project'));

        $quote = null;
        DB::transaction(function () use (&$quote, $planScan, $customer, $propertyId, $accountId, $jobTitle, $subtotal, $total, $lines) {
            $quote = Quote::create([
                'user_id' => $accountId,
                'customer_id' => $customer->id,
                'property_id' => $propertyId,
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

            $quote->products()->sync($pivotData);

            $planScan->increment('quotes_generated');
        });

        ActivityLog::record($user, $quote, 'created', [
            'source' => 'plan_scan',
            'plan_scan_id' => $planScan->id,
        ], 'Quote created from plan scan');

        ActivityLog::record($user, $planScan, 'converted', [
            'quote_id' => $quote?->id,
            'variant' => $validated['variant'],
        ], 'Plan scan converted to quote');

        return redirect()
            ->route('customer.quote.edit', $quote)
            ->with('success', 'Quote created from plan scan.');
    }
}
