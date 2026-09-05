<?php

namespace App\Services\Demo\Scenarios\BorealProprete;

use App\Enums\DemoDataVolume;
use App\Models\AccountingEntryBatch;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\Property;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\ServiceRequest;
use App\Models\Task;
use App\Models\TaskMaterial;
use App\Models\TaskStatusHistory;
use App\Models\TeamMember;
use App\Models\Transaction;
use App\Models\Work;
use App\Models\WorkChecklistItem;
use App\Models\WorkMedia;
use App\Models\WorkRating;
use App\Services\Demo\Contracts\DemoScenario;
use App\Services\Demo\DemoScenarioContext;
use App\Services\Demo\DemoScenarioFingerprint;
use App\Services\Demo\DemoScenarioInvariantValidator;
use App\Services\Demo\DemoScenarioModuleEvidence;
use App\Services\Demo\Generators\DemoFieldOperationsGenerator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class BorealPropreteScenario implements DemoScenario
{
    public function __construct(
        private readonly DemoFieldOperationsGenerator $fieldOperationsGenerator,
        private readonly DemoScenarioInvariantValidator $invariantValidator,
        private readonly DemoScenarioModuleEvidence $moduleEvidence,
        private readonly DemoScenarioFingerprint $fingerprint,
    ) {}

    public function key(): string
    {
        return BorealPropreteBlueprint::KEY;
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
        $blueprint = BorealPropreteBlueprint::definition();
        $targets = BorealPropreteBlueprint::targetsForVolume($context->dataVolume);

        return DB::transaction(function () use ($context, $blueprint, $targets): array {
            $generated = $this->fieldOperationsGenerator->generate($context, $blueprint, $targets);
            $counts = $this->databaseCounts($context);
            $this->assertTargets($targets, $counts);
            $this->assertCleaningNarrative($context);

            $owner = $context->owner->fresh();
            if (! $owner) {
                throw new RuntimeException('Boréal Propreté owner disappeared before final validation.');
            }

            $invariants = $this->invariantValidator->validateOrFail($owner, $context->referenceDate);
            $moduleEvidence = $this->moduleEvidence->validateOrFail(
                $owner,
                array_values((array) $context->workspace->selected_modules),
            );
            $datasetFingerprint = $this->fingerprint->forOwner($owner);

            return [
                'scenario_key' => $this->key(),
                'scenario_version' => $this->version(),
                'data_volume' => $context->dataVolume->value,
                'reference_date' => $context->referenceDate->toDateString(),
                'random_seed' => $context->randomSeed,
                'timezone' => $context->timezone,
                'history_months' => BorealPropreteBlueprint::HISTORY_MONTHS,
                'future_weeks' => BorealPropreteBlueprint::FUTURE_WEEKS,
                ...$counts,
                'transactions' => $generated['transactions']->count(),
                'notifications' => $generated['notifications'],
                'accounting_sync' => $generated['accounting'],
                'named_stories' => collect((array) $blueprint['client_stories'])
                    ->mapWithKeys(fn (array $story): array => [
                        (string) $story['key'] => (string) $story['name'],
                    ])
                    ->all(),
                'invariant_report' => $invariants['summary'],
                'module_evidence' => $moduleEvidence,
                'dataset_fingerprint' => $datasetFingerprint,
            ];
        });
    }

    /**
     * @return array<string, int>
     */
    private function databaseCounts(DemoScenarioContext $context): array
    {
        $ownerId = (int) $context->owner->id;
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
            'accounting_batches' => AccountingEntryBatch::query()->forUser($ownerId)->count(),
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
            'properties' => 'properties',
            'prospects' => 'prospects',
            'service_requests' => 'service_requests',
            'quotes' => 'quotes',
            'works' => 'works',
            'tasks' => 'tasks',
            'work_checklist_items' => 'work_checklist_items',
            'work_media' => 'work_media',
            'task_materials' => 'task_materials',
            'task_status_histories' => 'task_status_histories',
            'work_ratings' => 'work_ratings',
            'invoices' => 'invoices',
            'payments' => 'payments',
            'expenses' => 'expenses',
            'inventory_movements' => 'inventory_movements',
        ];

        foreach ($mapping as $targetKey => $countKey) {
            if ((int) ($targets[$targetKey] ?? -1) === (int) ($counts[$countKey] ?? -2)) {
                continue;
            }

            throw new RuntimeException(sprintf(
                'Boréal Propreté target mismatch for %s: expected %d, generated %d.',
                $targetKey,
                (int) ($targets[$targetKey] ?? -1),
                (int) ($counts[$countKey] ?? -2),
            ));
        }
    }

    private function assertCleaningNarrative(DemoScenarioContext $context): void
    {
        $ownerId = (int) $context->owner->id;
        $customers = Customer::query()
            ->byUser($ownerId)
            ->whereIn('company_name', [
                'Groupe Lavoie Immeubles',
                'Construction Horizon',
                'Atelier Mile End',
            ])
            ->get()
            ->keyBy('company_name');
        $construction = $customers->get('Construction Horizon');
        $atelier = $customers->get('Atelier Mile End');
        $lavoie = $customers->get('Groupe Lavoie Immeubles');
        $elodie = Customer::query()
            ->byUser($ownerId)
            ->where('first_name', 'Élodie')
            ->where('last_name', 'Nguyen')
            ->first();

        $constructionQuote = $construction
            ? Quote::query()->byUserWithArchived($ownerId)
                ->where('customer_id', $construction->id)
                ->where('status', 'accepted')
                ->where('total', 7820)
                ->first()
            : null;
        $constructionDeposit = $constructionQuote
            ? Transaction::query()
                ->where('quote_id', $constructionQuote->id)
                ->where('type', 'deposit')
                ->where('status', 'completed')
                ->where('amount', 2346)
                ->exists()
            : false;
        $constructionPartialInvoice = $construction
            ? Invoice::query()->byUser($ownerId)
                ->where('customer_id', $construction->id)
                ->where('status', 'partial')
                ->exists()
            : false;
        $atelierOpenQuote = $atelier
            ? Quote::query()->byUserWithArchived($ownerId)
                ->where('customer_id', $atelier->id)
                ->where('status', 'sent')
                ->exists()
            : false;
        $atelierFollowUp = $atelier
            ? Task::query()->forAccount($ownerId)
                ->where('customer_id', $atelier->id)
                ->open()
                ->where('title', 'like', '%Atelier Mile End%')
                ->exists()
            : false;
        $lavoieIncident = $lavoie
            ? Work::query()->byUser($ownerId)
                ->where('customer_id', $lavoie->id)
                ->where('status', Work::STATUS_DISPUTE)
                ->exists()
            : false;
        $lavoieRecovery = $lavoie
            ? Work::query()->byUser($ownerId)
                ->where('customer_id', $lavoie->id)
                ->where('job_title', 'like', '%Reprise qualité%')
                ->whereIn('status', Work::COMPLETED_STATUSES)
                ->exists()
            : false;
        $elodieCredit = $elodie
            ? Payment::query()
                ->where('user_id', $ownerId)
                ->where('customer_id', $elodie->id)
                ->where('status', Payment::STATUS_REFUNDED)
                ->where('reference', 'BOREAL-ELODIE-CREDIT')
                ->exists()
            : false;
        $workMonths = Work::query()
            ->byUser($ownerId)
            ->whereBetween('start_date', [
                $context->referenceDate->subMonths(11)->startOfMonth()->toDateString(),
                $context->referenceDate->toDateString(),
            ])
            ->get(['start_date'])
            ->map(fn (Work $work): string => $work->start_date->format('Y-m'))
            ->unique()
            ->count();

        if (
            ! $constructionQuote
            || ! $constructionDeposit
            || ! $constructionPartialInvoice
            || ! $atelierOpenQuote
            || ! $atelierFollowUp
            || ! $lavoieIncident
            || ! $lavoieRecovery
            || ! $elodieCredit
            || $workMonths !== 12
        ) {
            throw new RuntimeException('Boréal Propreté narrative links are incomplete or historically inconsistent.');
        }
    }
}
