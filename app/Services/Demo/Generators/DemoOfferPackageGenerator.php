<?php

namespace App\Services\Demo\Generators;

use App\Models\Customer;
use App\Models\CustomerBehaviorEvent;
use App\Models\CustomerPackage;
use App\Models\CustomerPackageUsage;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\LoyaltyPointLedger;
use App\Models\LoyaltyProgram;
use App\Models\OfferPackage;
use App\Models\OfferPackageItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\Sale;
use App\Services\Demo\DemoScenarioContext;
use App\Services\LoyaltyPointService;
use App\Services\OfferPackages\OfferPackageSalesLineBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DemoOfferPackageGenerator
{
    private const FORFAIT_KEYS = [
        'entretien_protecteur_6',
        'club_barbe_mensuel',
        'passeport_brushing_5',
        'cure_reparation_4',
    ];

    public function __construct(
        private readonly OfferPackageSalesLineBuilder $salesLineBuilder,
        private readonly LoyaltyPointService $loyaltyPointService,
    ) {}

    public function configureLoyaltyProgram(DemoScenarioContext $context): void
    {
        $program = LoyaltyProgram::query()->updateOrCreate(
            ['user_id' => $context->owner->id],
            [
                'is_enabled' => true,
                'points_per_currency_unit' => 5,
                'minimum_spend' => 25,
                'rounding_mode' => LoyaltyProgram::ROUND_FLOOR,
                'points_label' => 'Points Naya',
            ],
        );
        $createdAt = $context->referenceDate->subMonths(18)->startOfDay()->utc();

        DB::table($program->getTable())->where('id', $program->id)->update([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    /**
     * @param  array<string, mixed>  $blueprint
     * @param  array<string, int>  $targets
     * @param  Collection<int, Customer>  $customers
     * @param  Collection<string, Customer>  $storyCustomers
     * @param  Collection<string, Product>  $services
     * @param  Collection<string, Product>  $products
     * @param  Collection<int, int>  $reservationIds
     * @return array<string, int>
     */
    public function generate(
        DemoScenarioContext $context,
        array $blueprint,
        array $targets,
        Collection $customers,
        Collection $storyCustomers,
        Collection $services,
        Collection $products,
        Collection $reservationIds,
    ): array {
        $catalog = $this->createOfferCatalog($context, $blueprint, $targets, $services, $products);
        $packInvoiceLines = $this->createPackInvoiceLines(
            $context,
            $catalog['offers'],
            (int) ($targets['pack_invoice_lines'] ?? 0),
        );
        $packages = $this->createCustomerPackages(
            $context,
            $targets,
            $customers,
            $storyCustomers,
            $catalog['offers'],
            $reservationIds,
        );
        $this->normalizeLoyaltyHistory($context);
        $loyaltyStoryEvents = $this->createLoyaltyStoryEvents(
            $context,
            $storyCustomers,
            (int) ($targets['loyalty_story_events'] ?? 0),
        );

        $counts = [
            'offer_packages' => $catalog['offers']->count(),
            'offer_package_items' => $catalog['items'],
            'pack_invoice_lines' => $packInvoiceLines,
            ...$packages,
            'loyalty_story_events' => $loyaltyStoryEvents,
            'loyalty_point_ledgers' => LoyaltyPointLedger::query()
                ->where('user_id', $context->owner->id)
                ->count(),
        ];

        $this->validate($context, $targets, $counts);

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $blueprint
     * @param  array<string, int>  $targets
     * @param  Collection<string, Product>  $services
     * @param  Collection<string, Product>  $products
     * @return array{offers: Collection<string, OfferPackage>, items: int}
     */
    private function createOfferCatalog(
        DemoScenarioContext $context,
        array $blueprint,
        array $targets,
        Collection $services,
        Collection $products,
    ): array {
        $definitions = collect((array) ($blueprint['offer_packages'] ?? []));
        $offerTarget = (int) ($targets['offer_packages'] ?? 0);
        $itemTarget = (int) ($targets['offer_package_items'] ?? 0);

        if ($definitions->count() !== $offerTarget) {
            throw new RuntimeException(sprintf(
                'Studio Naya defines %d offers for a target of %d.',
                $definitions->count(),
                $offerTarget,
            ));
        }

        $offers = collect();
        $itemCount = 0;

        foreach ($definitions as $offerIndex => $definition) {
            $createdAt = $context->referenceDate
                ->subMonths(14)
                ->addWeeks($offerIndex * 3)
                ->setTime(9, 0)
                ->utc();
            $offer = OfferPackage::query()->create([
                'user_id' => $context->owner->id,
                'name' => (string) $definition['name'],
                'slug' => str_replace('_', '-', (string) $definition['key']),
                'type' => (string) $definition['type'],
                'status' => (string) $definition['status'],
                'description' => (string) $definition['description'],
                'image_path' => $definition['image_path'] ?? null,
                'pricing_mode' => (string) $definition['pricing_mode'],
                'price' => (float) $definition['price'],
                'currency_code' => (string) $definition['currency_code'],
                'validity_days' => $definition['validity_days'] ?? null,
                'included_quantity' => $definition['included_quantity'] ?? null,
                'unit_type' => $definition['unit_type'] ?? null,
                'is_public' => (bool) $definition['is_public'],
                'is_recurring' => (bool) $definition['is_recurring'],
                'recurrence_frequency' => $definition['recurrence_frequency'] ?? null,
                'renewal_notice_days' => $definition['renewal_notice_days'] ?? null,
                'metadata' => (array) ($definition['metadata'] ?? []),
            ]);
            DB::table($offer->getTable())->where('id', $offer->id)->update([
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            foreach ((array) $definition['items'] as $sortOrder => $itemDefinition) {
                $catalog = (string) ($itemDefinition['catalog'] ?? 'service');
                $product = $catalog === 'product'
                    ? $products->get((string) $itemDefinition['key'])
                    : $services->get((string) $itemDefinition['key']);

                if (! $product || (int) $product->user_id !== (int) $context->owner->id) {
                    throw new RuntimeException(sprintf(
                        'Studio Naya offer [%s] references missing tenant catalog item [%s:%s].',
                        $definition['key'],
                        $catalog,
                        $itemDefinition['key'] ?? '',
                    ));
                }

                $item = OfferPackageItem::query()->create([
                    'offer_package_id' => $offer->id,
                    'product_id' => $product->id,
                    'item_type_snapshot' => $product->item_type,
                    'name_snapshot' => $product->name,
                    'description_snapshot' => $product->description,
                    'quantity' => (float) ($itemDefinition['quantity'] ?? 1),
                    'unit_price' => (float) $product->price,
                    'included' => (bool) ($itemDefinition['included'] ?? true),
                    'is_optional' => (bool) ($itemDefinition['is_optional'] ?? false),
                    'sort_order' => $sortOrder,
                    'metadata' => [
                        'scenario_key' => 'studio_naya_coiffure',
                        'catalog_key' => (string) $itemDefinition['key'],
                    ],
                ]);
                DB::table($item->getTable())->where('id', $item->id)->update([
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);
                $itemCount++;
            }

            if ($offer->type === OfferPackage::TYPE_PACK) {
                $retailValue = round((float) $offer->items()
                    ->get(['quantity', 'unit_price'])
                    ->sum(fn (OfferPackageItem $item): float => (float) $item->quantity * (float) $item->unit_price), 2);
                $expectedSavings = round($retailValue - (float) $offer->price, 2);
                $configuredSavings = round((float) data_get($offer->metadata, 'savings_amount', 0), 2);

                if ($expectedSavings <= 0 || abs($configuredSavings - $expectedSavings) > 0.009) {
                    throw new RuntimeException(sprintf(
                        'Studio Naya pack [%s] must price its %0.2f catalog value with coherent savings (expected %0.2f, configured %0.2f).',
                        $definition['key'],
                        $retailValue,
                        $expectedSavings,
                        $configuredSavings,
                    ));
                }
            }

            $offers->put((string) $definition['key'], $offer->fresh('items.product'));
        }

        if ($itemCount !== $itemTarget) {
            throw new RuntimeException(sprintf(
                'Studio Naya generated %d offer items for a target of %d.',
                $itemCount,
                $itemTarget,
            ));
        }

        return ['offers' => $offers, 'items' => $itemCount];
    }

    /**
     * @param  Collection<string, OfferPackage>  $offers
     */
    private function createPackInvoiceLines(
        DemoScenarioContext $context,
        Collection $offers,
        int $target,
    ): int {
        if ($target <= 0) {
            return 0;
        }

        $packs = $offers
            ->filter(fn (OfferPackage $offer): bool => $offer->type === OfferPackage::TYPE_PACK)
            ->values();
        $candidates = Invoice::query()
            ->byUser((int) $context->owner->id)
            ->whereIn('status', ['draft', 'sent', 'overdue', 'paid'])
            ->with(['items', 'payments.loyaltyPointLedgers'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (Invoice $invoice): bool => ! $this->invoiceContainsOfferLine($invoice));

        if ($packs->isEmpty()) {
            throw new RuntimeException('Studio Naya needs active pack offers before generating pack sales.');
        }

        $paidTarget = max(1, (int) ceil($target / 2));
        $overdueTarget = max(1, (int) floor($target / 4));
        $sentTarget = $target - $paidTarget - $overdueTarget;
        $paid = $candidates
            ->where('status', 'paid')
            ->filter(fn (Invoice $invoice): bool => $this->canExtendPaidInvoice($invoice))
            ->values();
        $overdue = $candidates
            ->where('status', 'overdue')
            ->filter(fn (Invoice $invoice): bool => $invoice->payments->isEmpty())
            ->values();
        $sent = $candidates
            ->whereIn('status', ['draft', 'sent'])
            ->filter(fn (Invoice $invoice): bool => $invoice->payments->isEmpty())
            ->values();

        if ($paid->count() < $paidTarget
            || $overdue->count() < $overdueTarget
            || $sent->count() < $sentTarget) {
            throw new RuntimeException(sprintf(
                'Studio Naya pack sales need %d paid, %d sent and %d overdue invoices; %d/%d/%d are available.',
                $paidTarget,
                $sentTarget,
                $overdueTarget,
                $paid->count(),
                $sent->count(),
                $overdue->count(),
            ));
        }

        $selectedInvoices = $this->evenlyDistributed($paid, $paidTarget)
            ->concat($this->evenlyDistributed($sent, $sentTarget))
            ->concat($this->evenlyDistributed($overdue, $overdueTarget))
            ->values();

        foreach ($selectedInvoices as $index => $invoice) {
            $offer = $packs[$index % $packs->count()];
            $lineAt = CarbonImmutable::instance($invoice->created_at)->addMinutes(10)->utc();
            $this->appendOfferLineToInvoice(
                $context,
                $invoice,
                $offer,
                $lineAt,
                ['sale_story' => $invoice->status === 'paid' ? 'pack_paid' : 'pack_payment_pending'],
                $invoice->status === 'paid',
            );
        }

        return $selectedInvoices->count();
    }

    /**
     * @param  array<string, int>  $targets
     * @param  Collection<int, Customer>  $customers
     * @param  Collection<string, Customer>  $storyCustomers
     * @param  Collection<string, OfferPackage>  $offers
     * @param  Collection<int, int>  $reservationIds
     * @return array<string, int>
     */
    private function createCustomerPackages(
        DemoScenarioContext $context,
        array $targets,
        Collection $customers,
        Collection $storyCustomers,
        Collection $offers,
        Collection $reservationIds,
    ): array {
        $packageTarget = (int) ($targets['customer_packages'] ?? 0);
        $usageTarget = (int) ($targets['customer_package_usages'] ?? 0);
        $behaviorTarget = (int) ($targets['package_behavior_events'] ?? 0);

        if ($packageTarget % 4 !== 0
            || $usageTarget !== intdiv($packageTarget, 4) * 13 + 1
            || $behaviorTarget !== intdiv($packageTarget, 4) * 6) {
            throw new RuntimeException('Studio Naya forfait targets do not preserve the four-state lifecycle contract.');
        }

        $forfaits = collect(self::FORFAIT_KEYS)
            ->mapWithKeys(fn (string $key): array => [$key => $offers->get($key)])
            ->filter();
        if ($forfaits->count() !== count(self::FORFAIT_KEYS)) {
            throw new RuntimeException('Studio Naya is missing one or more required forfait offers.');
        }

        $narrativeCustomers = collect([
            $storyCustomers->get('aicha_martin'),
            $storyCustomers->get('marc_andre_beaulieu'),
            $storyCustomers->get('chloe_nguyen'),
            $storyCustomers->get('nadia_pierre'),
        ])->filter()->values();
        if ($narrativeCustomers->count() !== 4) {
            throw new RuntimeException('Studio Naya forfait narratives require Aïcha, Marc-André, Chloé and Nadia.');
        }

        $narrativeIds = $narrativeCustomers->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $eligibleCustomers = $customers
            ->reject(fn (Customer $customer): bool => in_array((int) $customer->id, $narrativeIds, true))
            ->sortBy('created_at')
            ->values();
        if ($eligibleCustomers->count() < $packageTarget - 4) {
            throw new RuntimeException('Studio Naya does not have enough tenant customers for forfait assignments.');
        }

        $pendingInvoiceCandidates = Invoice::query()
            ->byUser((int) $context->owner->id)
            ->whereIn('status', ['draft', 'sent', 'overdue'])
            ->whereDoesntHave('payments')
            ->with('items')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (Invoice $invoice): bool => ! $this->invoiceContainsOfferLine($invoice));
        $customerAssignments = $narrativeCustomers->all();
        $pendingInvoicesByPackageIndex = [];
        $usedCustomerIds = array_fill_keys($narrativeIds, true);
        $usedPendingInvoiceIds = [];
        $eligibleCustomerIds = $eligibleCustomers
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->flip();

        foreach (range(4, $packageTarget - 1) as $index) {
            if ($index % 4 !== 1) {
                continue;
            }

            $cycle = intdiv($index, 4);
            $recurrenceStatus = $this->recurrenceStatusForCycle($cycle);
            if ($recurrenceStatus === CustomerPackage::RECURRENCE_ACTIVE) {
                continue;
            }

            $pendingInvoice = $pendingInvoiceCandidates->first(function (Invoice $invoice) use (
                $eligibleCustomerIds,
                $recurrenceStatus,
                $usedCustomerIds,
                $usedPendingInvoiceIds,
            ): bool {
                $customerId = (int) $invoice->customer_id;
                $statusMatches = $recurrenceStatus === CustomerPackage::RECURRENCE_SUSPENDED
                    ? $invoice->status === 'overdue'
                    : in_array($invoice->status, ['draft', 'sent'], true);

                return $statusMatches
                    && $eligibleCustomerIds->has($customerId)
                    && ! isset($usedCustomerIds[$customerId])
                    && ! isset($usedPendingInvoiceIds[(int) $invoice->id]);
            });
            if (! $pendingInvoice) {
                throw new RuntimeException(sprintf(
                    'Studio Naya cannot attach a pending invoice to recurring forfait state [%s].',
                    $recurrenceStatus,
                ));
            }

            $customer = $customers->firstWhere('id', $pendingInvoice->customer_id);
            if (! $customer) {
                throw new RuntimeException('Studio Naya pending renewal invoice references an unavailable customer.');
            }

            $customerAssignments[$index] = $customer;
            $pendingInvoicesByPackageIndex[$index] = $pendingInvoice;
            $usedCustomerIds[(int) $customer->id] = true;
            $usedPendingInvoiceIds[(int) $pendingInvoice->id] = true;
        }

        foreach (range(4, $packageTarget - 1) as $index) {
            if (isset($customerAssignments[$index])) {
                continue;
            }

            $customer = $eligibleCustomers->first(
                fn (Customer $candidate): bool => ! isset($usedCustomerIds[(int) $candidate->id]),
            );
            if (! $customer) {
                throw new RuntimeException('Studio Naya ran out of unique customers for forfait assignments.');
            }

            $customerAssignments[$index] = $customer;
            $usedCustomerIds[(int) $customer->id] = true;
        }

        $billedReservationIds = InvoiceItem::query()
            ->whereHas('invoice', fn ($query) => $query->where('user_id', $context->owner->id))
            ->get(['meta'])
            ->map(fn (InvoiceItem $item): int => (int) data_get($item->meta, 'reservation_id', 0))
            ->filter()
            ->flip();
        $reservations = Reservation::query()
            ->forAccount((int) $context->owner->id)
            ->whereIn('id', $reservationIds->all())
            ->where('status', Reservation::STATUS_COMPLETED)
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (Reservation $reservation): int => (int) $reservation->client_id);
        $usedReservationIds = [];
        $usedPaidInvoiceIds = [];
        $createdPackages = collect();
        $usageCount = 0;
        $behaviorCount = 0;
        $carryOverChild = null;

        foreach (range(0, $packageTarget - 1) as $index) {
            $stateIndex = $index % 4;
            $cycle = intdiv($index, 4);
            $isCarryOverParent = $index === 2;
            $offerKey = $isCarryOverParent ? self::FORFAIT_KEYS[1] : self::FORFAIT_KEYS[$stateIndex];
            $offer = $forfaits->get($offerKey);
            $customer = $isCarryOverParent ? $narrativeCustomers[1] : $customerAssignments[$index];
            $profile = $this->packageProfile($context, $customer, $offer, $stateIndex, $cycle);
            if ($isCarryOverParent) {
                if (! $carryOverChild instanceof CustomerPackage) {
                    throw new RuntimeException('Studio Naya carry-over parent requires its renewed forfait child.');
                }

                $renewedDate = ($carryOverChild->current_period_starts_at ?: $carryOverChild->starts_at)
                    ?->toDateString();
                if (! $renewedDate) {
                    throw new RuntimeException('Studio Naya carry-over child requires a renewal date.');
                }
                $renewedStartsAt = CarbonImmutable::parse($renewedDate, $context->timezone)->startOfDay();
                $profile['expires_at'] = $renewedStartsAt->subDay()->endOfDay();
                $profile['usage_window_end'] = $profile['usage_window_end']->min(
                    $profile['expires_at']->subDay()->setTime(14, 0),
                );
                $profile['last_activity_at'] = $profile['expires_at'];
                $profile['is_recurring'] = true;
                $profile['recurrence_frequency'] = OfferPackage::RECURRENCE_MONTHLY;
                $profile['recurrence_status'] = CustomerPackage::RECURRENCE_ACTIVE;
                $profile['current_period_starts_at'] = $profile['starts_at'];
                $profile['current_period_ends_at'] = $profile['expires_at'];
                $profile['next_renewal_at'] = $renewedStartsAt;
            }
            $paidInvoice = $this->paidInvoiceForPackage(
                $context,
                $customer,
                $profile,
                $usedPaidInvoiceIds,
            );
            $paidInvoiceItem = null;
            if ($paidInvoice) {
                $paidInvoiceItem = $this->appendOfferLineToInvoice(
                    $context,
                    $paidInvoice,
                    $offer,
                    CarbonImmutable::instance($paidInvoice->created_at)->addMinutes(12)->utc(),
                    ['assignment_story' => 'initial_forfait_purchase'],
                    true,
                );
                $usedPaidInvoiceIds[(int) $paidInvoice->id] = true;
                $paidAt = $this->settledAt($paidInvoice)->setTimezone($context->timezone);
                $profile['starts_at'] = $paidAt->startOfDay();
                $profile['assigned_at'] = $paidAt;
                if ($profile['usage_window_start']->lt($paidAt)) {
                    $profile['usage_window_start'] = $paidAt;
                }
            }

            $sourceDetails = $paidInvoiceItem
                ? (array) data_get($paidInvoiceItem->meta, 'source_details', [])
                : $this->salesLineBuilder->sourceDetails($offer);
            $sourceDetails['snapshot_at'] = $profile['assigned_at']->toIso8601String();
            $sourceDetails['assignment'] = $paidInvoiceItem
                ? [
                    'source' => 'paid_invoice_item',
                    'assigned_by_user_id' => $context->owner->id,
                    'invoice_id' => $paidInvoice?->id,
                    'invoice_item_id' => $paidInvoiceItem->id,
                    'paid_at' => $profile['assigned_at']->toIso8601String(),
                ]
                : [
                    'source' => 'demo_complimentary_grant',
                    'assigned_by_user_id' => $context->owner->id,
                ];
            $metadata = [
                'scenario_key' => 'studio_naya_coiffure',
                'offer_key' => $offerKey,
                'lifecycle_state' => $profile['lifecycle_state'],
                'balance_state' => $profile['balance_state'],
                'recurrence_enabled' => (bool) $profile['is_recurring'],
                'provisioning' => $paidInvoiceItem
                    ? [
                        'source' => 'paid_invoice_item',
                        'invoice_id' => $paidInvoice?->id,
                        'invoice_item_id' => $paidInvoiceItem->id,
                        'line_quantity' => 1,
                        'rights_per_unit' => $profile['initial_quantity'],
                        'allocated_quantity' => $profile['initial_quantity'],
                        'unit_price' => (float) $paidInvoiceItem->unit_price,
                        'line_total' => (float) $paidInvoiceItem->total,
                        'paid_at' => $profile['assigned_at']->toIso8601String(),
                    ]
                    : [
                        'source' => 'demo_complimentary_grant',
                        'reason' => 'immersive_scenario_history',
                    ],
            ];
            if ($profile['is_recurring']) {
                $metadata['recurrence'] = [
                    'period_allocation_quantity' => $profile['initial_quantity'],
                    'subscription_quantity' => 1,
                    'carry_over_unused_balance' => true,
                    'carried_over_quantity' => 0,
                    'payment_grace_days' => 7,
                    'payment_reminder_days' => [0, 3, 6],
                    'demo_recurrence_state' => $profile['recurrence_status'],
                ];
            }
            if ($profile['lifecycle_state'] === 'active_low_balance') {
                $metadata['automation'] = [
                    'last_checked_at' => $profile['last_activity_at']->addMinutes(5)->utc()->toIso8601String(),
                    'notifications' => [
                        'low_balance_sent_at' => [
                            'sent_at' => $profile['last_activity_at']->addMinutes(5)->utc()->toIso8601String(),
                            'low_balance_threshold' => 1,
                        ],
                    ],
                ];
            } elseif ($profile['lifecycle_state'] === 'expired_with_balance') {
                $metadata['automation'] = [
                    'last_checked_at' => $profile['expires_at']->endOfDay()->utc()->toIso8601String(),
                    'expired_at' => $profile['expires_at']->endOfDay()->utc()->toIso8601String(),
                ];
            }

            $package = CustomerPackage::query()->create([
                'user_id' => $context->owner->id,
                'customer_id' => $customer->id,
                'offer_package_id' => $offer->id,
                'invoice_id' => $paidInvoice?->id,
                'invoice_item_id' => $paidInvoiceItem?->id,
                'status' => $profile['status'],
                'starts_at' => $profile['starts_at']->toDateString(),
                'expires_at' => $profile['expires_at']?->toDateString(),
                'consumed_at' => $profile['consumed_at']?->utc(),
                'initial_quantity' => $profile['initial_quantity'],
                'consumed_quantity' => $profile['consumed_quantity'],
                'remaining_quantity' => $profile['initial_quantity'] - $profile['consumed_quantity'],
                'unit_type' => $offer->unit_type,
                'price_paid' => $paidInvoiceItem ? (float) $paidInvoiceItem->total : 0,
                'currency_code' => $offer->currency_code,
                'is_recurring' => $profile['is_recurring'],
                'recurrence_frequency' => $profile['recurrence_frequency'],
                'recurrence_status' => $profile['recurrence_status'],
                'current_period_starts_at' => $profile['current_period_starts_at']?->toDateString(),
                'current_period_ends_at' => $profile['current_period_ends_at']?->toDateString(),
                'next_renewal_at' => $profile['next_renewal_at']?->toDateString(),
                'renewal_count' => $profile['renewal_count'],
                'source_details' => $sourceDetails,
                'metadata' => $metadata,
            ]);

            if (isset($pendingInvoicesByPackageIndex[$index])) {
                $renewalInvoice = $pendingInvoicesByPackageIndex[$index];
                $renewalLine = $this->appendOfferLineToInvoice(
                    $context,
                    $renewalInvoice,
                    $offer,
                    CarbonImmutable::instance($renewalInvoice->created_at)->addMinutes(12)->utc(),
                    [
                        'source' => 'customer_package_renewal',
                        'added_from' => 'recurring_renewal_invoice',
                        'customer_package_id' => $package->id,
                        'renewal_for_customer_package_id' => $package->id,
                        'recurrence_frequency' => $package->recurrence_frequency,
                        'subscription_quantity' => 1,
                        'next_renewal_at' => $package->next_renewal_at?->toDateString(),
                        'carry_over_unused_balance' => true,
                    ],
                    false,
                );
                $metadata = (array) $package->metadata;
                $metadata['recurrence'] = array_merge((array) ($metadata['recurrence'] ?? []), [
                    'pending_invoice_id' => $renewalInvoice->id,
                    'pending_invoice_item_id' => $renewalLine->id,
                    'pending_invoice_created_at' => CarbonImmutable::instance($renewalInvoice->created_at)->utc()->toIso8601String(),
                    'pending_invoice_status' => $renewalInvoice->fresh()->status,
                    'pending_invoice_total' => (float) $renewalInvoice->fresh()->total,
                ]);
                if ($profile['recurrence_status'] === CustomerPackage::RECURRENCE_SUSPENDED) {
                    $metadata['recurrence']['suspended_at'] = $profile['current_period_ends_at']
                        ->addDays(8)
                        ->startOfDay()
                        ->utc()
                        ->toIso8601String();
                    $metadata['recurrence']['suspension_reason'] = 'renewal_payment_overdue';
                }
                $package->forceFill(['metadata' => $metadata])->save();
            }

            DB::table($package->getTable())->where('id', $package->id)->update([
                'created_at' => $profile['assigned_at']->utc(),
                'updated_at' => ($profile['last_activity_at'] ?? $profile['assigned_at'])->utc(),
            ]);

            $offerServiceIds = $offer->items
                ->where('item_type_snapshot', Product::ITEM_TYPE_SERVICE)
                ->pluck('product_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();
            $customerReservations = collect($reservations->get((int) $customer->id, collect()))
                ->filter(fn (Reservation $reservation): bool => in_array((int) $reservation->service_id, $offerServiceIds, true))
                ->reject(fn (Reservation $reservation): bool => $billedReservationIds->has((int) $reservation->id))
                ->filter(fn (Reservation $reservation): bool => CarbonImmutable::instance($reservation->ends_at)
                    ->betweenIncluded($profile['usage_window_start']->utc(), $profile['usage_window_end']->utc()))
                ->values();

            foreach (range(0, $profile['consumed_quantity'] - 1) as $usageIndex) {
                $usedAt = $this->distributedInstant(
                    $profile['usage_window_start'],
                    $profile['usage_window_end'],
                    $usageIndex,
                    $profile['consumed_quantity'],
                );
                $reservation = $customerReservations
                    ->first(fn (Reservation $candidate): bool => ! isset($usedReservationIds[(int) $candidate->id]));
                if ($reservation) {
                    $usedReservationIds[(int) $reservation->id] = true;
                    $usedAt = CarbonImmutable::instance($reservation->ends_at)->setTimezone($context->timezone);
                }
                $productId = $reservation?->service_id ?: ($offerServiceIds[$usageIndex % count($offerServiceIds)] ?? null);

                $usage = CustomerPackageUsage::query()->create([
                    'customer_package_id' => $package->id,
                    'user_id' => $context->owner->id,
                    'customer_id' => $customer->id,
                    'reservation_id' => $reservation?->id,
                    'invoice_id' => null,
                    'product_id' => $productId,
                    'created_by_user_id' => $context->owner->id,
                    'quantity' => 1,
                    'used_at' => $usedAt->utc(),
                    'note' => $reservation
                        ? 'Visite couverte par le forfait Studio Naya.'
                        : 'Utilisation historique saisie au comptoir.',
                    'metadata' => [
                        'scenario_key' => 'studio_naya_coiffure',
                        'source' => $reservation ? 'reservation_completed' : 'customer_manual_usage',
                        'offer_key' => $offerKey,
                    ],
                ]);
                DB::table($usage->getTable())->where('id', $usage->id)->update([
                    'created_at' => $usedAt->utc(),
                    'updated_at' => $usedAt->utc(),
                ]);
                $usageCount++;
            }

            $behaviorCount += $this->createPackageBehaviorEvents(
                $context,
                $customer,
                $package->fresh(),
                $offer,
                $profile,
            );
            $createdPackages->push($package->fresh());
            if ($index === 1) {
                $carryOverChild = $package->fresh(['invoiceItem', 'invoice']);
            } elseif ($isCarryOverParent && $carryOverChild) {
                $this->linkCarryOverRenewal(
                    $context,
                    $package->fresh(),
                    $carryOverChild,
                );
                $carryOverChild = $carryOverChild->fresh();
                $createdPackages = $createdPackages->map(
                    fn (CustomerPackage $created): CustomerPackage => (int) $created->id === (int) $carryOverChild->id
                        ? $carryOverChild
                        : $created,
                );
            }
        }

        $firstPackage = $createdPackages->firstOrFail();
        $reversedUsedAt = CarbonImmutable::instance($firstPackage->starts_at)
            ->setTimezone($context->timezone)
            ->addDays(3)
            ->setTime(11, 0);
        $reversed = CustomerPackageUsage::query()->create([
            'customer_package_id' => $firstPackage->id,
            'user_id' => $context->owner->id,
            'customer_id' => $firstPackage->customer_id,
            'product_id' => $firstPackage->offerPackage?->items?->first()?->product_id,
            'created_by_user_id' => $context->owner->id,
            'quantity' => 1,
            'used_at' => $reversedUsedAt->utc(),
            'reversed_at' => $reversedUsedAt->addDay()->utc(),
            'reversed_by_user_id' => $context->owner->id,
            'reversal_reason' => 'reservation_rescheduled',
            'note' => 'Crédit rétabli après déplacement du rendez-vous.',
            'metadata' => [
                'scenario_key' => 'studio_naya_coiffure',
                'source' => 'reservation_status_changed',
            ],
        ]);
        DB::table($reversed->getTable())->where('id', $reversed->id)->update([
            'created_at' => $reversedUsedAt->utc(),
            'updated_at' => $reversedUsedAt->addDay()->utc(),
        ]);
        $usageCount++;

        return [
            'customer_packages' => $createdPackages->count(),
            'customer_package_usages' => $usageCount,
            'package_behavior_events' => $behaviorCount,
            'customer_packages_active' => $createdPackages->where('status', CustomerPackage::STATUS_ACTIVE)->count(),
            'customer_packages_consumed' => $createdPackages->where('status', CustomerPackage::STATUS_CONSUMED)->count(),
            'customer_packages_expired' => $createdPackages->where('status', CustomerPackage::STATUS_EXPIRED)->count(),
            'customer_packages_recurring' => $createdPackages->where('is_recurring', true)->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function packageProfile(
        DemoScenarioContext $context,
        Customer $customer,
        OfferPackage $offer,
        int $stateIndex,
        int $cycle,
    ): array {
        $reference = $context->referenceDate;
        $customerReadyAt = CarbonImmutable::instance($customer->created_at)
            ->setTimezone($context->timezone)
            ->addDays(7)
            ->startOfDay();
        $plannedStart = match ($stateIndex) {
            0 => $reference->subDays(95 + ($cycle * 3))->startOfDay(),
            1 => $reference->subMonths(3 + ($cycle % 5))->startOfMonth(),
            2 => $reference->subDays(175 + ($cycle * 2))->startOfDay(),
            default => $reference->subDays(130 + ($cycle * 2))->startOfDay(),
        };
        $startsAt = $customerReadyAt->gt($plannedStart) ? $customerReadyAt : $plannedStart;
        $assignedAt = $startsAt->setTime(9, 0);

        if ($stateIndex === 0) {
            $expiresAt = $startsAt->addDays((int) $offer->validity_days);
            $usageEnd = $reference->subDays(5 + ($cycle % 8))->setTime(15, 0);

            return [
                'status' => CustomerPackage::STATUS_ACTIVE,
                'lifecycle_state' => 'active_low_balance',
                'balance_state' => 'low',
                'starts_at' => $startsAt,
                'expires_at' => $expiresAt,
                'assigned_at' => $assignedAt,
                'initial_quantity' => 6,
                'consumed_quantity' => 5,
                'consumed_at' => null,
                'usage_window_start' => $startsAt->addDays(10)->setTime(10, 0),
                'usage_window_end' => $usageEnd,
                'last_activity_at' => $usageEnd,
                'is_recurring' => false,
                'recurrence_frequency' => null,
                'recurrence_status' => null,
                'current_period_starts_at' => null,
                'current_period_ends_at' => null,
                'next_renewal_at' => null,
                'renewal_count' => 0,
            ];
        }

        if ($stateIndex === 1) {
            $recurrenceStatus = match ($cycle % 3) {
                1 => CustomerPackage::RECURRENCE_PAYMENT_DUE,
                2 => CustomerPackage::RECURRENCE_SUSPENDED,
                default => CustomerPackage::RECURRENCE_ACTIVE,
            };
            $periodStartsAt = $recurrenceStatus === CustomerPackage::RECURRENCE_ACTIVE
                ? $reference->startOfMonth()
                : $reference->subMonth()->startOfMonth();
            $periodEndsAt = match ($recurrenceStatus) {
                CustomerPackage::RECURRENCE_PAYMENT_DUE => $reference->subDays(2)->endOfDay(),
                CustomerPackage::RECURRENCE_SUSPENDED => $reference->subDays(9)->endOfDay(),
                default => $reference->endOfMonth(),
            };
            $nextRenewalAt = $periodEndsAt->addDay()->startOfDay();
            $usageEnd = $reference->subDays(6 + ($cycle % 6))->setTime(14, 0);
            if ($recurrenceStatus === CustomerPackage::RECURRENCE_SUSPENDED
                && $usageEnd->gte($periodEndsAt)) {
                $usageEnd = $periodEndsAt->subDay()->setTime(14, 0);
            }

            return [
                'status' => CustomerPackage::STATUS_ACTIVE,
                'lifecycle_state' => 'active_recurring',
                'balance_state' => 'partial',
                'starts_at' => $startsAt,
                'expires_at' => $recurrenceStatus === CustomerPackage::RECURRENCE_ACTIVE
                    ? $periodEndsAt
                    : $reference->addDays(7),
                'assigned_at' => $assignedAt,
                'initial_quantity' => 2,
                'consumed_quantity' => 1,
                'consumed_at' => null,
                'usage_window_start' => $periodStartsAt->setTime(10, 0),
                'usage_window_end' => $usageEnd,
                'last_activity_at' => $usageEnd,
                'is_recurring' => true,
                'recurrence_frequency' => OfferPackage::RECURRENCE_MONTHLY,
                'recurrence_status' => $recurrenceStatus,
                'current_period_starts_at' => $periodStartsAt,
                'current_period_ends_at' => $periodEndsAt,
                'next_renewal_at' => $nextRenewalAt,
                'renewal_count' => 0,
            ];
        }

        if ($stateIndex === 2) {
            $expiresAt = $reference->subDays(30 + $cycle)->endOfDay();
            $usageEnd = $expiresAt->subDays(7)->setTime(13, 0);

            return [
                'status' => CustomerPackage::STATUS_EXPIRED,
                'lifecycle_state' => 'expired_with_balance',
                'balance_state' => 'expired_remaining',
                'starts_at' => $startsAt,
                'expires_at' => $expiresAt,
                'assigned_at' => $assignedAt,
                'initial_quantity' => 5,
                'consumed_quantity' => 3,
                'consumed_at' => null,
                'usage_window_start' => $startsAt->addDays(12)->setTime(10, 0),
                'usage_window_end' => $usageEnd,
                'last_activity_at' => $expiresAt,
                'is_recurring' => false,
                'recurrence_frequency' => null,
                'recurrence_status' => null,
                'current_period_starts_at' => null,
                'current_period_ends_at' => null,
                'next_renewal_at' => null,
                'renewal_count' => 0,
            ];
        }

        $usageEnd = $reference->subDays(12 + ($cycle % 10))->setTime(16, 0);

        return [
            'status' => CustomerPackage::STATUS_CONSUMED,
            'lifecycle_state' => 'fully_consumed',
            'balance_state' => 'empty',
            'starts_at' => $startsAt,
            'expires_at' => $startsAt->addDays((int) $offer->validity_days),
            'assigned_at' => $assignedAt,
            'initial_quantity' => 4,
            'consumed_quantity' => 4,
            'consumed_at' => $usageEnd,
            'usage_window_start' => $startsAt->addDays(10)->setTime(10, 0),
            'usage_window_end' => $usageEnd,
            'last_activity_at' => $usageEnd,
            'is_recurring' => false,
            'recurrence_frequency' => null,
            'recurrence_status' => null,
            'current_period_starts_at' => null,
            'current_period_ends_at' => null,
            'next_renewal_at' => null,
            'renewal_count' => 0,
        ];
    }

    private function createPackageBehaviorEvents(
        DemoScenarioContext $context,
        Customer $customer,
        CustomerPackage $package,
        OfferPackage $offer,
        array $profile,
    ): int {
        $primaryItem = $offer->items->first();
        $events = [[
            'type' => 'customer_package_purchased',
            'at' => $profile['assigned_at'],
        ]];
        if ($profile['lifecycle_state'] === 'active_low_balance') {
            $events[] = [
                'type' => 'customer_package_low_balance',
                'at' => $profile['last_activity_at']->addMinutes(5),
            ];
        }
        if ($profile['lifecycle_state'] === 'expired_with_balance') {
            $events[] = [
                'type' => 'customer_package_expired',
                'at' => $profile['expires_at']->endOfDay(),
            ];
        }

        foreach ($events as $event) {
            $behavior = CustomerBehaviorEvent::query()->create([
                'user_id' => $context->owner->id,
                'customer_id' => $customer->id,
                'product_id' => $primaryItem?->product_id,
                'category_id' => $primaryItem?->product?->category_id,
                'event_type' => $event['type'],
                'occurred_at' => $event['at']->utc(),
                'metadata' => [
                    'scenario_key' => 'studio_naya_coiffure',
                    'customer_package_id' => $package->id,
                    'offer_package_id' => $offer->id,
                    'offer_package_name' => $offer->name,
                    'status' => $package->status,
                    'remaining_quantity' => $package->remaining_quantity,
                    'initial_quantity' => $package->initial_quantity,
                    'unit_type' => $package->unit_type,
                    'expires_at' => $package->expires_at?->toDateString(),
                    'is_recurring' => (bool) $package->is_recurring,
                    'recurrence_status' => $package->recurrence_status,
                ],
            ]);
            DB::table($behavior->getTable())->where('id', $behavior->id)->update([
                'created_at' => $event['at']->utc(),
                'updated_at' => $event['at']->utc(),
            ]);
        }

        return count($events);
    }

    private function normalizeLoyaltyHistory(DemoScenarioContext $context): void
    {
        $program = LoyaltyProgram::query()
            ->where('user_id', $context->owner->id)
            ->first();

        if ($program?->is_enabled) {
            Payment::query()
                ->where('user_id', $context->owner->id)
                ->whereIn('status', Payment::settledStatuses())
                ->whereNotNull('customer_id')
                ->chunkById(250, function (Collection $payments) use ($context, $program): void {
                    $payments->each(
                        fn (Payment $payment): mixed => $this->reconcilePaymentLoyaltyAccrual(
                            $context,
                            $payment,
                            $program,
                        ),
                    );
                });
        }

        LoyaltyPointLedger::query()
            ->where('user_id', $context->owner->id)
            ->whereNotNull('payment_id')
            ->with('payment')
            ->get()
            ->each(function (LoyaltyPointLedger $ledger): void {
                $payment = $ledger->payment;
                if (! $payment) {
                    return;
                }

                $processedAt = CarbonImmutable::instance($payment->paid_at ?: $payment->created_at)
                    ->addMinutes($ledger->event === LoyaltyPointLedger::EVENT_REFUND ? 2 : 1)
                    ->utc();
                DB::table($ledger->getTable())->where('id', $ledger->id)->update([
                    'processed_at' => $processedAt,
                    'created_at' => $processedAt,
                    'updated_at' => $processedAt,
                ]);
            });
    }

    /**
     * @param  Collection<string, Customer>  $storyCustomers
     */
    private function createLoyaltyStoryEvents(
        DemoScenarioContext $context,
        Collection $storyCustomers,
        int $target,
    ): int {
        if ($target !== 3) {
            throw new RuntimeException('Studio Naya loyalty narrative requires exactly three story events.');
        }

        $aicha = $storyCustomers->get('aicha_martin')?->fresh();
        $marc = $storyCustomers->get('marc_andre_beaulieu')?->fresh();
        if (! $aicha || ! $marc) {
            throw new RuntimeException('Studio Naya loyalty narrative requires Aïcha and Marc-André.');
        }

        $aichaSale = Sale::query()
            ->where('user_id', $context->owner->id)
            ->where('customer_id', $aicha->id)
            ->where('status', Sale::STATUS_PAID)
            ->with('payments.loyaltyPointLedgers')
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->firstOrFail();
        $aichaOriginalDiscount = round((float) $aichaSale->discount_total, 2);
        $aichaOriginalTotal = round((float) $aichaSale->total, 2);
        $aichaRedemption = $this->loyaltyPointService->redeemForSale($aichaSale, 25, $aichaOriginalTotal);
        $aichaRedeemAmount = round((float) $aichaRedemption['amount'], 2);
        $aichaSale->forceFill([
            'loyalty_points_redeemed' => (int) $aichaRedemption['points'],
            'loyalty_discount_total' => $aichaRedeemAmount,
            'discount_total' => round($aichaOriginalDiscount + $aichaRedeemAmount, 2),
            'total' => round($aichaOriginalTotal - $aichaRedeemAmount, 2),
        ])->saveQuietly();
        $aichaPayment = $aichaSale->payments
            ->whereIn('status', Payment::settledStatuses())
            ->first();
        if (! $aichaPayment) {
            throw new RuntimeException('Aïcha loyalty redemption needs a settled sale payment.');
        }
        $aichaPayment->forceFill([
            'amount' => (float) $aichaSale->total,
            'charged_total' => $aichaPayment->charged_total === null
                ? null
                : (float) $aichaSale->total,
        ])->saveQuietly();
        $this->reconcilePaymentLoyaltyAccrual($context, $aichaPayment->fresh());

        $aichaLedger = LoyaltyPointLedger::query()
            ->where('user_id', $context->owner->id)
            ->where('customer_id', $aicha->id)
            ->whereNull('payment_id')
            ->where('event', LoyaltyPointLedger::EVENT_REDEMPTION)
            ->latest('id')
            ->firstOrFail();
        $aichaEventAt = CarbonImmutable::instance($aichaSale->created_at)->addMinute()->utc();
        $aichaLedger->forceFill([
            'meta' => array_merge((array) $aichaLedger->meta, [
                'scenario_key' => 'studio_naya_coiffure',
                'narrative' => 'rituel_fidelite_vip_applique_a_la_vente',
            ]),
            'processed_at' => $aichaEventAt,
        ])->saveQuietly();
        DB::table($aichaLedger->getTable())->where('id', $aichaLedger->id)->update([
            'created_at' => $aichaEventAt,
            'updated_at' => $aichaEventAt,
        ]);

        $marcSale = Sale::query()
            ->where('user_id', $context->owner->id)
            ->where('customer_id', $marc->id)
            ->where('status', Sale::STATUS_PAID)
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->firstOrFail();
        $marcOriginalDiscount = round((float) $marcSale->discount_total, 2);
        $marcOriginalTotal = round((float) $marcSale->total, 2);
        $marcRedemption = $this->loyaltyPointService->redeemForSale($marcSale, 25, $marcOriginalTotal);
        $marcSale->forceFill([
            'loyalty_points_redeemed' => (int) $marcRedemption['points'],
            'loyalty_discount_total' => (float) $marcRedemption['amount'],
            'discount_total' => round($marcOriginalDiscount + (float) $marcRedemption['amount'], 2),
            'total' => round($marcOriginalTotal - (float) $marcRedemption['amount'], 2),
        ])->saveQuietly();
        $this->loyaltyPointService->releaseSaleRedemption($marcSale->fresh(), 'checkout_redemption_replaced_before_payment');
        $marcSale->fresh()->forceFill([
            'discount_total' => $marcOriginalDiscount,
            'total' => $marcOriginalTotal,
        ])->saveQuietly();

        $marcEvents = LoyaltyPointLedger::query()
            ->where('user_id', $context->owner->id)
            ->where('customer_id', $marc->id)
            ->whereNull('payment_id')
            ->whereIn('event', [
                LoyaltyPointLedger::EVENT_REDEMPTION,
                LoyaltyPointLedger::EVENT_REDEMPTION_REVERSAL,
            ])
            ->orderBy('id')
            ->get();
        if ($marcEvents->count() !== 2) {
            throw new RuntimeException('Marc-André loyalty reversal narrative must contain a redemption pair.');
        }
        foreach ($marcEvents as $index => $ledger) {
            $eventAt = CarbonImmutable::instance($marcSale->created_at)->addMinutes($index + 1)->utc();
            $ledger->forceFill([
                'meta' => array_merge((array) $ledger->meta, [
                    'scenario_key' => 'studio_naya_coiffure',
                    'narrative' => $ledger->event === LoyaltyPointLedger::EVENT_REDEMPTION
                        ? 'geste_fidelite_selectionne'
                        : 'geste_fidelite_retire_avant_paiement',
                ]),
                'processed_at' => $eventAt,
            ])->saveQuietly();
            DB::table($ledger->getTable())->where('id', $ledger->id)->update([
                'created_at' => $eventAt,
                'updated_at' => $eventAt,
            ]);
        }

        return 1 + $marcEvents->count();
    }

    private function linkCarryOverRenewal(
        DemoScenarioContext $context,
        CustomerPackage $parent,
        CustomerPackage $child,
    ): void {
        $child->loadMissing(['invoice', 'invoiceItem']);
        if (! $child->invoice || ! $child->invoiceItem || $child->invoice->status !== 'paid') {
            throw new RuntimeException('Studio Naya carry-over renewal must be provisioned by a paid invoice line.');
        }
        if ((int) $parent->customer_id !== (int) $child->customer_id
            || (int) $parent->offer_package_id !== (int) $child->offer_package_id) {
            throw new RuntimeException('Studio Naya carry-over renewal must preserve customer and offer identity.');
        }

        $periodAllocation = 2;
        $carriedOver = (int) $parent->remaining_quantity;
        $initialQuantity = $periodAllocation + $carriedOver;
        $startsOn = ($child->current_period_starts_at ?: $child->starts_at)?->toDateString();
        if (! $startsOn) {
            throw new RuntimeException('Studio Naya carry-over renewal requires a civil period start date.');
        }
        $startsAt = CarbonImmutable::parse($startsOn, $context->timezone)->startOfDay();
        $periodEndsAt = $startsAt->addMonthNoOverflow()->subDay()->endOfDay();
        $childMetadata = (array) $child->metadata;
        $childMetadata['renewed_from_customer_package_id'] = $parent->id;
        $childMetadata['recurrence'] = array_merge((array) ($childMetadata['recurrence'] ?? []), [
            'period_allocation_quantity' => $periodAllocation,
            'subscription_quantity' => 1,
            'carry_over_unused_balance' => true,
            'carried_over_quantity' => $carriedOver,
            'renewed_from_remaining_quantity' => $carriedOver,
            'payment_grace_days' => 7,
            'payment_reminder_days' => [0, 3, 6],
        ]);
        $sourceDetails = (array) $child->source_details;
        $sourceDetails['assignment'] = [
            'source' => 'recurring_renewal',
            'assigned_by_user_id' => $context->owner->id,
            'renewed_from_customer_package_id' => $parent->id,
            'invoice_id' => $child->invoice_id,
            'invoice_item_id' => $child->invoice_item_id,
        ];
        $sourceDetails['recurrence'] = [
            'source' => 'paid_renewal_invoice',
            'frequency' => OfferPackage::RECURRENCE_MONTHLY,
            'renewed_from_customer_package_id' => $parent->id,
            'current_period_starts_at' => $startsAt->toDateString(),
            'current_period_ends_at' => $periodEndsAt->toDateString(),
            'next_renewal_at' => $periodEndsAt->addDay()->toDateString(),
            'period_allocation_quantity' => $periodAllocation,
            'subscription_quantity' => 1,
            'carry_over_unused_balance' => true,
            'carried_over_quantity' => $carriedOver,
        ];

        $child->forceFill([
            'starts_at' => $startsAt->toDateString(),
            'expires_at' => $periodEndsAt->toDateString(),
            'initial_quantity' => $initialQuantity,
            'remaining_quantity' => $initialQuantity - (int) $child->consumed_quantity,
            'current_period_starts_at' => $startsAt->toDateString(),
            'current_period_ends_at' => $periodEndsAt->toDateString(),
            'next_renewal_at' => $periodEndsAt->addDay()->toDateString(),
            'renewal_count' => 1,
            'renewed_from_customer_package_id' => $parent->id,
            'source_details' => $sourceDetails,
            'metadata' => $childMetadata,
        ])->saveQuietly();

        $lineMeta = (array) $child->invoiceItem->meta;
        $lineMeta = array_merge($lineMeta, [
            'source' => 'customer_package_renewal',
            'added_from' => 'recurring_renewal_invoice',
            'customer_package_id' => $parent->id,
            'renewal_for_customer_package_id' => $parent->id,
            'recurrence_frequency' => OfferPackage::RECURRENCE_MONTHLY,
            'subscription_quantity' => 1,
            'carry_over_unused_balance' => true,
        ]);
        $child->invoiceItem->forceFill(['meta' => $lineMeta])->saveQuietly();

        $parentMetadata = (array) $parent->metadata;
        $parentMetadata['recurrence'] = array_merge((array) ($parentMetadata['recurrence'] ?? []), [
            'renewed_at' => $startsAt->toIso8601String(),
            'renewed_to_customer_package_id' => $child->id,
            'renewed_by_user_id' => $context->owner->id,
            'carry_over_unused_balance' => true,
            'carried_over_quantity' => $carriedOver,
            'pending_invoice_id' => $child->invoice_id,
            'pending_invoice_item_id' => $child->invoice_item_id,
            'pending_invoice_created_at' => $child->invoice->created_at?->utc()->toIso8601String(),
            'pending_invoice_status' => 'paid',
            'pending_invoice_total' => (float) $child->invoice->total,
            'paid_invoice_id' => $child->invoice_id,
            'paid_invoice_item_id' => $child->invoice_item_id,
            'paid_renewed_to_customer_package_id' => $child->id,
        ]);
        $parent->forceFill([
            'next_renewal_at' => $startsAt->toDateString(),
            'metadata' => $parentMetadata,
        ])->saveQuietly();

        CustomerBehaviorEvent::query()
            ->where('user_id', $context->owner->id)
            ->where('customer_id', $child->customer_id)
            ->get()
            ->filter(fn (CustomerBehaviorEvent $event): bool => (int) data_get(
                $event->metadata,
                'customer_package_id',
                0,
            ) === (int) $child->id)
            ->each(function (CustomerBehaviorEvent $event) use ($child, $initialQuantity): void {
                $metadata = (array) $event->metadata;
                $metadata['initial_quantity'] = $initialQuantity;
                $metadata['remaining_quantity'] = $initialQuantity - (int) $child->consumed_quantity;
                $event->forceFill(['metadata' => $metadata])->saveQuietly();
            });

        DB::table($child->getTable())->where('id', $child->id)->update([
            'updated_at' => $child->current_period_starts_at?->utc(),
        ]);
        DB::table($parent->getTable())->where('id', $parent->id)->update([
            'updated_at' => $startsAt->utc(),
        ]);
    }

    private function recurrenceStatusForCycle(int $cycle): string
    {
        return match ($cycle % 3) {
            1 => CustomerPackage::RECURRENCE_PAYMENT_DUE,
            2 => CustomerPackage::RECURRENCE_SUSPENDED,
            default => CustomerPackage::RECURRENCE_ACTIVE,
        };
    }

    /**
     * @param  array<string, mixed>  $profile
     * @param  array<int, true>  $usedInvoiceIds
     */
    private function paidInvoiceForPackage(
        DemoScenarioContext $context,
        Customer $customer,
        array $profile,
        array $usedInvoiceIds,
    ): ?Invoice {
        $candidates = Invoice::query()
            ->byUser((int) $context->owner->id)
            ->where('customer_id', $customer->id)
            ->where('status', 'paid')
            ->with(['items', 'payments.loyaltyPointLedgers'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->filter(function (Invoice $invoice) use ($profile, $usedInvoiceIds): bool {
                if (isset($usedInvoiceIds[(int) $invoice->id])
                    || $this->invoiceContainsOfferLine($invoice)
                    || ! $this->canExtendPaidInvoice($invoice)) {
                    return false;
                }

                $settledAt = $this->settledAt($invoice);

                return $settledAt->betweenIncluded(
                    $profile['starts_at']->utc(),
                    $profile['usage_window_end']->utc(),
                );
            });

        return $candidates
            ->sortBy(function (Invoice $invoice) use ($profile): string {
                $distance = abs($this->settledAt($invoice)->diffInSeconds($profile['assigned_at']->utc(), false));

                return sprintf('%020d-%010d', $distance, (int) $invoice->id);
            })
            ->first();
    }

    private function invoiceContainsOfferLine(Invoice $invoice): bool
    {
        $invoice->loadMissing('items');

        return $invoice->items->contains(
            fn (InvoiceItem $item): bool => data_get($item->meta, 'offer_package_type') !== null,
        );
    }

    private function canExtendPaidInvoice(Invoice $invoice): bool
    {
        $invoice->loadMissing('payments');
        $settled = $invoice->payments->whereIn('status', Payment::settledStatuses());

        return $settled->isNotEmpty()
            && $settled->count() === $invoice->payments->count()
            && abs((float) $settled->sum('amount') - (float) $invoice->total) <= 0.009;
    }

    private function settledAt(Invoice $invoice): CarbonImmutable
    {
        $invoice->loadMissing('payments');
        $payment = $invoice->payments
            ->whereIn('status', Payment::settledStatuses())
            ->sortBy(fn (Payment $candidate): string => sprintf(
                '%s-%010d',
                $candidate->paid_at?->utc()->format('Y-m-d H:i:s.u') ?? '0000-00-00 00:00:00.000000',
                (int) $candidate->id,
            ))
            ->last();

        if (! $payment) {
            throw new RuntimeException('A paid Studio Naya invoice is missing its settled payment.');
        }

        return CarbonImmutable::instance($payment->paid_at ?: $payment->created_at)->utc();
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function appendOfferLineToInvoice(
        DemoScenarioContext $context,
        Invoice $invoice,
        OfferPackage $offer,
        CarbonImmutable $lineAt,
        array $meta,
        bool $settleAddedBalance,
    ): InvoiceItem {
        $originalTotal = round((float) $invoice->total, 2);
        $attributes = $this->salesLineBuilder->invoiceItemAttributes($offer, 1);
        $sourceDetails = (array) data_get($attributes, 'meta.source_details', []);
        $sourceDetails['snapshot_at'] = $lineAt->toIso8601String();
        $attributes['meta'] = array_replace((array) $attributes['meta'], [
            'scenario_key' => 'studio_naya_coiffure',
            'demo_generated' => true,
            'source_details' => $sourceDetails,
        ], $meta);

        $line = InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            ...$attributes,
        ]);
        DB::table($line->getTable())->where('id', $line->id)->update([
            'created_at' => $lineAt,
            'updated_at' => $lineAt,
        ]);

        $subtotal = round((float) InvoiceItem::query()
            ->where('invoice_id', $invoice->id)
            ->sum('total'), 2);
        $taxTotal = round($subtotal * 0.14975, 2);
        $total = round($subtotal + $taxTotal, 2);
        $billingSnapshot = (array) ($invoice->billing_snapshot ?? []);
        $billingSnapshot['offer_package_sales'] = [
            ...(array) ($billingSnapshot['offer_package_sales'] ?? []),
            [
                'offer_package_key' => $offer->slug,
                'offer_package_name' => $offer->name,
                'offer_package_type' => $offer->type,
                'price' => (float) $offer->price,
                'sold_at' => $lineAt->toIso8601String(),
                'payment_state' => $settleAddedBalance ? 'paid' : 'pending',
            ],
        ];
        $approvalMeta = (array) ($invoice->approval_meta ?? []);
        data_set($approvalMeta, 'approval_policy_snapshot.amount', $total);

        $invoice->forceFill([
            'status' => $invoice->status === 'draft' ? 'sent' : $invoice->status,
            'subtotal' => $subtotal,
            'tax_total' => $taxTotal,
            'total' => $total,
            'billing_snapshot' => $billingSnapshot,
            'approval_meta' => $approvalMeta,
        ])->save();

        $updatedAt = $lineAt;
        if ($settleAddedBalance) {
            $invoice->loadMissing('payments');
            $payment = $invoice->payments
                ->whereIn('status', Payment::settledStatuses())
                ->sortBy('paid_at')
                ->last();
            if (! $payment) {
                throw new RuntimeException('A paid Studio Naya offer line cannot be added without a settled payment.');
            }

            $delta = round($total - $originalTotal, 2);
            $payment->forceFill([
                'amount' => round((float) $payment->amount + $delta, 2),
                'tip_base_amount' => $payment->tip_base_amount,
                'charged_total' => $payment->charged_total === null
                    ? null
                    : round((float) $payment->charged_total + $delta, 2),
            ])->saveQuietly();
            $updatedAt = CarbonImmutable::instance($payment->paid_at ?: $payment->created_at)->utc();
            DB::table($payment->getTable())->where('id', $payment->id)->update([
                'updated_at' => $updatedAt,
            ]);
            $this->reconcilePaymentLoyaltyAccrual($context, $payment->fresh());
        }

        DB::table($invoice->getTable())->where('id', $invoice->id)->update([
            'updated_at' => $updatedAt,
        ]);
        $invoice->unsetRelation('items');
        $invoice->unsetRelation('payments');

        return $line->fresh();
    }

    private function reconcilePaymentLoyaltyAccrual(
        DemoScenarioContext $context,
        Payment $payment,
        ?LoyaltyProgram $program = null,
    ): void {
        $program ??= LoyaltyProgram::query()
            ->where('user_id', $context->owner->id)
            ->first();
        if (! $program || ! $program->is_enabled || ! $payment->customer_id) {
            return;
        }

        $amount = round(max(0, (float) $payment->amount), 2);
        $rawPoints = $amount >= (float) $program->minimum_spend
            ? $amount * max(0, (float) $program->points_per_currency_unit)
            : 0;
        $expectedPoints = match ((string) $program->rounding_mode) {
            LoyaltyProgram::ROUND_CEIL => (int) ceil($rawPoints),
            LoyaltyProgram::ROUND_ROUND => (int) round($rawPoints),
            default => (int) floor($rawPoints),
        };
        $ledger = LoyaltyPointLedger::query()
            ->where('payment_id', $payment->id)
            ->where('event', LoyaltyPointLedger::EVENT_ACCRUAL)
            ->first();
        $previousPoints = (int) ($ledger?->points ?? 0);
        $processedAt = CarbonImmutable::instance($payment->paid_at ?: $payment->created_at)
            ->addMinute()
            ->utc();

        if ($expectedPoints > 0) {
            $expectedMeta = ['payment_status' => $payment->status];
            $ledger ??= LoyaltyPointLedger::query()->make([
                'user_id' => $context->owner->id,
                'customer_id' => $payment->customer_id,
                'payment_id' => $payment->id,
                'event' => LoyaltyPointLedger::EVENT_ACCRUAL,
            ]);
            $needsReconciliation = ! $ledger->exists
                || (int) $ledger->user_id !== (int) $context->owner->id
                || (int) $ledger->customer_id !== (int) $payment->customer_id
                || (int) $ledger->points !== $expectedPoints
                || abs((float) $ledger->amount - $amount) > 0.009
                || (array) $ledger->meta !== $expectedMeta;

            if ($needsReconciliation) {
                $ledger->forceFill([
                    'user_id' => $context->owner->id,
                    'customer_id' => $payment->customer_id,
                    'points' => $expectedPoints,
                    'amount' => $amount,
                    'meta' => $expectedMeta,
                    'processed_at' => $processedAt,
                ])->saveQuietly();
                DB::table($ledger->getTable())->where('id', $ledger->id)->update([
                    'created_at' => $processedAt,
                    'updated_at' => $processedAt,
                ]);
            }
        } elseif ($ledger) {
            $ledger->delete();
        }

        $delta = $expectedPoints - $previousPoints;
        if ($delta !== 0) {
            $customer = Customer::query()->find($payment->customer_id);
            $customer?->forceFill([
                'loyalty_points_balance' => max(0, (int) $customer->loyalty_points_balance + $delta),
            ])->saveQuietly();
        }
    }

    /**
     * @param  Collection<int, mixed>  $records
     * @return Collection<int, mixed>
     */
    private function evenlyDistributed(Collection $records, int $target): Collection
    {
        if ($target >= $records->count()) {
            return $records->values();
        }

        return collect(range(0, $target - 1))
            ->map(function (int $index) use ($records, $target): mixed {
                $position = (int) floor((($index + 0.5) * $records->count()) / $target);

                return $records[min($records->count() - 1, $position)];
            })
            ->values();
    }

    private function distributedInstant(
        CarbonImmutable $start,
        CarbonImmutable $end,
        int $index,
        int $count,
    ): CarbonImmutable {
        if ($count <= 1 || $start->gte($end)) {
            return $end;
        }

        $seconds = max(0, $start->diffInSeconds($end));
        $offset = (int) floor(($seconds * $index) / ($count - 1));

        return $start->addSeconds($offset);
    }

    /**
     * @param  array<string, int>  $targets
     * @param  array<string, int>  $counts
     */
    private function validate(DemoScenarioContext $context, array $targets, array $counts): void
    {
        foreach ([
            'offer_packages',
            'offer_package_items',
            'pack_invoice_lines',
            'customer_packages',
            'customer_package_usages',
            'package_behavior_events',
            'loyalty_story_events',
        ] as $key) {
            if ((int) ($counts[$key] ?? -1) !== (int) ($targets[$key] ?? -2)) {
                throw new RuntimeException(sprintf(
                    'Studio Naya immersive target mismatch for %s: expected %d, generated %d.',
                    $key,
                    (int) ($targets[$key] ?? -2),
                    (int) ($counts[$key] ?? -1),
                ));
            }
        }

        $ownerId = (int) $context->owner->id;
        $invalidOffers = OfferPackage::query()
            ->forAccount($ownerId)
            ->where(function ($query): void {
                $query->where('status', '!=', OfferPackage::STATUS_ACTIVE)
                    ->orWhere('is_public', false)
                    ->orWhereDoesntHave('items');
            })
            ->count();
        $invalidItems = OfferPackageItem::query()
            ->whereHas('offerPackage', fn ($query) => $query->where('user_id', $ownerId))
            ->whereHas('product', fn ($query) => $query->where('user_id', '!=', $ownerId))
            ->count();
        $invalidPackages = CustomerPackage::query()
            ->forAccount($ownerId)
            ->with([
                'customer:id,user_id',
                'offerPackage:id,user_id',
                'invoice:id,user_id,customer_id,status',
                'invoiceItem:id,invoice_id,total,meta',
                'renewedFrom:id,user_id,customer_id,offer_package_id,remaining_quantity',
            ])
            ->withSum(['usages as active_usage_quantity' => fn ($query) => $query->active()], 'quantity')
            ->get()
            ->filter(function (CustomerPackage $package) use ($ownerId): bool {
                $pricePaid = round((float) $package->price_paid, 2);
                $hasValidPaidSource = $pricePaid <= 0
                    ? $package->invoice_id === null && $package->invoice_item_id === null
                    : $package->invoice?->status === 'paid'
                        && (int) $package->invoice?->user_id === $ownerId
                        && (int) $package->invoice?->customer_id === (int) $package->customer_id
                        && (int) $package->invoiceItem?->invoice_id === (int) $package->invoice_id
                        && data_get($package->invoiceItem?->meta, 'offer_package_type') === OfferPackage::TYPE_FORFAIT
                        && abs((float) $package->invoiceItem?->total - $pricePaid) <= 0.009;
                $periodAllocation = (int) data_get(
                    $package->metadata,
                    'recurrence.period_allocation_quantity',
                    $package->initial_quantity,
                );
                $carriedOver = (int) data_get($package->metadata, 'recurrence.carried_over_quantity', 0);
                $renewedToId = (int) data_get($package->metadata, 'recurrence.renewed_to_customer_package_id', 0);
                $hasValidRenewalChain = ! $package->renewed_from_customer_package_id
                    ? ($renewedToId > 0 || ($carriedOver === 0 && $periodAllocation === (int) $package->initial_quantity))
                    : $package->renewedFrom
                        && (int) $package->renewal_count > 0
                        && (int) $package->initial_quantity === $periodAllocation + $carriedOver
                        && $carriedOver === (int) $package->renewedFrom->remaining_quantity
                        && (int) $package->customer_id === (int) $package->renewedFrom->customer_id
                        && (int) $package->offer_package_id === (int) $package->renewedFrom->offer_package_id;

                return (int) $package->consumed_quantity !== (int) ($package->active_usage_quantity ?? 0)
                    || (int) $package->remaining_quantity
                        !== (int) $package->initial_quantity - (int) $package->consumed_quantity
                    || (int) $package->customer?->user_id !== $ownerId
                    || (int) $package->offerPackage?->user_id !== $ownerId
                    || ! $hasValidPaidSource
                    || ($package->is_recurring && ! $hasValidRenewalChain);
            })
            ->count();
        $invalidUsages = CustomerPackageUsage::query()
            ->forAccount($ownerId)
            ->where(function ($query) use ($ownerId): void {
                $query->whereHas('customer', fn ($customerQuery) => $customerQuery->where('user_id', '!=', $ownerId))
                    ->orWhereHas('customerPackage', fn ($packageQuery) => $packageQuery->where('user_id', '!=', $ownerId))
                    ->orWhereHas('product', fn ($productQuery) => $productQuery->where('user_id', '!=', $ownerId));
            })
            ->count();

        $billedReservationIds = InvoiceItem::query()
            ->whereHas('invoice', fn ($query) => $query->where('user_id', $ownerId))
            ->get(['meta'])
            ->map(fn (InvoiceItem $item): int => (int) data_get($item->meta, 'reservation_id', 0))
            ->filter()
            ->all();
        $doubleBilledUsages = $billedReservationIds === []
            ? 0
            : CustomerPackageUsage::query()
                ->forAccount($ownerId)
                ->whereIn('reservation_id', $billedReservationIds)
                ->count();

        $invalidPendingRenewals = CustomerPackage::query()
            ->forAccount($ownerId)
            ->whereIn('recurrence_status', [
                CustomerPackage::RECURRENCE_PAYMENT_DUE,
                CustomerPackage::RECURRENCE_SUSPENDED,
            ])
            ->get()
            ->filter(function (CustomerPackage $package) use ($ownerId): bool {
                $invoiceId = (int) data_get($package->metadata, 'recurrence.pending_invoice_id', 0);
                $itemId = (int) data_get($package->metadata, 'recurrence.pending_invoice_item_id', 0);
                $invoice = Invoice::query()
                    ->with('items')
                    ->whereKey($invoiceId)
                    ->where('user_id', $ownerId)
                    ->where('customer_id', $package->customer_id)
                    ->first();
                $item = $invoice?->items->firstWhere('id', $itemId);

                return ! $invoice
                    || ! in_array($invoice->status, ['sent', 'overdue'], true)
                    || ($package->recurrence_status === CustomerPackage::RECURRENCE_SUSPENDED
                        && $invoice->status !== 'overdue')
                    || ! $item
                    || (int) data_get($item->meta, 'renewal_for_customer_package_id', 0) !== (int) $package->id;
            })
            ->count();

        $storyLoyalty = LoyaltyPointLedger::query()
            ->where('user_id', $ownerId)
            ->whereNull('payment_id')
            ->get();
        $invalidLoyaltyGroups = $storyLoyalty
            ->groupBy(fn (LoyaltyPointLedger $ledger): int => (int) data_get($ledger->meta, 'sale_id', 0))
            ->filter(function (Collection $events, int $saleId) use ($ownerId): bool {
                $sale = Sale::query()
                    ->whereKey($saleId)
                    ->where('user_id', $ownerId)
                    ->first();
                if (! $sale || $events->contains(
                    fn (LoyaltyPointLedger $ledger): bool => (int) $ledger->customer_id !== (int) $sale->customer_id,
                )) {
                    return true;
                }

                if ($events->count() === 1) {
                    $redemption = $events->first();

                    return $redemption->event !== LoyaltyPointLedger::EVENT_REDEMPTION
                        || (int) $sale->loyalty_points_redeemed !== abs((int) $redemption->points)
                        || abs((float) $sale->loyalty_discount_total - (float) $redemption->amount) > 0.009;
                }

                return $events->count() !== 2
                    || $events->pluck('event')->sort()->values()->all() !== [
                        LoyaltyPointLedger::EVENT_REDEMPTION,
                        LoyaltyPointLedger::EVENT_REDEMPTION_REVERSAL,
                    ]
                    || (int) $events->sum('points') !== 0
                    || (int) $sale->loyalty_points_redeemed !== 0
                    || (float) $sale->loyalty_discount_total !== 0.0;
            })
            ->count();

        if ($invalidOffers
            + $invalidItems
            + $invalidPackages
            + $invalidUsages
            + $doubleBilledUsages
            + $invalidPendingRenewals
            + $invalidLoyaltyGroups > 0) {
            throw new RuntimeException(sprintf(
                'Studio Naya immersive data failed validation (%d/%d/%d/%d/%d/%d/%d).',
                $invalidOffers,
                $invalidItems,
                $invalidPackages,
                $invalidUsages,
                $doubleBilledUsages,
                $invalidPendingRenewals,
                $invalidLoyaltyGroups,
            ));
        }

        $statusCounts = CustomerPackage::query()
            ->forAccount($ownerId)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');
        $quarter = intdiv((int) $targets['customer_packages'], 4);
        if ((int) $statusCounts->get(CustomerPackage::STATUS_ACTIVE, 0) !== $quarter * 2
            || (int) $statusCounts->get(CustomerPackage::STATUS_CONSUMED, 0) !== $quarter
            || (int) $statusCounts->get(CustomerPackage::STATUS_EXPIRED, 0) !== $quarter) {
            throw new RuntimeException('Studio Naya forfait lifecycle statuses are not balanced.');
        }

        $renewalChains = CustomerPackage::query()
            ->forAccount($ownerId)
            ->whereNotNull('renewed_from_customer_package_id')
            ->count();
        if ($renewalChains !== 1) {
            throw new RuntimeException('Studio Naya must expose exactly one coherent carry-over renewal chain.');
        }

        $packStatuses = InvoiceItem::query()
            ->whereHas('invoice', fn ($query) => $query->where('user_id', $ownerId))
            ->with('invoice')
            ->get()
            ->filter(fn (InvoiceItem $item): bool => data_get($item->meta, 'offer_package_type') === OfferPackage::TYPE_PACK)
            ->pluck('invoice.status')
            ->countBy();
        if ((int) $packStatuses->get('paid', 0) < 1
            || (int) $packStatuses->get('sent', 0) < 1
            || (int) $packStatuses->get('overdue', 0) < 1) {
            throw new RuntimeException('Studio Naya pack sales must mix paid, sent and overdue invoices.');
        }
    }
}
