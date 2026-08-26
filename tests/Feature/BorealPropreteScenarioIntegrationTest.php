<?php

use App\Enums\DemoDataVolume;
use App\Jobs\ProvisionDemoWorkspaceJob;
use App\Models\Customer;
use App\Models\DemoWorkspace;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\Property;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\Reservation;
use App\Models\Role;
use App\Models\ServiceRequest;
use App\Models\Task;
use App\Models\TaskMaterial;
use App\Models\TaskStatusHistory;
use App\Models\TeamMember;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkChecklistItem;
use App\Models\WorkMedia;
use App\Models\WorkRating;
use App\Queries\Demo\DemoScenarioDashboardQuery;
use App\Services\Demo\DemoScenarioInvariantViolationException;
use App\Services\Demo\DemoScenarioRegistry;
use App\Services\Demo\DemoWorkspaceCatalog;
use App\Services\Demo\DemoWorkspaceProvisioner;
use App\Services\Demo\DemoWorkspaceTimelineService;
use App\Services\Demo\Scenarios\BorealProprete\BorealPropreteBlueprint;
use Carbon\CarbonImmutable;

function borealPropreteScenarioIntegrationAdmin(): User
{
    $role = Role::query()->firstOrCreate(
        ['name' => 'superadmin'],
        ['description' => 'Superadmin role'],
    );

    return User::query()->create([
        'name' => 'Boréal Scenario Admin',
        'email' => 'boreal-scenario-admin@example.test',
        'password' => 'password',
        'role_id' => $role->id,
        'onboarding_completed_at' => now(),
    ]);
}

/**
 * @return array<string, mixed>
 */
