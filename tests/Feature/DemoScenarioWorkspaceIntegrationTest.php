<?php

use App\Enums\DemoDataVolume;
use App\Jobs\ProvisionDemoWorkspaceJob;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerBehaviorEvent;
use App\Models\CustomerPackage;
use App\Models\CustomerPackageUsage;
use App\Models\DemoWorkspace;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\LoyaltyPointLedger;
use App\Models\LoyaltyProgram;
use App\Models\OfferPackage;
use App\Models\OfferPackageItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Reservation;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Task;
use App\Models\TeamMember;
use App\Models\Transaction;
use App\Models\User;
use App\Queries\Demo\DemoScenarioDashboardQuery;
use App\Services\Demo\Contracts\DemoScenario;
use App\Services\Demo\DemoScenarioContext;
use App\Services\Demo\DemoScenarioModuleEvidence;
use App\Services\Demo\DemoScenarioRegistry;
use App\Services\Demo\DemoWorkspaceCatalog;
use App\Services\Demo\DemoWorkspaceProvisioner;
use App\Services\Demo\DemoWorkspaceTimelineService;
use App\Services\Demo\Scenarios\StudioNaya\StudioNayaBlueprint;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

function demoScenarioIntegrationAdmin(): User
{
    $role = Role::query()->firstOrCreate(
        ['name' => 'superadmin'],
        ['description' => 'Superadmin role']
    );

    return User::query()->create([
        'name' => 'Scenario Admin',
        'email' => 'scenario-admin@example.test',
        'password' => 'password',
        'role_id' => $role->id,
        'onboarding_completed_at' => now(),
    ]);
}

/**
 * @return array<string, mixed>
 */
function demoScenarioIntegrationPayload(array $overrides = []): array
{
    /** @var DemoWorkspaceCatalog $catalog */
    $catalog = app(DemoWorkspaceCatalog::class);
    $preset = collect($catalog->presets())->firstWhere('key', 'studio_naya_coiffure');

    return array_replace($catalog->defaults(), [
        'prospect_name' => $preset['prospect_name'],
        'prospect_email' => null,
        'prospect_company' => $preset['prospect_company'],
        'company_name' => $preset['company_name'],
        'company_type' => $preset['company_type'],
        'company_sector' => $preset['company_sector'],
        'seed_profile' => $preset['seed_profile'],
        'scenario_key' => $preset['scenario_key'],
        'data_volume' => 'small',
        'reference_date' => '2026-08-20',
        'random_seed' => 12345,
        'scenario_version' => 1,
        'team_size' => $preset['team_size'],
        'locale' => $preset['locale'],
        'timezone' => $preset['timezone'],
        'desired_outcome' => $preset['desired_outcome'],
        'selected_modules' => $preset['modules'],
        'scenario_packs' => $preset['scenario_packs'],
        'branding_profile' => $preset['branding_profile'],
        'extra_access_roles' => $preset['extra_access_roles'],
        'suggested_flow' => $preset['suggested_flow'],
        'expires_at' => '2026-09-03',
    ], $overrides);
}

function bindScenarioIntegrationFake(): DemoScenario
{
    $scenario = new class implements DemoScenario
    {
        public ?DemoScenarioContext $lastContext = null;

        public function key(): string
        {
            return 'studio_naya_coiffure';
        }

        public function version(): int
        {
            return 1;
        }

        public function defaultVolume(): DemoDataVolume
        {
            return DemoDataVolume::Medium;
        }

        public function generate(DemoScenarioContext $context): array
        {
            $this->lastContext = $context;

            return [
                'generator' => 'scenario_engine',
                'semantic_fingerprint' => hash('sha256', implode('|', [
                    $context->dataVolume->value,
                    $context->referenceDate->toDateString(),
                    $context->randomSeed,
                ])),
                'customers' => 300,
                'reservations' => 1800,
            ];
        }
    };

    app()->singleton(
        DemoScenarioRegistry::class,
        fn (): DemoScenarioRegistry => new DemoScenarioRegistry([$scenario])
    );

    return $scenario;
}

it('keeps MySQL demo timestamps in UTC across the spring DST gap', function () {
    $serializedUtc = '2025-03-09 02:40:00';
    $storedInstant = CarbonImmutable::createFromFormat(
        'Y-m-d H:i:s',
        $serializedUtc,
        (string) config('database.connections.mysql.timezone'),
    );
    $torontoInstant = $storedInstant->setTimezone('America/Toronto');

    expect(config('database.connections.mysql.timezone'))->toBe('+00:00')
        ->and(config('database.connections.mariadb.timezone'))->toBe('+00:00')
        ->and($storedInstant->format('P'))->toBe('+00:00')
        ->and($torontoInstant->format('Y-m-d H:i:s P'))->toBe('2025-03-08 21:40:00 -05:00')
        ->and($torontoInstant->utc()->format('Y-m-d H:i:s'))->toBe($serializedUtc);
});

it('exposes Studio Naya as an opt-in narrative preset without changing lean salon defaults', function () {
    $catalog = app(DemoWorkspaceCatalog::class);
    $preset = collect($catalog->presets())->firstWhere('key', 'studio_naya_coiffure');
    $requiredModules = $catalog->requiredModulesForScenario('studio_naya_coiffure');

    expect($catalog->defaults()['scenario_key'])->toBeNull()
        ->and($catalog->defaultModules('services', 'salon'))->not->toContain('quotes', 'tasks', 'products')
        ->and($catalog->scenarioKeys())->toContain('studio_naya_coiffure')
        ->and($catalog->dataVolumeKeys())->toBe(['small', 'medium', 'large'])
        ->and($preset)->toBeArray()
        ->and($preset['scenario_key'])->toBe('studio_naya_coiffure')
        ->and($preset['data_volume'])->toBe('medium')
        ->and($preset['team_size'])->toBe(5)
        ->and($preset['modules'])->toContain('quotes', 'products', 'reservations')
        ->and($requiredModules)->not->toBeEmpty()
        ->and(array_diff($requiredModules, $preset['modules']))->toBe([])
        ->and($catalog->scenarioDefinition('studio_naya_coiffure')['required_modules'])->toBe($requiredModules);
});

it('refuses to provision Studio Naya when a required module is missing', function () {
    bindScenarioIntegrationFake();
    $admin = demoScenarioIntegrationAdmin();
    $payload = demoScenarioIntegrationPayload();
    $payload['selected_modules'] = array_values(array_diff($payload['selected_modules'], ['services']));

    expect(fn () => app(DemoWorkspaceProvisioner::class)->create($payload, $admin))
        ->toThrow(
            InvalidArgumentException::class,
            'Demo scenario [studio_naya_coiffure] requires these modules: services.',
        );

    expect(DemoWorkspace::query()->count())->toBe(0);
});

