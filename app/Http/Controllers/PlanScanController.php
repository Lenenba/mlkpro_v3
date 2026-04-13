<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\PlanScan;
use App\Services\PlanScanQuoteService;
use App\Services\PlanScanReviewService;
use App\Services\PlanScanService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PlanScanController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();
        $perPage = $this->resolveDataTablePerPage($request);

        if (! $user || $user->id !== $accountId) {
            abort(403);
        }

        $baseQuery = PlanScan::query()
            ->byUser($accountId)
            ->with('customer');

        $scans = (clone $baseQuery)
            ->latest()
            ->simplePaginate($perPage)
            ->withQueryString();

        $stats = [
            'total' => (clone $baseQuery)->count(),
            'ready' => (clone $baseQuery)->where('status', PlanScanService::STATUS_READY)->count(),
            'processing' => (clone $baseQuery)->where('status', PlanScanService::STATUS_PROCESSING)->count(),
            'failed' => (clone $baseQuery)->where('status', PlanScanService::STATUS_FAILED)->count(),
        ];

        return $this->inertiaOrJson('PlanScan/Index', [
            'scans' => $scans,
            'stats' => $stats,
        ]);
    }

    public function create(Request $request, PlanScanService $service)
    {
        $user = $request->user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

        if (! $user || $user->id !== $accountId) {
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

        return $this->inertiaOrJson('PlanScan/Create', [
            'customers' => $customers,
            'tradeOptions' => $service->tradeOptions(),
            'priorityOptions' => $service->priorityOptions(),
        ]);
    }

    public function store(Request $request, PlanScanService $service)
    {
        $user = $request->user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

        if (! $user || $user->id !== $accountId) {
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

        if ($propertyId && ! $customerId) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Validation error.',
                    'errors' => [
                        'property_id' => ['Select a customer first.'],
                    ],
                ], 422);
            }

            return back()->withErrors(['property_id' => 'Select a customer first.']);
        }

        if ($customerId) {
            $customer = Customer::byUser($accountId)->findOrFail($customerId);
            if ($propertyId && ! $customer->properties()->whereKey($propertyId)->exists()) {
                if ($this->shouldReturnJson($request)) {
                    return response()->json([
                        'message' => 'Validation error.',
                        'errors' => [
                            'property_id' => ['Invalid property for this customer.'],
                        ],
                    ], 422);
                }

                return back()->withErrors(['property_id' => 'Invalid property for this customer.']);
            }
        }

        $metrics = [
            'surface_m2' => $validated['surface_m2'] ?? null,
            'rooms' => $validated['rooms'] ?? null,
            'priority' => $validated['priority'] ?? 'balanced',
        ];

        $scan = $service->submit(
            $user,
            $request->file('plan_file'),
            [
                'customer_id' => $customerId,
                'property_id' => $propertyId,
                'job_title' => $validated['job_title'] ?? null,
                'trade_type' => $validated['trade_type'],
            ],
            $metrics
        );

        if ($scan?->status === PlanScanService::STATUS_FAILED) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Plan scan failed. Please try again.',
                    'scan' => $scan,
                ], 500);
            }

            return redirect()
                ->route('plan-scans.show', $scan)
                ->with('error', 'Plan scan failed. Please try again.');
        }

        $message = $scan?->status === PlanScanService::STATUS_READY
            ? 'Plan scan ready.'
            : 'Plan scan queued. Analysis started.';

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => $message,
                'scan' => $scan->fresh(),
            ], 201);
        }

        return redirect()
            ->route('plan-scans.show', $scan)
            ->with('success', $message);
    }

    public function show(Request $request, PlanScan $planScan, PlanScanService $service)
    {
        $user = $request->user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

        if (! $user || $planScan->user_id !== $accountId) {
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

        return $this->inertiaOrJson('PlanScan/Show', [
            'scan' => $planScan->load(['customer', 'property']),
            'customers' => $customers,
            'tradeOptions' => $service->tradeOptions(),
            'priorityOptions' => $service->priorityOptions(),
        ]);
    }

    public function review(Request $request, PlanScan $planScan, PlanScanService $service, PlanScanReviewService $reviewService)
    {
        $user = $request->user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

        if (! $user || $planScan->user_id !== $accountId) {
            abort(403);
        }

        $tradeIds = collect($service->tradeOptions())->pluck('id')->all();
        $priorityIds = collect($service->priorityOptions())->pluck('id')->all();

        $validated = $request->validate([
            'trade_type' => ['required', Rule::in($tradeIds)],
            'surface_m2' => 'nullable|numeric|min:0|max:10000',
            'rooms' => 'nullable|integer|min:0|max:200',
            'priority' => ['nullable', Rule::in($priorityIds)],
            'line_items' => 'nullable|array',
            'line_items.*.name' => 'required|string|max:255',
            'line_items.*.quantity' => 'required|numeric|min:0.1|max:100000',
            'line_items.*.unit' => 'nullable|string|max:20',
            'line_items.*.description' => 'nullable|string|max:500',
            'line_items.*.base_cost' => 'nullable|numeric|min:0|max:1000000',
            'line_items.*.is_labor' => 'nullable|boolean',
            'line_items.*.confidence' => 'nullable|numeric|min:0|max:100',
            'line_items.*.notes' => 'nullable|string|max:500',
        ]);

        $reviewedPayload = [
            'trade_type' => $validated['trade_type'],
            'metrics' => [
                'surface_m2' => $validated['surface_m2'] ?? null,
                'rooms' => $validated['rooms'] ?? null,
                'priority' => $validated['priority'] ?? data_get($planScan->ai_reviewed_payload, 'metrics.priority', 'balanced'),
            ],
            'line_items' => collect($validated['line_items'] ?? [])
                ->map(function (array $line) {
                    return [
                        'name' => trim((string) ($line['name'] ?? '')),
                        'quantity' => round(max(0.1, (float) ($line['quantity'] ?? 1)), 2),
                        'unit' => trim((string) ($line['unit'] ?? 'u')) ?: 'u',
                        'description' => $line['description'] ?? null,
                        'base_cost' => isset($line['base_cost']) && $line['base_cost'] !== ''
                            ? round((float) $line['base_cost'], 2)
                            : null,
                        'is_labor' => (bool) ($line['is_labor'] ?? false),
                        'confidence' => isset($line['confidence']) && $line['confidence'] !== ''
                            ? (int) round((float) $line['confidence'])
                            : null,
                        'notes' => $line['notes'] ?? null,
                    ];
                })
                ->filter(fn (array $line) => $line['name'] !== '')
                ->values()
                ->all(),
            'assumptions' => is_array(data_get($planScan->ai_reviewed_payload, 'assumptions'))
                ? data_get($planScan->ai_reviewed_payload, 'assumptions')
                : (is_array(data_get($planScan->ai_extraction_normalized, 'assumptions'))
                    ? data_get($planScan->ai_extraction_normalized, 'assumptions')
                    : []),
            'review_flags' => is_array(data_get($planScan->ai_reviewed_payload, 'review_flags'))
                ? data_get($planScan->ai_reviewed_payload, 'review_flags')
                : (is_array(data_get($planScan->ai_extraction_normalized, 'review_flags'))
                    ? data_get($planScan->ai_extraction_normalized, 'review_flags')
                    : []),
            'reviewed_at' => now()->toIso8601String(),
            'updated_by_user_id' => $user->id,
        ];

        $planScan = $reviewService->applyReviewedPayload($planScan, $user, $reviewedPayload, [
            'source' => 'manual_ui',
            'activity' => 'reviewed',
            'activity_message' => 'Plan scan review saved',
            'summary' => 'Estimation mise a jour a partir du payload de revue confirme.',
            'ai_review_required' => false,
        ]);

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Plan scan review saved.',
                'scan' => $planScan->fresh(),
            ]);
        }

        return redirect()
            ->route('plan-scans.show', $planScan)
            ->with('success', 'Plan scan review saved.');
    }

    public function reanalyze(Request $request, PlanScan $planScan, PlanScanService $service)
    {
        $user = $request->user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

        if (! $user || $planScan->user_id !== $accountId) {
            abort(403);
        }

        $validated = $request->validate([
            'mode' => ['nullable', Rule::in(['retry', 'escalate'])],
        ]);

        $mode = $validated['mode'] ?? 'retry';
        $scan = $service->reanalyze($planScan, $user, $mode);

        $message = $scan?->status === PlanScanService::STATUS_READY
            ? ($mode === 'escalate' ? 'AI analysis escalated and refreshed.' : 'AI analysis refreshed.')
            : ($mode === 'escalate' ? 'AI escalation queued. Analysis started.' : 'AI reanalysis queued. Analysis started.');

        ActivityLog::record($user, $scan, 'reanalyzed', [
            'mode' => $mode,
            'ai_retry_count' => $scan->ai_retry_count,
        ], $mode === 'escalate' ? 'Plan scan AI escalated' : 'Plan scan AI retried');

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => $message,
                'scan' => $scan->fresh(),
            ]);
        }

        return redirect()
            ->route('plan-scans.show', $scan)
            ->with('success', $message);
    }

    public function destroy(Request $request, PlanScan $planScan)
    {
        $user = $request->user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

        if (! $user || $planScan->user_id !== $accountId) {
            abort(403);
        }

        $snapshot = [
            'id' => $planScan->id,
            'job_title' => $planScan->job_title,
            'status' => $planScan->status,
            'trade_type' => $planScan->trade_type,
        ];

        if ($planScan->plan_file_path && Storage::disk('public')->exists($planScan->plan_file_path)) {
            Storage::disk('public')->delete($planScan->plan_file_path);
        }

        ActivityLog::record($user, $planScan, 'deleted', $snapshot, 'Plan scan deleted');

        $planScan->delete();

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Plan scan deleted.',
            ]);
        }

        return redirect()
            ->route('plan-scans.index')
            ->with('success', 'Plan scan deleted.');
    }

    public function convert(Request $request, PlanScan $planScan, PlanScanQuoteService $quoteService)
    {
        $user = $request->user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

        if (! $user || $planScan->user_id !== $accountId) {
            abort(403);
        }

        $validated = $request->validate([
            'variant' => ['required', Rule::in(['eco', 'standard', 'premium'])],
            'customer_id' => ['nullable', Rule::exists('customers', 'id')],
            'property_id' => ['nullable', Rule::exists('properties', 'id')],
        ]);
        try {
            $quote = $quoteService->createQuoteFromScan(
                $planScan,
                $user,
                $validated['variant'],
                $validated['customer_id'] ?? null,
                $validated['property_id'] ?? null
            );
        } catch (ValidationException $exception) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Validation error.',
                    'errors' => $exception->errors(),
                ], 422);
            }

            return back()->withErrors($exception->errors());
        }

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Quote created from plan scan.',
                'quote' => $quote->fresh(['products', 'customer']),
                'plan_scan' => $planScan->fresh(),
            ]);
        }

        return redirect()
            ->route('customer.quote.edit', $quote)
            ->with('success', 'Quote created from plan scan.');
    }
}
