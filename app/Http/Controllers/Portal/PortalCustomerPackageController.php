<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerPackage;
use App\Models\CustomerPackageUsage;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Portal\PortalAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class PortalCustomerPackageController extends Controller
{
    public function __construct(
        private readonly PortalAccessService $portalAccess
    ) {}

    public function index(Request $request)
    {
        $customer = $this->portalAccess->customer($request);
        $owner = $this->portalAccess->ownerForCustomer($customer);
        $accountId = (int) $customer->user_id;

        $packages = CustomerPackage::query()
            ->forAccount($accountId)
            ->where('customer_id', $customer->id)
            ->with([
                'offerPackage',
                'usages.creator:id,name',
                'usages.product:id,name',
                'usages.reservation:id,starts_at',
            ])
            ->orderByRaw(
                "CASE status WHEN 'active' THEN 0 WHEN 'consumed' THEN 1 WHEN 'expired' THEN 2 ELSE 3 END"
            )
            ->latest('starts_at')
            ->latest('id')
            ->get();

        $invoiceMap = $this->relatedInvoices($packages, $accountId, (int) $customer->id);

        return $this->inertiaOrJson('Portal/Packages/Index', [
            'customer' => [
                'id' => (int) $customer->id,
                'name' => $this->customerLabel($customer),
                'email' => $customer->email,
                'phone' => $customer->phone,
            ],
            'company' => [
                'name' => $owner->company_name ?: config('app.name'),
                'logo_url' => $owner->company_logo_url,
                'currency_code' => $owner->businessCurrencyCode(),
            ],
            'packages' => $packages
                ->map(fn (CustomerPackage $package): array => $this->serializePackage($package, $invoiceMap))
                ->values()
                ->all(),
            'stats' => $this->stats($packages),
        ]);
    }

    public function requestRenewal(Request $request, CustomerPackage $customerPackage)
    {
        return $this->storePortalRequest($request, $customerPackage, 'renewal');
    }

    public function requestCancellation(Request $request, CustomerPackage $customerPackage)
    {
        return $this->storePortalRequest($request, $customerPackage, 'cancellation');
    }

    private function storePortalRequest(Request $request, CustomerPackage $package, string $type)
    {
        $customer = $this->portalAccess->customer($request);
        if ((int) $package->user_id !== (int) $customer->user_id || (int) $package->customer_id !== (int) $customer->id) {
            abort(404);
        }

        $canRequest = $type === 'renewal'
            ? $this->canRequestRenewal($package)
            : $this->canRequestCancellation($package);

        if (! $canRequest) {
            throw ValidationException::withMessages([
                'customer_package_id' => 'This request is not available for this package.',
            ]);
        }

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $metadata = (array) ($package->metadata ?? []);
        $requests = (array) data_get($metadata, 'portal.requests', []);
        $requests[$type] = array_filter([
            'requested_at' => now('UTC')->toIso8601String(),
            'requested_by_user_id' => $request->user()?->id,
            'note' => $validated['note'] ?? null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
        data_set($metadata, 'portal.requests', $requests);

        $package->forceFill([
            'metadata' => $metadata,
        ])->save();

        ActivityLog::record(
            $request->user(),
            $customer,
            $type === 'renewal'
                ? 'customer_package_portal_renewal_requested'
                : 'customer_package_portal_cancellation_requested',
            [
                'customer_package_id' => $package->id,
                'offer_package_id' => $package->offer_package_id,
                'note' => $validated['note'] ?? null,
            ],
            $type === 'renewal'
                ? 'Client requested package renewal'
                : 'Client requested package cancellation'
        );

        $message = $type === 'renewal'
            ? 'Demande de renouvellement envoyee.'
            : 'Demande d\'annulation envoyee.';

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => $message,
                'package' => $this->serializePackage($package->fresh(['offerPackage', 'usages.creator']), collect()),
            ]);
        }

        return redirect()->back()->with('success', $message);
    }

    /**
     * @param  Collection<int, CustomerPackage>  $packages
     * @return Collection<int, Invoice>
     */
    private function relatedInvoices(Collection $packages, int $accountId, int $customerId): Collection
    {
        $invoiceIds = $packages
            ->flatMap(fn (CustomerPackage $package): array => [
                $package->invoice_id,
                data_get($package->metadata, 'recurrence.pending_invoice_id'),
                data_get($package->metadata, 'recurrence.paid_invoice_id'),
            ])
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($invoiceIds->isEmpty()) {
            return collect();
        }

        return Invoice::query()
            ->where('user_id', $accountId)
            ->where('customer_id', $customerId)
            ->whereIn('id', $invoiceIds)
            ->withSum([
                'payments as payments_sum_amount' => fn ($query) => $query->whereIn('status', Payment::settledStatuses()),
            ], 'amount')
            ->latest('created_at')
            ->get()
            ->keyBy('id');
    }

    /**
     * @param  Collection<int, Invoice>  $invoiceMap
     * @return array<string, mixed>
     */
    private function serializePackage(CustomerPackage $package, Collection $invoiceMap): array
    {
        $metadata = (array) ($package->metadata ?? []);
        $sourceDetails = (array) ($package->source_details ?? []);
        $offerSnapshot = (array) data_get($sourceDetails, 'offer_package', []);
        $name = (string) ($offerSnapshot['name'] ?? $package->offerPackage?->name ?? 'Forfait');
        $description = (string) ($offerSnapshot['description'] ?? $package->offerPackage?->description ?? '');

        return [
            'id' => (int) $package->id,
            'name' => $name,
            'description' => $description,
            'status' => (string) $package->status,
            'starts_at' => $package->starts_at?->toDateString(),
            'expires_at' => $package->expires_at?->toDateString(),
            'consumed_at' => $package->consumed_at?->toIso8601String(),
            'cancelled_at' => $package->cancelled_at?->toIso8601String(),
            'initial_quantity' => (int) $package->initial_quantity,
            'consumed_quantity' => (int) $package->consumed_quantity,
            'remaining_quantity' => (int) $package->remaining_quantity,
            'unit_type' => (string) $package->unit_type,
            'price_paid' => (float) $package->price_paid,
            'currency_code' => (string) $package->currency_code,
            'is_recurring' => (bool) $package->is_recurring,
            'recurrence_frequency' => $package->recurrence_frequency,
            'recurrence_status' => $package->recurrence_status,
            'current_period_starts_at' => $package->current_period_starts_at?->toDateString(),
            'current_period_ends_at' => $package->current_period_ends_at?->toDateString(),
            'next_renewal_at' => $package->next_renewal_at?->toDateString(),
            'renewal_count' => (int) $package->renewal_count,
            'period_allocation_quantity' => (int) data_get($metadata, 'recurrence.period_allocation_quantity', $package->initial_quantity),
            'carry_over_unused_balance' => (bool) data_get($metadata, 'recurrence.carry_over_unused_balance', false),
            'carried_over_quantity' => (int) data_get($metadata, 'recurrence.carried_over_quantity', 0),
            'renewed_from_customer_package_id' => $package->renewed_from_customer_package_id
                ? (int) $package->renewed_from_customer_package_id
                : null,
            'portal_requests' => (array) data_get($metadata, 'portal.requests', []),
            'can_request_renewal' => $this->canRequestRenewal($package),
            'can_request_cancellation' => $this->canRequestCancellation($package),
            'invoices' => $this->serializeInvoicesForPackage($package, $invoiceMap),
            'usages' => $package->relationLoaded('usages')
                ? $package->usages
                    ->take(8)
                    ->map(fn (CustomerPackageUsage $usage): array => $this->serializeUsage($usage))
                    ->values()
                    ->all()
                : [],
        ];
    }

    /**
     * @param  Collection<int, Invoice>  $invoiceMap
     * @return array<int, array<string, mixed>>
     */
    private function serializeInvoicesForPackage(CustomerPackage $package, Collection $invoiceMap): array
    {
        $links = collect([
            ['id' => $package->invoice_id, 'type' => 'purchase'],
            ['id' => data_get($package->metadata, 'recurrence.pending_invoice_id'), 'type' => 'renewal'],
            ['id' => data_get($package->metadata, 'recurrence.paid_invoice_id'), 'type' => 'renewal'],
        ]);

        return $links
            ->map(fn (array $link): array => [
                'id' => (int) ($link['id'] ?? 0),
                'type' => (string) ($link['type'] ?? 'purchase'),
            ])
            ->filter(fn (array $link): bool => $link['id'] > 0 && $invoiceMap->has($link['id']))
            ->unique('id')
            ->map(function (array $link) use ($invoiceMap): array {
                /** @var Invoice $invoice */
                $invoice = $invoiceMap->get($link['id']);

                return [
                    'id' => (int) $invoice->id,
                    'type' => $link['type'],
                    'number' => (string) ($invoice->number ?: '#'.$invoice->id),
                    'status' => (string) $invoice->status,
                    'total' => (float) $invoice->total,
                    'amount_paid' => (float) $invoice->amount_paid,
                    'balance_due' => (float) $invoice->balance_due,
                    'currency_code' => (string) $invoice->currency_code,
                    'created_at' => $invoice->created_at?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }

    private function serializeUsage(CustomerPackageUsage $usage): array
    {
        $source = data_get($usage->metadata, 'source')
            ?: ($usage->reservation_id ? 'reservation' : 'manual');

        return [
            'id' => (int) $usage->id,
            'quantity' => (int) $usage->quantity,
            'used_at' => $usage->used_at?->toIso8601String(),
            'note' => $usage->note,
            'source' => (string) $source,
            'created_by_name' => $usage->creator?->name,
            'product_name' => $usage->product?->name,
            'reservation_id' => $usage->reservation_id ? (int) $usage->reservation_id : null,
            'reservation_starts_at' => $usage->reservation?->starts_at?->toIso8601String(),
        ];
    }

    /**
     * @param  Collection<int, CustomerPackage>  $packages
     * @return array<string, int>
     */
    private function stats(Collection $packages): array
    {
        $today = Carbon::today();
        $soon = $today->copy()->addDays(14);

        return [
            'total' => $packages->count(),
            'active' => $packages->where('status', CustomerPackage::STATUS_ACTIVE)->count(),
            'remaining_quantity' => (int) $packages
                ->where('status', CustomerPackage::STATUS_ACTIVE)
                ->sum('remaining_quantity'),
            'recurring' => $packages->where('is_recurring', true)->count(),
            'payment_due' => $packages->where('recurrence_status', CustomerPackage::RECURRENCE_PAYMENT_DUE)->count(),
            'expiring_soon' => $packages
                ->filter(fn (CustomerPackage $package): bool => $package->status === CustomerPackage::STATUS_ACTIVE
                    && $package->expires_at
                    && $package->expires_at->betweenIncluded($today, $soon))
                ->count(),
            'carried_over_quantity' => (int) $packages->sum(fn (CustomerPackage $package): int => (int) data_get(
                $package->metadata,
                'recurrence.carried_over_quantity',
                0
            )),
        ];
    }

    private function canRequestRenewal(CustomerPackage $package): bool
    {
        return $package->status !== CustomerPackage::STATUS_CANCELLED
            && $package->recurrence_status !== CustomerPackage::RECURRENCE_CANCELLED;
    }

    private function canRequestCancellation(CustomerPackage $package): bool
    {
        return $package->status !== CustomerPackage::STATUS_CANCELLED
            && $package->recurrence_status !== CustomerPackage::RECURRENCE_CANCELLED
            && ((int) $package->remaining_quantity > 0 || (bool) $package->is_recurring);
    }

    private function customerLabel(Customer $customer): string
    {
        if ($customer->company_name) {
            return (string) $customer->company_name;
        }

        $fullName = trim(
            implode(' ', array_filter([
                $customer->first_name,
                $customer->last_name,
            ]))
        );

        return $fullName !== '' ? $fullName : 'Client #'.$customer->id;
    }
}