it('persists scenario metadata and dispatches only opted-in workspaces through the scenario manager', function () {
    $scenario = bindScenarioIntegrationFake();
    $admin = demoScenarioIntegrationAdmin();
    $payload = demoScenarioIntegrationPayload();
    $payload['selected_modules'] = array_values(array_unique([
        ...$payload['selected_modules'],
        'jobs',
        'requests',
    ]));

    $workspace = app(DemoWorkspaceProvisioner::class)->create(
        $payload,
        $admin
    );
    $selectedModules = collect($workspace->selected_modules)->sort()->values()->all();
    $enabledFeatures = collect($workspace->owner?->company_features)
        ->filter(fn (mixed $enabled): bool => $enabled === true)
        ->keys()
        ->sort()
        ->values()
        ->all();
    expect($workspace->scenario_key)->toBe('studio_naya_coiffure')
        ->and($workspace->data_volume)->toBe(DemoDataVolume::Small)
        ->and($workspace->reference_date?->toDateString())->toBe('2026-08-20')
        ->and($workspace->random_seed)->toBe(12345)
        ->and($workspace->scenario_version)->toBe(1)
        ->and(data_get($workspace->baseline_snapshot, 'scenario_key'))->toBe('studio_naya_coiffure')
        ->and(data_get($workspace->baseline_snapshot, 'reference_date'))->toBe('2026-08-20')
        ->and(data_get($workspace->configuration, 'scenario.random_seed'))->toBe(12345)
        ->and(data_get($workspace->seed_summary, 'generator'))->toBe('scenario_engine')
        ->and(data_get($workspace->seed_summary, 'scenario_key'))->toBe('studio_naya_coiffure')
        ->and($scenario->lastContext)->not->toBeNull()
        ->and($scenario->lastContext?->workspace->is($workspace))->toBeTrue()
        ->and($scenario->lastContext?->owner->is($workspace->owner))->toBeTrue()
        ->and($enabledFeatures)->toBe($selectedModules)
        ->and($selectedModules)->not->toContain('jobs', 'requests')
        ->and(array_diff(
            app(DemoWorkspaceCatalog::class)->requiredModulesForScenario($workspace->scenario_key),
            $selectedModules,
        ))->toBe([]);
});

it('preserves scenario inputs when a workspace is reset to its isolated baseline', function () {
    bindScenarioIntegrationFake();
    $admin = demoScenarioIntegrationAdmin();
    $unrelatedTenant = User::query()->create([
        'name' => 'Unrelated Tenant',
        'email' => 'unrelated-tenant@example.test',
        'password' => 'password',
        'role_id' => Role::query()->where('name', 'superadmin')->value('id'),
        'onboarding_completed_at' => now(),
    ]);
    $provisioner = app(DemoWorkspaceProvisioner::class);
    $workspace = $provisioner->create(demoScenarioIntegrationPayload(), $admin);
    $originalOwnerId = $workspace->owner_user_id;
    $originalFingerprint = data_get($workspace->seed_summary, 'semantic_fingerprint');

    $queuedReset = $provisioner->queueResetToBaseline($workspace, $admin);
    (new ProvisionDemoWorkspaceJob($queuedReset->id, $admin->id, true))->handle(
        $provisioner,
        app(DemoWorkspaceTimelineService::class),
    );
    $reset = $queuedReset->fresh(['owner']);

    expect($reset->owner_user_id)->not->toBe($originalOwnerId)
        ->and(User::query()->find($originalOwnerId))->toBeNull()
        ->and(User::query()->find($unrelatedTenant->id))->not->toBeNull()
        ->and($reset->scenario_key)->toBe('studio_naya_coiffure')
        ->and($reset->data_volume)->toBe(DemoDataVolume::Small)
        ->and($reset->reference_date?->toDateString())->toBe('2026-08-20')
        ->and($reset->random_seed)->toBe(12345)
        ->and(data_get($reset->seed_summary, 'semantic_fingerprint'))->toBe($originalFingerprint)
        ->and(DemoWorkspace::query()->count())->toBe(1);
});

it('atomically activates a successful queued scenario reset', function () {
    bindScenarioIntegrationFake();
    $admin = demoScenarioIntegrationAdmin();
    $provisioner = app(DemoWorkspaceProvisioner::class);
    $workspace = $provisioner->create(demoScenarioIntegrationPayload(), $admin);
    $originalOwnerId = $workspace->owner_user_id;
    $originalAccessEmail = $workspace->access_email;
    $originalAccessPassword = $workspace->access_password;
    $originalFingerprint = data_get($workspace->seed_summary, 'semantic_fingerprint');
    Customer::query()->create([
        'user_id' => $originalOwnerId,
        'first_name' => 'Retired',
        'last_name' => 'Dataset',
        'email' => 'retired-dataset@example.test',
        'is_active' => true,
    ]);

    $queued = $provisioner->queueResetToBaseline($workspace, $admin);
    (new ProvisionDemoWorkspaceJob($queued->id, $admin->id, true))->handle(
        $provisioner,
        app(DemoWorkspaceTimelineService::class),
    );
    $ready = $queued->fresh(['owner']);
    $activatedOwnerId = $ready->owner_user_id;

    (new ProvisionDemoWorkspaceJob($queued->id, $admin->id, true))->handle(
        $provisioner,
        app(DemoWorkspaceTimelineService::class),
    );

    expect($ready->provisioning_status)->toBe(DemoWorkspaceProvisioner::STATUS_READY)
        ->and($ready->owner_user_id)->not->toBe($originalOwnerId)
        ->and(User::query()->find($originalOwnerId))->toBeNull()
        ->and(Customer::query()->where('email', 'retired-dataset@example.test')->exists())->toBeFalse()
        ->and($ready->access_email)->toBe($originalAccessEmail)
        ->and($ready->access_password)->toBe($originalAccessPassword)
        ->and(Hash::check((string) $originalAccessPassword, (string) $ready->owner?->password))->toBeTrue()
        ->and(data_get($ready->seed_summary, 'semantic_fingerprint'))->toBe($originalFingerprint)
        ->and($ready->last_reset_by_user_id)->toBe($admin->id)
        ->and($ready->last_reset_at)->not->toBeNull()
        ->and($ready->fresh()->owner_user_id)->toBe($activatedOwnerId);
});

