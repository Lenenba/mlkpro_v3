<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerPackage;
use App\Models\OfferPackage;
use App\Services\OfferPackages\CustomerPackageService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerPackageController extends Controller
{
    public function store(Request $request, Customer $customer, CustomerPackageService $service)
    {
        $this->authorize('update', $customer);

        $accountId = (int) $request->user()->accountOwnerId();
        $validated = $request->validate([
            'offer_package_id' => [
                'required',
                'integer',
                Rule::exists('offer_packages', 'id')
                    ->where(fn ($query) => $query
                        ->where('user_id', $accountId)
                        ->where('type', OfferPackage::TYPE_FORFAIT)
                        ->where('status', OfferPackage::STATUS_ACTIVE)),
            ],
            'initial_quantity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'price_paid' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'carry_over_unused_balance' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $offer = OfferPackage::query()
            ->forAccount($accountId)
            ->active()
            ->where('type', OfferPackage::TYPE_FORFAIT)
            ->with('items')
            ->findOrFail((int) $validated['offer_package_id']);

        $package = $service->assign($request->user(), $customer, $offer, $validated, [
            'source' => 'customer_manual_assignment',
        ]);

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Forfait assigned to customer.',
                'customerPackage' => $package,
            ], 201);
        }

        return redirect()
            ->route('customer.show', $customer)
            ->with('success', 'Forfait assigned to customer.');
    }

    public function consume(Request $request, Customer $customer, CustomerPackage $customerPackage, CustomerPackageService $service)
    {
        $this->authorize('update', $customer);

        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'used_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
            'allow_negative' => ['sometimes', 'boolean'],
        ]);

        $package = $service->consume($request->user(), $customer, $customerPackage, array_merge($validated, [
            'source' => 'customer_manual_usage',
        ]));

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Forfait usage recorded.',
                'customerPackage' => $package,
            ]);
        }

        return redirect()
            ->route('customer.show', $customer)
            ->with('success', 'Forfait usage recorded.');
    }

    public function renew(Request $request, Customer $customer, CustomerPackage $customerPackage, CustomerPackageService $service)
    {
        $this->authorize('update', $customer);

        $validated = $request->validate([
            'initial_quantity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'price_paid' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'carry_over_unused_balance' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $package = $service->renew($request->user(), $customer, $customerPackage, $validated);

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Recurring forfait renewed.',
                'customerPackage' => $package,
            ], 201);
        }

        return redirect()
            ->route('customer.show', $customer)
            ->with('success', 'Recurring forfait renewed.');
    }

    public function renewalInvoice(Request $request, Customer $customer, CustomerPackage $customerPackage, CustomerPackageService $service)
    {
        $this->authorize('update', $customer);

        $validated = $request->validate([
            'price_paid' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
        ]);

        $invoice = $service->createRenewalInvoice($request->user(), $customer, $customerPackage, $validated);

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Renewal invoice created.',
                'invoice' => $invoice,
            ], 201);
        }

        return redirect()
            ->route('invoice.show', $invoice)
            ->with('success', 'Renewal invoice created.');
    }

    public function cancelRecurring(Request $request, Customer $customer, CustomerPackage $customerPackage, CustomerPackageService $service)
    {
        $this->authorize('update', $customer);

        $validated = $request->validate([
            'mode' => ['required', Rule::in(['end_of_period', 'immediate'])],
            'reason' => ['required_if:mode,immediate', 'nullable', 'string', 'max:1000'],
        ]);

        $package = $service->cancelRecurring($request->user(), $customer, $customerPackage, $validated);

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => $validated['mode'] === 'immediate'
                    ? 'Recurring forfait cancelled.'
                    : 'Recurring forfait cancellation scheduled.',
                'customerPackage' => $package,
            ]);
        }

        return redirect()
            ->route('customer.show', $customer)
            ->with('success', $validated['mode'] === 'immediate'
                ? 'Recurring forfait cancelled.'
                : 'Recurring forfait cancellation scheduled.');
    }

    public function changeRecurringOffer(Request $request, Customer $customer, CustomerPackage $customerPackage, CustomerPackageService $service)
    {
        $this->authorize('update', $customer);

        $accountId = (int) $request->user()->accountOwnerId();
        $validated = $request->validate([
            'target_offer_package_id' => [
                'required',
                'integer',
                Rule::exists('offer_packages', 'id')
                    ->where(fn ($query) => $query
                        ->where('user_id', $accountId)
                        ->where('type', OfferPackage::TYPE_FORFAIT)
                        ->where('status', OfferPackage::STATUS_ACTIVE)
                        ->where('is_recurring', true)),
            ],
            'change_type' => ['required', Rule::in(['upgrade', 'downgrade'])],
            'initial_quantity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'price_paid' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'carry_over_unused_balance' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $targetOffer = OfferPackage::query()
            ->forAccount($accountId)
            ->active()
            ->where('type', OfferPackage::TYPE_FORFAIT)
            ->where('is_recurring', true)
            ->with('items')
            ->findOrFail((int) $validated['target_offer_package_id']);

        $package = $service->changeRecurringOffer(
            $request->user(),
            $customer,
            $customerPackage,
            $targetOffer,
            (string) $validated['change_type'],
            $validated
        );

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => $validated['change_type'] === 'upgrade'
                    ? 'Recurring forfait upgraded.'
                    : 'Recurring forfait downgraded.',
                'customerPackage' => $package,
            ], 201);
        }

        return redirect()
            ->route('customer.show', $customer)
            ->with('success', $validated['change_type'] === 'upgrade'
                ? 'Recurring forfait upgraded.'
                : 'Recurring forfait downgraded.');
    }
}
