<?php

namespace App\Services\Demo\Scenarios\StudioNaya;

use App\Enums\DemoDataVolume;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\Quote;
use App\Models\Reservation;
use App\Models\Sale;
use App\Models\Task;
use App\Models\TeamMember;
use App\Models\Transaction;
use App\Services\Demo\Contracts\DemoScenario;
use App\Services\Demo\DemoScenarioContext;
use App\Services\Demo\DemoScenarioFingerprint;
use App\Services\Demo\DemoScenarioInvariantValidator;
use App\Services\Demo\Generators\DemoCommerceGenerator;
use App\Services\Demo\Generators\DemoCustomerGenerator;
use App\Services\Demo\Generators\DemoReservationGenerator;
use App\Services\Demo\Generators\DemoTeamCatalogGenerator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class StudioNayaScenario implements DemoScenario
{
    public function __construct(
        private readonly DemoTeamCatalogGenerator $teamCatalogGenerator,
        private readonly DemoCustomerGenerator $customerGenerator,
        private readonly DemoReservationGenerator $reservationGenerator,
        private readonly DemoCommerceGenerator $commerceGenerator,
        private readonly DemoScenarioInvariantValidator $invariantValidator,
        private readonly DemoScenarioFingerprint $fingerprint,
    ) {}

    public function key(): string
    {
        return StudioNayaBlueprint::KEY;
    }

    public function version(): int
    {
        return 1;
    }

    public function defaultVolume(): DemoDataVolume
    {
        return DemoDataVolume::Medium;
    }

    /**
     * @return array<string, mixed>
     */
    public function generate(DemoScenarioContext $context): array
    {
        $blueprint = StudioNayaBlueprint::definition();
        $targets = $this->targets($context->dataVolume);

        return DB::transaction(function () use ($context, $blueprint, $targets): array {
            $resources = $this->teamCatalogGenerator->generate($context, $blueprint, $targets);
            $customers = $this->customerGenerator->generate(
                $context,
                $blueprint,
                (int) $targets['customers'],
            );
            $reservations = $this->reservationGenerator->generate(
                $context,
                $blueprint,
                $customers['customers'],
                $customers['customers_by_story'],
                $resources['team_members'],
                $resources['services'],
                (int) $targets['reservations'],
            );
            $commerce = $this->commerceGenerator->generate(
                $context,
                $blueprint,
                $targets,
                $customers['customers'],
                $customers['customers_by_story'],
                $resources['team_members'],
                $resources['services'],
                $resources['products'],
                $reservations['reservations'],
            );
            $publicBookingLinks = (int) $reservations['public_booking_links'];
            $refunds = (int) $commerce['refunds'];
            $deposits = (int) $commerce['deposits'];

            // MEDIUM and LARGE intentionally create thousands of records. Drop
            // generation graphs before the validator reloads a clean tenant
            // snapshot so queued provisioning stays within a modest worker
            // memory budget.
            unset($resources, $customers, $reservations, $commerce);
            gc_collect_cycles();

            $counts = $this->databaseCounts($context);
            $this->assertTargets($targets, $counts);
            $invariants = $this->invariantValidator->validateOrFail(
                $context->owner->fresh(),
                $context->referenceDate,
            );

            return [
                'scenario_key' => $this->key(),
                'scenario_version' => $this->version(),
                'data_volume' => $context->dataVolume->value,
                'reference_date' => $context->referenceDate->toDateString(),
                'random_seed' => $context->randomSeed,
                'timezone' => $context->timezone,
                ...$counts,
                'public_booking_links' => $publicBookingLinks,
                'refunds' => $refunds,
                'deposits' => $deposits,
                'invariant_report' => $invariants['summary'],
                'dataset_fingerprint' => $this->fingerprint->forOwner($context->owner->fresh()),
            ];
        });
    }

    /**
     * @return array<string, int>
     */
    private function targets(DemoDataVolume $volume): array
    {
        $targets = config('demo_scenarios.volumes.'.$volume->value);
        if (! is_array($targets)) {
            throw new RuntimeException('Missing demo scenario volume configuration for ['.$volume->value.'].');
        }

        return collect($targets)
            ->map(fn (mixed $value): int => (int) $value)
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function databaseCounts(DemoScenarioContext $context): array
    {
        $ownerId = (int) $context->owner->id;

        return [
            'team_members' => TeamMember::query()->forAccount($ownerId)->count(),
            'services' => Product::query()->byUser($ownerId)->services()->count(),
            'products' => Product::query()->byUser($ownerId)->products()->count(),
            'customers' => Customer::query()->byUser($ownerId)->count(),
            'reservations' => Reservation::query()->forAccount($ownerId)->count(),
            'invoices' => Invoice::query()->byUser($ownerId)->count(),
            'payments' => Payment::query()->where('user_id', $ownerId)->count(),
            'quotes' => Quote::query()->byUserWithArchived($ownerId)->count(),
            'sales' => Sale::query()->where('user_id', $ownerId)->count(),
            'expenses' => Expense::query()->byAccount($ownerId)->count(),
            'inventory_movements' => ProductStockMovement::query()
                ->whereHas('product', fn ($query) => $query->where('user_id', $ownerId))
                ->count(),
            'notifications' => $context->owner->notifications()->count(),
            'tasks' => Task::query()->forAccount($ownerId)->count(),
            'transactions' => Transaction::query()->where('user_id', $ownerId)->count(),
        ];
    }

    /**
     * @param  array<string, int>  $targets
     * @param  array<string, int>  $counts
     */
    private function assertTargets(array $targets, array $counts): void
    {
        $mapping = [
            'employees' => 'team_members',
            'services' => 'services',
            'products' => 'products',
            'customers' => 'customers',
            'reservations' => 'reservations',
            'invoices' => 'invoices',
            'payments' => 'payments',
            'quotes' => 'quotes',
            'sales' => 'sales',
            'expenses' => 'expenses',
            'inventory_movements' => 'inventory_movements',
            'notifications' => 'notifications',
        ];

        foreach ($mapping as $targetKey => $countKey) {
            if ((int) ($targets[$targetKey] ?? -1) === (int) ($counts[$countKey] ?? -2)) {
                continue;
            }

            throw new RuntimeException(sprintf(
                'Studio Naya target mismatch for %s: expected %d, generated %d.',
                $targetKey,
                (int) ($targets[$targetKey] ?? -1),
                (int) ($counts[$countKey] ?? -2),
            ));
        }
    }
}