it('keeps the live tenant intact when a queued shadow reset fails', function () {
    $scenario = new class implements DemoScenario
    {
        public int $generationCount = 0;

        public function key(): string
        {
            return 'studio_naya_coiffure';
        }

        public function version(): int
        {
            return 1;
        }

        public function defaultVolume(): DemoDataVolume
        {
            return DemoDataVolume::Small;
        }

        public function generate(DemoScenarioContext $context): array
        {
            $this->generationCount++;
            Customer::query()->create([
                'user_id' => $context->owner->id,
                'first_name' => $this->generationCount === 1 ? 'Live' : 'Shadow',
                'last_name' => 'Sentinel',
                'email' => 'sentinel-'.$context->owner->id.'@example.test',
                'is_active' => true,
            ]);

            if ($this->generationCount === 2) {
                throw new RuntimeException('Injected shadow reset failure.');
            }

            return [
                'generator' => 'failure_injection_scenario',
                'dataset_fingerprint' => 'live-dataset-fingerprint',
                'customers' => 1,
            ];
        }
    };
    app()->singleton(
        DemoScenarioRegistry::class,
        fn (): DemoScenarioRegistry => new DemoScenarioRegistry([$scenario])
    );
    $admin = demoScenarioIntegrationAdmin();
    $provisioner = app(DemoWorkspaceProvisioner::class);
    $workspace = $provisioner->create(demoScenarioIntegrationPayload(), $admin);
    $originalOwner = $workspace->owner;
    $originalOwnerId = $workspace->owner_user_id;
    $originalCustomer = Customer::query()->where('user_id', $originalOwnerId)->firstOrFail();
    $originalAccessEmail = $workspace->access_email;
    $originalAccessPassword = $workspace->access_password;
    $originalSummary = $workspace->seed_summary;
    $userCount = User::query()->count();

    $queued = $provisioner->queueResetToBaseline($workspace, $admin);
    expect($queued->owner_user_id)->toBe($originalOwnerId)
        ->and($queued->provisioning_status)->toBe(DemoWorkspaceProvisioner::STATUS_QUEUED);

    (new ProvisionDemoWorkspaceJob($queued->id, $admin->id, true))->handle(
        $provisioner,
        app(DemoWorkspaceTimelineService::class),
    );

    $failed = $queued->fresh(['owner']);

    expect($scenario->generationCount)->toBe(2)
        ->and($failed->provisioning_status)->toBe(DemoWorkspaceProvisioner::STATUS_FAILED)
        ->and($failed->provisioning_error)->toContain('Injected shadow reset failure')
        ->and($failed->owner_user_id)->toBe($originalOwnerId)
        ->and($failed->access_email)->toBe($originalAccessEmail)
        ->and($failed->access_password)->toBe($originalAccessPassword)
        ->and($failed->seed_summary)->toBe($originalSummary)
        ->and($failed->owner?->is($originalOwner))->toBeTrue()
        ->and(Hash::check((string) $originalAccessPassword, (string) $failed->owner?->password))->toBeTrue()
        ->and(User::query()->count())->toBe($userCount)
        ->and(Customer::query()->count())->toBe(1)
        ->and(Customer::query()->find($originalCustomer->id)?->user_id)->toBe($originalOwnerId)
        ->and(Customer::query()->find($originalCustomer->id)?->first_name)->toBe('Live');
});