function borealPropreteScenarioIntegrationPayload(array $overrides = []): array
{
    $catalog = app(DemoWorkspaceCatalog::class);
    $preset = collect($catalog->presets())->firstWhere('key', BorealPropreteBlueprint::KEY);

    if (! is_array($preset)) {
        throw new RuntimeException('The Boréal Propreté demo preset is not registered.');
    }

    return array_replace($catalog->defaults(), [
        'prospect_name' => $preset['prospect_name'],
        'prospect_email' => null,
        'prospect_company' => $preset['prospect_company'],
        'company_name' => $preset['company_name'],
        'company_type' => $preset['company_type'],
        'company_sector' => $preset['company_sector'],
        'seed_profile' => $preset['seed_profile'],
        'scenario_key' => BorealPropreteBlueprint::KEY,
        'data_volume' => DemoDataVolume::Small->value,
        'reference_date' => '2026-08-20',
        'random_seed' => 26082026,
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

/**
 * @param  array<string, int>  $targets
 * @return array<string, int>
 */
function borealPropreteScenarioExpectedCounts(array $targets): array
{
    return [
        'team_members' => $targets['employees'],
        'services' => $targets['services'],
        'products' => $targets['products'],
        'customers' => $targets['customers'],
        'properties' => $targets['properties'],
        'prospects' => $targets['prospects'],
        'service_requests' => $targets['service_requests'],
        'quotes' => $targets['quotes'],
        'works' => $targets['works'],
        'tasks' => $targets['tasks'],
        'work_checklist_items' => $targets['work_checklist_items'],
        'work_media' => $targets['work_media'],
        'task_materials' => $targets['task_materials'],
        'task_status_histories' => $targets['task_status_histories'],
        'work_ratings' => $targets['work_ratings'],
        'invoices' => $targets['invoices'],
        'payments' => $targets['payments'],
        'expenses' => $targets['expenses'],
        'inventory_movements' => $targets['inventory_movements'],
    ];
}

/**
 * @return array<string, int>
 */
function borealPropreteScenarioDatabaseCounts(int $ownerId): array
{
    $customerIds = Customer::query()->byUser($ownerId)->pluck('id');
    $workIds = Work::query()->byUser($ownerId)->pluck('id');
    $taskIds = Task::query()->forAccount($ownerId)->pluck('id');

    return [
        'team_members' => TeamMember::query()->forAccount($ownerId)->count(),
        'services' => Product::query()->byUser($ownerId)->services()->count(),
        'products' => Product::query()->byUser($ownerId)->products()->count(),
        'customers' => $customerIds->count(),
        'properties' => Property::query()->whereIn('customer_id', $customerIds)->count(),
        'prospects' => LeadRequest::query()->byUser($ownerId)->withTrashed()->count(),
        'service_requests' => ServiceRequest::query()->byUser($ownerId)->count(),
        'quotes' => Quote::query()->byUserWithArchived($ownerId)->count(),
        'works' => $workIds->count(),
        'tasks' => $taskIds->count(),
        'work_checklist_items' => WorkChecklistItem::query()->whereIn('work_id', $workIds)->count(),
        'work_media' => WorkMedia::query()->whereIn('work_id', $workIds)->count(),
        'task_materials' => TaskMaterial::query()->whereIn('task_id', $taskIds)->count(),
        'task_status_histories' => TaskStatusHistory::query()->whereIn('task_id', $taskIds)->count(),
        'work_ratings' => WorkRating::query()->whereIn('work_id', $workIds)->count(),
        'invoices' => Invoice::query()->byUser($ownerId)->count(),
        'payments' => Payment::query()->where('user_id', $ownerId)->count(),
        'expenses' => Expense::query()->byAccount($ownerId)->count(),
        'inventory_movements' => ProductStockMovement::query()
            ->whereHas('product', fn ($query) => $query->where('user_id', $ownerId))
            ->count(),
    ];
}

/**
 * @return array<string, mixed>
 */
function borealPropreteScenarioNamedStories(int $ownerId): array
{
    $construction = Customer::query()
        ->byUser($ownerId)
        ->where('company_name', 'Construction Horizon')
        ->firstOrFail();
    $lavoie = Customer::query()
        ->byUser($ownerId)
        ->where('company_name', 'Groupe Lavoie Immeubles')
        ->firstOrFail();
    $elodie = Customer::query()
        ->byUser($ownerId)
        ->where('first_name', 'Élodie')
        ->where('last_name', 'Nguyen')
        ->firstOrFail();
    $atelier = Customer::query()
        ->byUser($ownerId)
        ->where('company_name', 'Atelier Mile End')
        ->firstOrFail();

    $constructionQuote = Quote::query()
        ->byUserWithArchived($ownerId)
        ->where('customer_id', $construction->id)
        ->where('status', 'accepted')
        ->where('total', 7820)
        ->firstOrFail();

    return [
        'construction_horizon' => [
            'accepted_quote_total' => (float) $constructionQuote->total,
            'deposit' => Transaction::query()
                ->where('user_id', $ownerId)
                ->where('quote_id', $constructionQuote->id)
                ->where('type', 'deposit')
                ->where('status', 'completed')
                ->where('reference', 'BOREAL-HORIZON-DEPOT-30')
                ->where('amount', 2346)
                ->count(),
            'partial_invoice' => Invoice::query()
                ->byUser($ownerId)
                ->where('customer_id', $construction->id)
                ->where('status', 'partial')
                ->count(),
            'work_titles' => Work::query()
                ->byUser($ownerId)
                ->where('customer_id', $construction->id)
                ->whereIn('job_title', [
                    'Construction Horizon · Phase 1 — dépoussiérage',
                    'Construction Horizon · Phase 2 — remise finale',
                    'Construction Horizon · Ajout de portée — vitres',
                ])
                ->orderBy('start_date')
                ->pluck('job_title')
                ->all(),
        ],
        'groupe_lavoie_immeubles' => [
            'incident' => Work::query()
                ->byUser($ownerId)
                ->where('customer_id', $lavoie->id)
                ->where('status', Work::STATUS_DISPUTE)
                ->where('job_title', 'Incident hivernal · Résidences Lavoie — site Papineau')
                ->count(),
            'recovery' => Work::query()
                ->byUser($ownerId)
                ->where('customer_id', $lavoie->id)
                ->whereIn('status', Work::COMPLETED_STATUSES)
                ->where('job_title', 'Reprise qualité sous 24 h · Résidences Lavoie')
                ->count(),
        ],
        'elodie_nguyen' => [
            'incident' => Work::query()
                ->byUser($ownerId)
                ->where('customer_id', $elodie->id)
                ->where('status', Work::STATUS_DISPUTE)
                ->where('job_title', 'Déménagement Élodie Nguyen · contrôle incomplet')
                ->count(),
            'validated_recovery' => Work::query()
                ->byUser($ownerId)
                ->where('customer_id', $elodie->id)
                ->where('status', Work::STATUS_VALIDATED)
                ->where('job_title', 'Reprise urgente · Élodie Nguyen')
                ->count(),
            'credit' => Payment::query()
                ->where('user_id', $ownerId)
                ->where('customer_id', $elodie->id)
                ->where('status', Payment::STATUS_REFUNDED)
                ->where('reference', 'BOREAL-ELODIE-CREDIT')
                ->count(),
        ],
        'atelier_mile_end' => [
            'qualified_request' => LeadRequest::query()
                ->byUser($ownerId)
                ->where('customer_id', $atelier->id)
                ->where('status', LeadRequest::STATUS_QUALIFIED)
                ->count(),
            'active_service_request' => ServiceRequest::query()
                ->byUser($ownerId)
                ->where('customer_id', $atelier->id)
                ->where('status', ServiceRequest::STATUS_IN_PROGRESS)
                ->count(),
            'sent_quote' => Quote::query()
                ->byUserWithArchived($ownerId)
                ->where('customer_id', $atelier->id)
                ->where('status', 'sent')
                ->count(),
            'open_follow_up' => Task::query()
                ->forAccount($ownerId)
                ->where('customer_id', $atelier->id)
                ->open()
                ->where('title', 'Relancer Atelier Mile End après le devis')
                ->whereDate('due_date', '2026-08-22')
                ->count(),
        ],
    ];
}

/**
 * @return list<string>
 */
function borealPropreteScenarioHistoricalMonths(int $ownerId, CarbonImmutable $referenceDate): array
{
    return Work::query()
        ->byUser($ownerId)
        ->whereBetween('start_date', [
            $referenceDate->subMonths(11)->startOfMonth()->toDateString(),
            $referenceDate->toDateString(),
        ])
        ->get(['start_date'])
        ->map(fn (Work $work): string => $work->start_date->format('Y-m'))
        ->unique()
        ->sort()
        ->values()
        ->all();
}

/**
 * @param  array<string, mixed>  $overrides
 */
function provisionBorealPropreteScenario(array $overrides = []): DemoWorkspace
{
    try {
        return app(DemoWorkspaceProvisioner::class)->create(
            borealPropreteScenarioIntegrationPayload($overrides),
            borealPropreteScenarioIntegrationAdmin(),
        );
    } catch (DemoScenarioInvariantViolationException $exception) {
        $reportViolations = collect($exception->report()['violations'] ?? []);
        $violations = $reportViolations
            ->countBy(fn (array $violation): string => (string) ($violation['code'] ?? 'unknown'))
            ->all();
        $samples = $reportViolations
            ->unique(fn (array $violation): string => (string) ($violation['code'] ?? 'unknown'))
            ->values()
            ->all();

        throw new RuntimeException(
            $exception->getMessage().' '.json_encode([
                'counts' => $violations,
                'samples' => $samples,
            ], JSON_THROW_ON_ERROR),
            previous: $exception,
        );
    }
}

it('provisions the scaled Boréal Propreté volumes without temporal violations', function (DemoDataVolume $volume) {
    $targets = BorealPropreteBlueprint::targetsForVolume($volume);
    $requiredModules = app(DemoWorkspaceCatalog::class)
        ->requiredModulesForScenario(BorealPropreteBlueprint::KEY);
    $workspace = provisionBorealPropreteScenario([
        'data_volume' => $volume->value,
        'reference_date' => '2026-08-26',
        'selected_modules' => array_values(array_unique([
            ...$requiredModules,
            'reservations',
            'promotions',
            'assistant',
        ])),
    ]);

    expect($workspace->provisioning_status)->toBe(DemoWorkspaceProvisioner::STATUS_READY)
        ->and($workspace->data_volume)->toBe($volume)
        ->and($workspace->selected_modules)->toBe($requiredModules)
        ->and(data_get($workspace->seed_summary, 'invariant_report.violation_count'))->toBe(0)
        ->and(borealPropreteScenarioDatabaseCounts((int) $workspace->owner_user_id))
        ->toBe(borealPropreteScenarioExpectedCounts($targets));
})->with([
    'medium' => [DemoDataVolume::Medium],
    'large' => [DemoDataVolume::Large],
]);

it('provisions and reproducibly resets the real small Boréal Propreté scenario', function () {
    $catalog = app(DemoWorkspaceCatalog::class);
    $registry = app(DemoScenarioRegistry::class);
    $targets = BorealPropreteBlueprint::targetsForVolume(DemoDataVolume::Small);
    $expectedCounts = borealPropreteScenarioExpectedCounts($targets);
    $referenceDate = CarbonImmutable::parse('2026-08-20', 'America/Toronto');
    $expectedMonths = collect(range(0, BorealPropreteBlueprint::HISTORY_MONTHS - 1))
        ->map(fn (int $offset): string => $referenceDate
            ->subMonths(BorealPropreteBlueprint::HISTORY_MONTHS - 1)
            ->addMonths($offset)
            ->format('Y-m'))
        ->all();

    expect($registry->has(BorealPropreteBlueprint::KEY))->toBeTrue()
        ->and($catalog->scenarioDefinition(BorealPropreteBlueprint::KEY))->not->toBeNull()
        ->and($catalog->requiredModulesForScenario(BorealPropreteBlueprint::KEY))->toContain(
            'requests',
            'quotes',
            'jobs',
            'tasks',
            'planning',
            'presence',
            'invoices',
            'expenses',
            'accounting',
            'products',
        );

    $provisioner = app(DemoWorkspaceProvisioner::class);
    $workspace = provisionBorealPropreteScenario();
    $admin = User::query()->where('email', 'boreal-scenario-admin@example.test')->firstOrFail();
    $ownerId = (int) $workspace->owner_user_id;
    $summary = (array) $workspace->seed_summary;
    $fingerprint = (string) data_get($summary, 'dataset_fingerprint');
    $storySnapshot = borealPropreteScenarioNamedStories($ownerId);
    $historicalMonths = borealPropreteScenarioHistoricalMonths($ownerId, $referenceDate);
    $moduleEvidence = collect((array) data_get($summary, 'module_evidence', []));
    $scenarioInsights = app(DemoScenarioDashboardQuery::class)->execute($workspace->owner);

    expect($workspace->provisioning_status)->toBe(DemoWorkspaceProvisioner::STATUS_READY)
        ->and($workspace->scenario_key)->toBe(BorealPropreteBlueprint::KEY)
        ->and($workspace->data_volume)->toBe(DemoDataVolume::Small)
        ->and($workspace->reference_date?->toDateString())->toBe('2026-08-20')
        ->and($workspace->random_seed)->toBe(26082026)
        ->and($workspace->company_name)->toBe('Boréal Propreté Services')
        ->and(data_get($summary, 'history_months'))->toBe(BorealPropreteBlueprint::HISTORY_MONTHS)
        ->and(data_get($summary, 'future_weeks'))->toBe(BorealPropreteBlueprint::FUTURE_WEEKS)
        ->and(data_get($summary, 'invariant_report.violation_count'))->toBe(0)
        ->and($fingerprint)->not->toBe('')
        ->and(strlen($fingerprint))->toBe(64)
        ->and(data_get($summary, 'named_stories'))->toMatchArray([
            'construction_horizon' => 'Construction Horizon',
            'groupe_lavoie_immeubles' => 'Groupe Lavoie Immeubles',
            'elodie_nguyen' => 'Élodie Nguyen',
            'atelier_mile_end' => 'Atelier Mile End',
        ])
        ->and(borealPropreteScenarioDatabaseCounts($ownerId))->toBe($expectedCounts)
        ->and($historicalMonths)->toBe($expectedMonths)
        ->and(Reservation::query()->forAccount($ownerId)->count())->toBe(0)
        ->and(Work::query()
            ->byUser($ownerId)
            ->whereDate('start_date', '>', $referenceDate->toDateString())
            ->whereDate('start_date', '<=', $referenceDate->addWeeks(BorealPropreteBlueprint::FUTURE_WEEKS)->toDateString())
            ->count())->toBeGreaterThan(0)
        ->and($moduleEvidence)->not->toBeEmpty()
        ->and($moduleEvidence->every(fn (array $evidence): bool => $evidence['demonstrable'] === true))->toBeTrue()
        ->and(data_get($moduleEvidence->get('requests'), 'source'))->toBe('requests+service_requests')
        ->and(data_get($moduleEvidence->get('jobs'), 'source'))->toBe('works')
        ->and(data_get($scenarioInsights, 'operating_model'))->toBe('field_operations')
        ->and(data_get($scenarioInsights, 'monthly.labels'))->toHaveCount(12)
        ->and(data_get($scenarioInsights, 'monthly.reservations'))->toHaveCount(12)
        ->and(collect(data_get($scenarioInsights, 'monthly.reservations'))->every(
            fn (int $count): bool => $count > 0,
        ))->toBeTrue()
        ->and((int) data_get($scenarioInsights, 'metrics.reservations_today'))->toBeGreaterThan(0)
        ->and((float) data_get($scenarioInsights, 'metrics.average_service_value'))->toBeGreaterThan(0)
        ->and((float) data_get($scenarioInsights, 'metrics.no_show_rate'))->toBeGreaterThan(0)
        ->and(data_get($scenarioInsights, 'top_services'))->not->toBeEmpty()
        ->and(data_get($scenarioInsights, 'top_employees'))->not->toBeEmpty()
        ->and(data_get($scenarioInsights, 'top_products'))->not->toBeEmpty();

    foreach ($expectedCounts as $key => $expectedCount) {
        expect((int) data_get($summary, $key))->toBe($expectedCount, "Unexpected summary count for [{$key}].");
    }

    expect($storySnapshot)->toBe([
        'construction_horizon' => [
            'accepted_quote_total' => 7820.0,
            'deposit' => 1,
            'partial_invoice' => 1,
            'work_titles' => [
                'Construction Horizon · Phase 1 — dépoussiérage',
                'Construction Horizon · Phase 2 — remise finale',
                'Construction Horizon · Ajout de portée — vitres',
            ],
        ],
        'groupe_lavoie_immeubles' => [
            'incident' => 1,
            'recovery' => 1,
        ],
        'elodie_nguyen' => [
            'incident' => 1,
            'validated_recovery' => 1,
            'credit' => 1,
        ],
        'atelier_mile_end' => [
            'qualified_request' => 1,
            'active_service_request' => 1,
            'sent_quote' => 1,
            'open_follow_up' => 1,
        ],
    ]);

    $queuedReset = $provisioner->queueResetToBaseline($workspace, $admin);
    (new ProvisionDemoWorkspaceJob($queuedReset->id, $admin->id, true))->handle(
        $provisioner,
        app(DemoWorkspaceTimelineService::class),
    );
    $reset = $queuedReset->fresh(['owner']);
    $resetOwnerId = (int) $reset->owner_user_id;

    expect($reset->provisioning_status)->toBe(DemoWorkspaceProvisioner::STATUS_READY)
        ->and($reset->provisioning_error)->toBeNull()
        ->and($resetOwnerId)->not->toBe($ownerId)
        ->and(User::query()->find($ownerId))->toBeNull()
        ->and($reset->scenario_key)->toBe(BorealPropreteBlueprint::KEY)
        ->and($reset->data_volume)->toBe(DemoDataVolume::Small)
        ->and($reset->reference_date?->toDateString())->toBe('2026-08-20')
        ->and($reset->random_seed)->toBe(26082026)
        ->and(data_get($reset->seed_summary, 'dataset_fingerprint'))->toBe($fingerprint)
        ->and(borealPropreteScenarioDatabaseCounts($resetOwnerId))->toBe($expectedCounts)
        ->and(borealPropreteScenarioNamedStories($resetOwnerId))->toBe($storySnapshot)
        ->and(borealPropreteScenarioHistoricalMonths($resetOwnerId, $referenceDate))->toBe($expectedMonths)
        ->and(data_get($reset->seed_summary, 'invariant_report.violation_count'))->toBe(0);
});