it('provisions and reproducibly resets the real small Studio Naya scenario', function () {
    $admin = demoScenarioIntegrationAdmin();
    $unrelatedTenant = User::query()->create([
        'name' => 'Unrelated Real Tenant',
        'email' => 'unrelated-real-tenant@example.test',
        'password' => 'password',
        'role_id' => Role::query()->where('name', 'superadmin')->value('id'),
        'onboarding_completed_at' => now(),
    ]);
    $provisioner = app(DemoWorkspaceProvisioner::class);

    $workspace = $provisioner->create(
        demoScenarioIntegrationPayload(),
        $admin
    );
    $originalOwnerId = $workspace->owner_user_id;
    $originalFingerprint = data_get($workspace->seed_summary, 'dataset_fingerprint');
    $selectedModules = collect($workspace->selected_modules)->sort()->values()->all();
    $enabledFeatures = collect($workspace->owner?->company_features)
        ->filter(fn (mixed $enabled): bool => $enabled === true)
        ->keys()
        ->sort()
        ->values()
        ->all();
    $moduleEvidence = (array) data_get($workspace->seed_summary, 'module_evidence', []);
    $moduleRoutes = app(DemoScenarioModuleEvidence::class)->routeNames($selectedModules);
    $activeCredentials = collect($workspace->extra_access_credentials)
        ->where('is_active', true)
        ->values();
    $managerCredential = $activeCredentials->firstWhere('role_key', 'manager');
    $allCredentialPasswordsAuthenticate = $activeCredentials->every(function (array $credential): bool {
        $user = User::query()->find($credential['user_id'] ?? null);

        return $user !== null
            && filled($credential['password'] ?? null)
            && Hash::check((string) $credential['password'], (string) $user->password);
    });
    $scenarioInsights = app(DemoScenarioDashboardQuery::class)->execute($workspace->owner);
    $expectedMonthlyRevenue = collect(data_get($scenarioInsights, 'monthly.labels', []))
        ->map(function (string $label) use ($workspace): float {
            $month = CarbonImmutable::parse($label.'-01', (string) $workspace->timezone);

            return round((float) Payment::query()
                ->where('user_id', $workspace->owner_user_id)
                ->whereIn('status', Payment::settledStatuses())
                ->whereBetween('paid_at', [$month->startOfMonth()->utc(), $month->endOfMonth()->utc()])
                ->sum('amount'), 2);
        })
        ->all();
    $storyCustomerModels = Customer::query()
        ->where('user_id', $workspace->owner_user_id)
        ->whereIn('first_name', ['Aïcha', 'Samantha', 'Nadia', 'Marc-André', 'Chloé'])
        ->get();
    $namedStories = $storyCustomerModels
        ->map(fn (Customer $customer): string => trim($customer->first_name.' '.$customer->last_name))
        ->sort()
        ->values()
        ->all();
    $storyCustomers = $storyCustomerModels->keyBy(
        fn (Customer $customer): string => trim($customer->first_name.' '.$customer->last_name),
    );
    $samantha = $storyCustomers->get('Samantha Joseph');
    $aicha = $storyCustomers->get('Aïcha Martin');
    $marc = $storyCustomers->get('Marc-André Beaulieu');
    $nadia = $storyCustomers->get('Nadia Pierre');
    $chloe = $storyCustomers->get('Chloé Nguyen');
    $weddingQuote = Quote::query()
        ->with('taxes')
        ->where('user_id', $workspace->owner_user_id)
        ->where('customer_id', $samantha?->id)
        ->where('job_title', 'like', '%mariage%')
        ->firstOrFail();
    $quoteStatuses = Quote::query()
        ->where('user_id', $workspace->owner_user_id)
        ->distinct()
        ->pluck('status');
    $weddingDeposit = Transaction::query()
        ->where('quote_id', $weddingQuote->id)
        ->where('type', 'deposit')
        ->where('status', 'completed')
        ->firstOrFail();
    $weddingTask = Task::query()
        ->forAccount((int) $workspace->owner_user_id)
        ->where('customer_id', $samantha?->id)
        ->open()
        ->firstOrFail();
    $referenceDayEnd = CarbonImmutable::parse(
        '2026-08-20',
        (string) $workspace->timezone,
    )->endOfDay()->utc();
    $futureCustomerIds = Reservation::query()
        ->forAccount((int) $workspace->owner_user_id)
        ->whereIn('status', Reservation::ACTIVE_STATUSES)
        ->where('starts_at', '>', $referenceDayEnd)
        ->whereNotNull('client_id')
        ->distinct()
        ->pluck('client_id');
    $acceptedFutureQuotes = Quote::query()
        ->byUser((int) $workspace->owner_user_id)
        ->where('status', 'accepted')
        ->whereIn('customer_id', $futureCustomerIds)
        ->get(['id', 'total']);
    $expectedFutureCommitment = round(
        (float) $acceptedFutureQuotes->sum('total')
        - (float) Transaction::query()
            ->where('user_id', $workspace->owner_user_id)
            ->whereIn('quote_id', $acceptedFutureQuotes->pluck('id'))
            ->where('type', 'deposit')
            ->where('status', 'completed')
            ->sum('amount'),
        2,
    );
    $nadiaInvoice = Invoice::query()
        ->byUser((int) $workspace->owner_user_id)
        ->where('customer_id', $nadia?->id)
        ->firstOrFail();
    $chloeRefund = Payment::query()
        ->where('user_id', $workspace->owner_user_id)
        ->where('customer_id', $chloe?->id)
        ->where('reference', 'NAYA-CHLOE-REFUND')
        ->firstOrFail();
    $offers = OfferPackage::query()
        ->forAccount((int) $workspace->owner_user_id)
        ->with('items.product')
        ->get();
    $customerPackages = CustomerPackage::query()
        ->forAccount((int) $workspace->owner_user_id)
        ->with([
            'offerPackage',
            'customer',
            'usages',
            'invoice.payments',
            'invoiceItem',
            'renewedFrom',
        ])
        ->get();
    $packInvoiceLines = InvoiceItem::query()
        ->whereHas('invoice', fn ($query) => $query->where('user_id', $workspace->owner_user_id))
        ->with('invoice.payments')
        ->get()
        ->filter(fn (InvoiceItem $item): bool => data_get($item->meta, 'offer_package_type') === OfferPackage::TYPE_PACK)
        ->values();
    $packageBehaviorEvents = CustomerBehaviorEvent::query()
        ->byUser((int) $workspace->owner_user_id)
        ->whereIn('event_type', [
            'customer_package_purchased',
            'customer_package_low_balance',
            'customer_package_expired',
        ])
        ->count();
    $loyaltyProgram = LoyaltyProgram::query()
        ->where('user_id', $workspace->owner_user_id)
        ->firstOrFail();
    $loyaltyStoryEvents = LoyaltyPointLedger::query()
        ->where('user_id', $workspace->owner_user_id)
        ->whereNull('payment_id')
        ->get();
    $settledLoyaltyPayments = Payment::query()
        ->where('user_id', $workspace->owner_user_id)
        ->whereIn('status', Payment::settledStatuses())
        ->whereNotNull('customer_id')
        ->where('amount', '>=', (float) $loyaltyProgram->minimum_spend)
        ->with('loyaltyPointLedgers')
        ->get();
    $packStatusCounts = $packInvoiceLines->pluck('invoice.status')->countBy();
    $billedReservationIds = InvoiceItem::query()
        ->whereHas('invoice', fn ($query) => $query->where('user_id', $workspace->owner_user_id))
        ->get(['meta'])
        ->map(fn (InvoiceItem $item): int => (int) data_get($item->meta, 'reservation_id', 0))
        ->filter()
        ->all();
    $doubleBilledPackageUsages = CustomerPackageUsage::query()
        ->where('user_id', $workspace->owner_user_id)
        ->whereIn('reservation_id', $billedReservationIds)
        ->count();
    $renewalChain = $customerPackages
        ->first(fn (CustomerPackage $package): bool => $package->renewed_from_customer_package_id !== null);
    $scenarioReference = CarbonImmutable::parse(
        $workspace->reference_date?->toDateString(),
        $workspace->timezone,
    );
    $activeRecurringAlreadyDue = $customerPackages
        ->where('status', CustomerPackage::STATUS_ACTIVE)
        ->where('is_recurring', true)
        ->where('recurrence_status', CustomerPackage::RECURRENCE_ACTIVE)
        ->filter(fn (CustomerPackage $package): bool => $package->next_renewal_at?->lte($scenarioReference) ?? true);
    $pendingRecurringPackages = $customerPackages->whereIn('recurrence_status', [
        CustomerPackage::RECURRENCE_PAYMENT_DUE,
        CustomerPackage::RECURRENCE_SUSPENDED,
    ]);
    $loyaltyEventsBySale = $loyaltyStoryEvents->groupBy(
        fn (LoyaltyPointLedger $ledger): int => (int) data_get($ledger->meta, 'sale_id', 0),
    );
    $criticalTimelineLinks = [
        [$samantha, 'quote_sent', 'quote_id', $weddingQuote->id],
        [$samantha, 'deposit_paid', 'transaction_id', $weddingDeposit->id],
        [$samantha, 'final_invoice_due', 'task_id', $weddingTask->id],
        [$chloe, 'partial_refund_recorded', 'payment_id', $chloeRefund->id],
    ];

    expect($workspace->provisioning_status)->toBe(DemoWorkspaceProvisioner::STATUS_READY)
        ->and($workspace->scenario_key)->toBe('studio_naya_coiffure')
        ->and($workspace->owner?->demo_type)->toBe('scenario:studio_naya_coiffure')
        ->and(data_get($workspace->seed_summary, 'team_members'))->toBe(5)
        ->and(data_get($workspace->seed_summary, 'customers'))->toBe(40)
        ->and(data_get($workspace->seed_summary, 'reservations'))->toBe(180)
        ->and(data_get($workspace->seed_summary, 'invoices'))->toBe(110)
        ->and(data_get($workspace->seed_summary, 'deposits'))->toBe(1)
        ->and(data_get($workspace->seed_summary, 'tasks'))->toBe(1)
        ->and(data_get($workspace->seed_summary, 'transactions'))->toBe(1)
        ->and(data_get($workspace->seed_summary, 'offer_packages'))->toBe(7)
        ->and(data_get($workspace->seed_summary, 'offer_packs'))->toBe(3)
        ->and(data_get($workspace->seed_summary, 'offer_forfaits'))->toBe(4)
        ->and(data_get($workspace->seed_summary, 'offer_package_items'))->toBe(20)
        ->and(data_get($workspace->seed_summary, 'pack_invoice_lines'))->toBe(6)
        ->and(data_get($workspace->seed_summary, 'customer_packages'))->toBe(12)
        ->and(data_get($workspace->seed_summary, 'customer_packages_active'))->toBe(6)
        ->and(data_get($workspace->seed_summary, 'customer_packages_consumed'))->toBe(3)
        ->and(data_get($workspace->seed_summary, 'customer_packages_expired'))->toBe(3)
        ->and(data_get($workspace->seed_summary, 'customer_packages_recurring'))->toBe(4)
        ->and(data_get($workspace->seed_summary, 'customer_package_usages'))->toBe(40)
        ->and(data_get($workspace->seed_summary, 'customer_package_usages_reversed'))->toBe(1)
        ->and(data_get($workspace->seed_summary, 'package_behavior_events'))->toBe(18)
        ->and(data_get($workspace->seed_summary, 'loyalty_story_events'))->toBe(3)
        ->and(data_get($workspace->seed_summary, 'invariant_report.violation_count'))->toBe(0)
        ->and(collect($moduleEvidence)->keys()->sort()->values()->all())->toBe($selectedModules)
        ->and(collect($moduleEvidence)->every(
            fn (array $evidence): bool => ($evidence['demonstrable'] ?? false) === true
                && (int) ($evidence['records'] ?? 0) > 0,
        ))->toBeTrue()
        ->and(collect($moduleRoutes)->filter(fn (?string $routeName): bool => blank($routeName))->all())->toBe([])
        ->and(data_get($workspace->seed_summary, 'dataset_fingerprint'))->toBeString()->not->toBeEmpty()
        ->and($enabledFeatures)->toBe($selectedModules)
        ->and(array_diff(
            app(DemoWorkspaceCatalog::class)->requiredModulesForScenario($workspace->scenario_key),
            $selectedModules,
        ))->toBe([])
        ->and($activeCredentials)->toHaveCount(3)
        ->and($allCredentialPasswordsAuthenticate)->toBeTrue()
        ->and($managerCredential)->toBeArray()
        ->and($managerCredential['password'])->toBe($workspace->access_password)
        ->and($scenarioInsights)->toBeArray()
        ->and(data_get($scenarioInsights, 'range_months'))->toBe(12)
        ->and(data_get($scenarioInsights, 'monthly.labels'))->toHaveCount(12)
        ->and(data_get($scenarioInsights, 'monthly.revenue'))->toHaveCount(12)->toBe($expectedMonthlyRevenue)
        ->and(data_get($scenarioInsights, 'monthly.expenses'))->toHaveCount(12)
        ->and(data_get($scenarioInsights, 'monthly.reservations'))->toHaveCount(12)
        ->and((float) data_get($scenarioInsights, 'metrics.committed_future_revenue'))
        ->toBe($expectedFutureCommitment)->toBeGreaterThan(0)
        ->and(data_get($scenarioInsights, 'top_services'))->not->toBeEmpty()
        ->and(data_get($scenarioInsights, 'top_employees'))->not->toBeEmpty()
        ->and(data_get($scenarioInsights, 'top_products'))->not->toBeEmpty()
        ->and($namedStories)->toBe([
            'Aïcha Martin',
            'Chloé Nguyen',
            'Marc-André Beaulieu',
            'Nadia Pierre',
            'Samantha Joseph',
        ])
        ->and($quoteStatuses->diff(['draft', 'sent', 'accepted', 'declined'])->values()->all())->toBe([])
        ->and($quoteStatuses->contains('declined'))->toBeTrue()
        ->and($quoteStatuses->contains('rejected'))->toBeFalse()
        ->and($weddingQuote->status)->toBe('accepted')
        ->and($weddingQuote->created_at?->setTimezone($workspace->timezone)->toDateString())
        ->toBe(CarbonImmutable::parse('2026-08-20', $workspace->timezone)->subDays(84)->toDateString())
        ->and($weddingQuote->accepted_at?->setTimezone($workspace->timezone)->toDateString())
        ->toBe(CarbonImmutable::parse('2026-08-20', $workspace->timezone)->subDays(78)->toDateString())
        ->and($weddingQuote->taxes)->toHaveCount(2)
        ->and(round((float) $weddingDeposit->amount / (float) $weddingQuote->total, 2))->toBe(0.30)
        ->and($weddingDeposit->paid_at?->setTimezone($workspace->timezone)->toDateString())
        ->toBe(CarbonImmutable::parse('2026-08-20', $workspace->timezone)->subDays(77)->toDateString())
        ->and($weddingTask->status)->toBe(Task::STATUS_TODO)
        ->and($weddingTask->due_date?->toDateString())
        ->toBe(CarbonImmutable::parse('2026-08-20', $workspace->timezone)->addDays(35)->toDateString())
        ->and($nadiaInvoice->status)->toBe('partial')
        ->and($nadiaInvoice->balance_due)->toBeGreaterThan(0)
        ->and(Sale::query()->where('user_id', $workspace->owner_user_id)->where('customer_id', $aicha?->id)->count())->toBe(3)
        ->and(Sale::query()->where('user_id', $workspace->owner_user_id)->where('customer_id', $marc?->id)->count())->toBe(2)
        ->and(Payment::query()
            ->where('user_id', $workspace->owner_user_id)
            ->where('customer_id', $marc?->id)
            ->where('tip_amount', '>', 0)
            ->count())->toBe(8)
        ->and($offers)->toHaveCount(7)
        ->and($offers->where('type', OfferPackage::TYPE_PACK))->toHaveCount(3)
        ->and($offers->where('type', OfferPackage::TYPE_FORFAIT))->toHaveCount(4)
        ->and($offers->every(fn (OfferPackage $offer): bool => $offer->status === OfferPackage::STATUS_ACTIVE
            && $offer->is_public
            && $offer->items->isNotEmpty()
            && $offer->items->every(fn (OfferPackageItem $item): bool => (int) $item->product?->user_id === (int) $workspace->owner_user_id)))
        ->toBeTrue()
        ->and($offers->where('type', OfferPackage::TYPE_PACK)->every(function (OfferPackage $offer): bool {
            $retailValue = round((float) $offer->items->sum(
                fn (OfferPackageItem $item): float => (float) $item->quantity * (float) $item->unit_price,
            ), 2);

            return abs($retailValue - (float) $offer->price - (float) data_get($offer->metadata, 'savings_amount')) <= 0.009;
        }))->toBeTrue()
        ->and($packInvoiceLines)->toHaveCount(6)
        ->and((int) $packStatusCounts->get('paid', 0))->toBe(3)
        ->and((int) $packStatusCounts->get('sent', 0))->toBe(2)
        ->and((int) $packStatusCounts->get('overdue', 0))->toBe(1)
        ->and($packInvoiceLines->where('invoice.status', 'paid')->every(
            fn (InvoiceItem $line): bool => abs((float) $line->invoice?->amount_paid - (float) $line->invoice?->total) <= 0.009,
        ))->toBeTrue()
        ->and($packInvoiceLines->reject(fn (InvoiceItem $line): bool => $line->invoice?->status === 'paid')->every(
            fn (InvoiceItem $line): bool => $line->invoice?->payments->isEmpty(),
        ))->toBeTrue()
        ->and($customerPackages->where('status', CustomerPackage::STATUS_ACTIVE))->toHaveCount(6)
        ->and($customerPackages->where('status', CustomerPackage::STATUS_CONSUMED))->toHaveCount(3)
        ->and($customerPackages->where('status', CustomerPackage::STATUS_EXPIRED))->toHaveCount(3)
        ->and($customerPackages->where('is_recurring', true))->toHaveCount(4)
        ->and($customerPackages->pluck('recurrence_status')->filter()->unique()->sort()->values()->all())
        ->toBe([
            CustomerPackage::RECURRENCE_ACTIVE,
            CustomerPackage::RECURRENCE_PAYMENT_DUE,
            CustomerPackage::RECURRENCE_SUSPENDED,
        ])
        ->and(CustomerPackageUsage::query()->where('user_id', $workspace->owner_user_id)->count())->toBe(40)
        ->and(CustomerPackageUsage::query()->where('user_id', $workspace->owner_user_id)->whereNotNull('reversed_at')->count())->toBe(1)
        ->and(CustomerPackageUsage::query()->where('user_id', $workspace->owner_user_id)->whereNotNull('reservation_id')->count())
        ->toBeGreaterThan(0)
        ->and($doubleBilledPackageUsages)->toBe(0)
        ->and($customerPackages->every(function (CustomerPackage $package): bool {
            if ((float) $package->price_paid <= 0) {
                return $package->invoice_id === null && $package->invoice_item_id === null;
            }

            return $package->invoice?->status === 'paid'
                && (int) $package->invoice?->customer_id === (int) $package->customer_id
                && (int) $package->invoiceItem?->invoice_id === (int) $package->invoice_id
                && data_get($package->invoiceItem?->meta, 'offer_package_type') === OfferPackage::TYPE_FORFAIT
                && abs((float) $package->price_paid - (float) $package->invoiceItem?->total) <= 0.009;
        }))->toBeTrue()
        ->and($customerPackages->where('price_paid', '>', 0))->not->toBeEmpty()
        ->and($customerPackages->where('price_paid', '<=', 0))->not->toBeEmpty()
        ->and($customerPackages->filter(
            fn (CustomerPackage $package): bool => data_get($package->metadata, 'lifecycle_state') === 'active_low_balance',
        )->every(fn (CustomerPackage $package): bool => filled(
            data_get($package->metadata, 'automation.notifications.low_balance_sent_at.sent_at'),
        )))->toBeTrue()
        ->and($customerPackages->filter(
            fn (CustomerPackage $package): bool => data_get($package->metadata, 'lifecycle_state') === 'expired_with_balance',
        )->every(fn (CustomerPackage $package): bool => filled(
            data_get($package->metadata, 'automation.expired_at'),
        )))->toBeTrue()
        ->and($pendingRecurringPackages)->toHaveCount(2)
        ->and($pendingRecurringPackages->every(function (CustomerPackage $package): bool {
            $invoice = Invoice::query()->with('items')->find(
                (int) data_get($package->metadata, 'recurrence.pending_invoice_id'),
            );
            $itemId = (int) data_get($package->metadata, 'recurrence.pending_invoice_item_id');
            $item = $invoice?->items->firstWhere('id', $itemId);

            return $invoice
                && (int) $invoice->customer_id === (int) $package->customer_id
                && in_array($invoice->status, ['sent', 'overdue'], true)
                && ($package->recurrence_status !== CustomerPackage::RECURRENCE_SUSPENDED
                    || $invoice->status === 'overdue')
                && (int) data_get($item?->meta, 'renewal_for_customer_package_id') === (int) $package->id;
        }))->toBeTrue()
        ->and($renewalChain)->toBeInstanceOf(CustomerPackage::class)
        ->and((int) $renewalChain?->initial_quantity)->toBe(
            (int) data_get($renewalChain?->metadata, 'recurrence.period_allocation_quantity')
                + (int) data_get($renewalChain?->metadata, 'recurrence.carried_over_quantity'),
        )
        ->and((int) data_get($renewalChain?->metadata, 'recurrence.carried_over_quantity'))
        ->toBe((int) $renewalChain?->renewedFrom?->remaining_quantity)
        ->and($renewalChain?->starts_at?->toDateString())
        ->toBe($renewalChain?->current_period_starts_at?->toDateString())
        ->and($renewalChain?->expires_at?->toDateString())
        ->toBe($renewalChain?->current_period_ends_at?->toDateString())
        ->and($renewalChain?->renewedFrom?->current_period_ends_at?->copy()->addDay()->toDateString())
        ->toBe($renewalChain?->current_period_starts_at?->toDateString())
        ->and($renewalChain?->current_period_starts_at?->toDateString())
        ->toBe($scenarioReference->startOfMonth()->toDateString())
        ->and($renewalChain?->current_period_ends_at?->toDateString())
        ->toBe($scenarioReference->endOfMonth()->toDateString())
        ->and($renewalChain?->next_renewal_at?->toDateString())
        ->toBe($scenarioReference->addMonth()->startOfMonth()->toDateString())
        ->and($renewalChain?->current_period_starts_at?->lte($scenarioReference))->toBeTrue()
        ->and($renewalChain?->current_period_ends_at?->gte($scenarioReference))->toBeTrue()
        ->and($renewalChain?->next_renewal_at?->gt($scenarioReference))->toBeTrue()
        ->and($renewalChain?->usages->every(function (CustomerPackageUsage $usage) use ($renewalChain, $workspace): bool {
            $usedOn = $usage->used_at?->setTimezone($workspace->timezone)->toDateString();

            return $usedOn !== null
                && $usedOn >= $renewalChain->current_period_starts_at?->toDateString()
                && $usedOn <= $renewalChain->current_period_ends_at?->toDateString();
        }))->toBeTrue()
        ->and($activeRecurringAlreadyDue)->toBeEmpty()
        ->and($packageBehaviorEvents)->toBe(18)
        ->and($loyaltyProgram->is_enabled)->toBeTrue()
        ->and((float) $loyaltyProgram->points_per_currency_unit)->toBe(5.0)
        ->and($loyaltyProgram->points_label)->toBe('Points Naya')
        ->and($settledLoyaltyPayments)->not->toBeEmpty()
        ->and($settledLoyaltyPayments->every(function (Payment $payment) use ($loyaltyProgram, $workspace): bool {
            $accruals = $payment->loyaltyPointLedgers
                ->where('event', LoyaltyPointLedger::EVENT_ACCRUAL)
                ->values();
            $expectedPoints = (int) floor(
                (float) $payment->amount * (float) $loyaltyProgram->points_per_currency_unit,
            );

            return $accruals->count() === 1
                && (int) $accruals->first()?->user_id === (int) $workspace->owner_user_id
                && (int) $accruals->first()?->customer_id === (int) $payment->customer_id
                && (int) $accruals->first()?->points === $expectedPoints
                && abs((float) $accruals->first()?->amount - (float) $payment->amount) <= 0.009;
        }))->toBeTrue()
        ->and($loyaltyStoryEvents)->toHaveCount(3)
        ->and($loyaltyStoryEvents->pluck('event')->sort()->values()->all())->toBe([
            LoyaltyPointLedger::EVENT_REDEMPTION,
            LoyaltyPointLedger::EVENT_REDEMPTION,
            LoyaltyPointLedger::EVENT_REDEMPTION_REVERSAL,
        ])
        ->and($loyaltyEventsBySale->keys()->every(fn (mixed $saleId): bool => (int) $saleId > 0))->toBeTrue()
        ->and($loyaltyEventsBySale->every(function ($events, mixed $saleId) use ($workspace): bool {
            $sale = Sale::query()
                ->where('user_id', $workspace->owner_user_id)
                ->find((int) $saleId);
            if (! $sale) {
                return false;
            }

            return $events->count() === 1
                ? (int) $sale->loyalty_points_redeemed === abs((int) $events->first()->points)
                    && abs((float) $sale->loyalty_discount_total - (float) $events->first()->amount) <= 0.009
                : $events->count() === 2
                    && (int) $events->sum('points') === 0
                    && (int) $sale->loyalty_points_redeemed === 0
                    && (float) $sale->loyalty_discount_total === 0.0;
        }))->toBeTrue()
        ->and(Product::query()
            ->byUser((int) $workspace->owner_user_id)
            ->services()
            ->whereNotNull('image')
            ->count())->toBe(28)
        ->and(Customer::query()
            ->byUser((int) $workspace->owner_user_id)
            ->where('logo', 'like', '/images/presets/avatar-%')
            ->count())->toBe(40)
        ->and(User::query()
            ->whereIn('id', TeamMember::query()
                ->forAccount((int) $workspace->owner_user_id)
                ->pluck('user_id'))
            ->where('profile_picture', 'like', '/images/presets/avatar-%')
            ->count())->toBe(5);

    foreach ($criticalTimelineLinks as [$customer, $event, $foreignKey, $expectedId]) {
        $activity = ActivityLog::query()
            ->where('subject_type', $customer->getMorphClass())
            ->where('subject_id', $customer->id)
            ->where('action', $event)
            ->firstOrFail();

        expect((int) data_get($activity->properties, $foreignKey))->toBe((int) $expectedId)
            ->and((int) data_get($activity->properties, 'linked_entity_id'))->toBe((int) $expectedId);
    }

    $dashboardResponse = $this->actingAs($workspace->owner)
        ->withSession(['two_factor_passed' => true])
        ->get(route('dashboard'));
    $dashboardResponse
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('scenarioInsights.scenario_key', 'studio_naya_coiffure')
            ->where('scenarioInsights.range_months', 12)
            ->has('scenarioInsights.monthly.labels', 12)
            ->has('scenarioInsights.monthly.revenue', 12)
            ->has('scenarioInsights.monthly.expenses', 12)
            ->has('scenarioInsights.monthly.reservations', 12)
            ->has('scenarioInsights.top_services')
            ->has('scenarioInsights.top_employees')
            ->has('scenarioInsights.top_products')
            ->where('stats.catalog_total', 46)
            ->where('stats.products_total', 18)
            ->where('stats.inventory_value', fn (mixed $value): bool => (float) $value > 0)
            ->where('stats.pos_revenue_paid', fn (mixed $value): bool => (float) $value > 0));

    $performancePayload = $this->actingAs($workspace->owner)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('performance.index'))
        ->assertOk()
        ->json();
    $presencePayload = $this->actingAs($workspace->owner)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('presence.index'))
        ->assertOk()
        ->json();

    expect(data_get($performancePayload, 'performanceMode'))->toBe('reservations')
        ->and((int) data_get($performancePayload, 'clientPerformance.periods.month.orders'))->toBeGreaterThan(0)
        ->and((float) data_get($performancePayload, 'clientPerformance.periods.month.revenue'))->toBeGreaterThan(0)
        ->and(data_get($performancePayload, 'clientPerformance.periods.month.top_customers'))->not->toBeEmpty()
        ->and(data_get($performancePayload, 'employeePerformance.periods.month.top_sellers'))->not->toBeEmpty()
        ->and(collect(data_get($presencePayload, 'people', []))->sum('reservations_today'))->toBeGreaterThan(0)
        ->and(collect(data_get($presencePayload, 'people', []))->whereNotNull('current_status'))->toHaveCount(5);

    foreach ($moduleRoutes as $module => $routeName) {
        expect(Route::has((string) $routeName))->toBeTrue("Missing representative route for module [{$module}].");

        $this->actingAs($workspace->owner)
            ->withSession(['two_factor_passed' => true])
            ->get(route((string) $routeName))
            ->assertOk();
    }

    $this->actingAs($workspace->owner)
        ->withSession(['two_factor_passed' => true])
        ->get(route('offer-packages.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('OfferPackages/Index')
            ->has('offers.data', 7)
            ->where('stats.active', 7)
            ->where('stats.packs', 3)
            ->where('stats.forfaits', 4)
            ->where('reporting.sales.packs.line_count', 6)
            ->where('reporting.sales.consumable_forfaits.sold_count', 12));

    $currentSmallTargets = config('demo_scenarios.volumes.small');
    $legacySmallTargets = collect($currentSmallTargets)
        ->except(StudioNayaBlueprint::IMMERSIVE_TARGET_KEYS)
        ->all();
    config()->set('demo_scenarios.volumes.small', $legacySmallTargets);

    try {
        $queuedReset = $provisioner->queueResetToBaseline($workspace, $admin);
        (new ProvisionDemoWorkspaceJob($queuedReset->id, $admin->id, true))->handle(
            $provisioner,
            app(DemoWorkspaceTimelineService::class),
        );
    } finally {
        config()->set('demo_scenarios.volumes.small', $currentSmallTargets);
    }

    $reset = $queuedReset->fresh(['owner']);
    $resetActiveCredentials = collect($reset->extra_access_credentials)
        ->where('is_active', true)
        ->values();
    $resetManagerCredential = $resetActiveCredentials->firstWhere('role_key', 'manager');
    $resetCredentialPasswordsAuthenticate = $resetActiveCredentials->every(function (array $credential): bool {
        $user = User::query()->find($credential['user_id'] ?? null);

        return $user !== null
            && filled($credential['password'] ?? null)
            && Hash::check((string) $credential['password'], (string) $user->password);
    });
    $resetEnabledFeatures = collect($reset->owner?->company_features)
        ->filter(fn (mixed $enabled): bool => $enabled === true)
        ->keys()
        ->sort()
        ->values()
        ->all();

    expect($reset->owner_user_id)->not->toBe($originalOwnerId)
        ->and(User::query()->find($originalOwnerId))->toBeNull()
        ->and(User::query()->find($unrelatedTenant->id))->not->toBeNull()
        ->and($reset->scenario_key)->toBe('studio_naya_coiffure')
        ->and($reset->data_volume)->toBe(DemoDataVolume::Small)
        ->and($reset->reference_date?->toDateString())->toBe('2026-08-20')
        ->and($reset->random_seed)->toBe(12345)
        ->and($reset->access_email)->toBe($workspace->access_email)
        ->and($reset->access_password)->toBe($workspace->access_password)
        ->and(Hash::check((string) $reset->access_password, (string) $reset->owner?->password))->toBeTrue()
        ->and($resetActiveCredentials)->toHaveCount(3)
        ->and($resetCredentialPasswordsAuthenticate)->toBeTrue()
        ->and($resetManagerCredential)->toBeArray()
        ->and($resetManagerCredential['email'])->toBe($reset->access_email)
        ->and($resetManagerCredential['password'])->toBe($reset->access_password)
        ->and($resetEnabledFeatures)->toBe($selectedModules)
        ->and(data_get($reset->seed_summary, 'offer_packages'))->toBe(7)
        ->and(data_get($reset->seed_summary, 'customer_packages'))->toBe(12)
        ->and(data_get($reset->seed_summary, 'customer_package_usages'))->toBe(40)
        ->and(data_get($reset->seed_summary, 'invariant_report.violation_count'))->toBe(0)
        ->and(data_get($reset->seed_summary, 'dataset_fingerprint'))->toBe($originalFingerprint)
        ->and(DemoWorkspace::query()->count())->toBe(1);
});

it('provisions the default medium Studio Naya volume with exact targets and valid invariants', function () {
    $admin = demoScenarioIntegrationAdmin();

    $workspace = app(DemoWorkspaceProvisioner::class)->create(
        demoScenarioIntegrationPayload([
            'data_volume' => 'medium',
        ]),
        $admin
    );

    expect(data_get($workspace->seed_summary, 'team_members'))->toBe(5)
        ->and(data_get($workspace->seed_summary, 'services'))->toBe(28)
        ->and(data_get($workspace->seed_summary, 'products'))->toBe(18)
        ->and(data_get($workspace->seed_summary, 'customers'))->toBe(300)
        ->and(data_get($workspace->seed_summary, 'reservations'))->toBe(1800)
        ->and(data_get($workspace->seed_summary, 'invoices'))->toBe(1100)
        ->and(data_get($workspace->seed_summary, 'payments'))->toBe(1450)
        ->and(data_get($workspace->seed_summary, 'quotes'))->toBe(55)
        ->and(data_get($workspace->seed_summary, 'sales'))->toBe(300)
        ->and(data_get($workspace->seed_summary, 'expenses'))->toBe(216)
        ->and(data_get($workspace->seed_summary, 'inventory_movements'))->toBe(2400)
        ->and(data_get($workspace->seed_summary, 'notifications'))->toBe(36)
        ->and(data_get($workspace->seed_summary, 'offer_packages'))->toBe(7)
        ->and(data_get($workspace->seed_summary, 'offer_packs'))->toBe(3)
        ->and(data_get($workspace->seed_summary, 'offer_forfaits'))->toBe(4)
        ->and(data_get($workspace->seed_summary, 'offer_package_items'))->toBe(20)
        ->and(data_get($workspace->seed_summary, 'pack_invoice_lines'))->toBe(18)
        ->and(data_get($workspace->seed_summary, 'customer_packages'))->toBe(36)
        ->and(data_get($workspace->seed_summary, 'customer_packages_active'))->toBe(18)
        ->and(data_get($workspace->seed_summary, 'customer_packages_consumed'))->toBe(9)
        ->and(data_get($workspace->seed_summary, 'customer_packages_expired'))->toBe(9)
        ->and(data_get($workspace->seed_summary, 'customer_packages_recurring'))->toBe(10)
        ->and(data_get($workspace->seed_summary, 'customer_package_usages'))->toBe(118)
        ->and(data_get($workspace->seed_summary, 'package_behavior_events'))->toBe(54)
        ->and(data_get($workspace->seed_summary, 'loyalty_story_events'))->toBe(3)
        ->and(data_get($workspace->seed_summary, 'invariant_report.violation_count'))->toBe(0)
        ->and(data_get($workspace->seed_summary, 'dataset_fingerprint'))->toBeString()->not->toBeEmpty();
});
