<?php

namespace App\Services\Demo;

use App\Models\AccountingEntryBatch;
use App\Models\Campaign;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\LoyaltyPointLedger;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\Reservation;
use App\Models\Sale;
use App\Models\ServiceRequest;
use App\Models\SocialPost;
use App\Models\Task;
use App\Models\TeamMember;
use App\Models\TeamMemberAttendance;
use App\Models\TeamMemberShift;
use App\Models\User;
use App\Models\WeeklyAvailability;
use App\Models\Work;
use App\Modules\AiAssistant\Models\AiAssistantSetting;
use App\Modules\AiAssistant\Models\AiConversation;
use App\Modules\AiAssistant\Models\AiKnowledgeItem;
use RuntimeException;

final class DemoScenarioModuleEvidence
{
    /** @var array<string, string> */
    private const MODULE_ROUTES = [
        'services' => 'service.index',
        'reservations' => 'reservation.index',
        'planning' => 'planning.index',
        'presence' => 'presence.index',
        'invoices' => 'invoice.index',
        'expenses' => 'expense.index',
        'accounting' => 'accounting.index',
        'team_members' => 'team.index',
        'performance' => 'performance.index',
        'products' => 'product.index',
        'sales' => 'sales.index',
        'quotes' => 'quote.index',
        'requests' => 'service-requests.index',
        'jobs' => 'jobs.index',
        'tasks' => 'task.index',
        'loyalty' => 'loyalty.index',
        'campaigns' => 'campaigns.index',
        'promotions' => 'promotions.index',
        'assistant' => 'admin.ai-assistant.settings.edit',
        'social' => 'social.index',
    ];

    /**
     * Resolve persisted evidence for every selected module.
     *
     * An unsupported module intentionally fails closed: adding a module to a
     * scenario must also define how its usefulness is demonstrated.
     *
     * @param  array<int, string>  $modules
     * @return array<string, array{records:int, source:string, demonstrable:bool}>
     */
    public function inspect(User $owner, array $modules): array
    {
        $resolvers = $this->resolvers($owner);
        $evidence = [];

        foreach (collect($modules)->map(fn (mixed $module): string => trim((string) $module))->filter()->unique() as $module) {
            $resolver = $resolvers[$module] ?? null;
            $resolved = $resolver ? $resolver() : ['records' => 0, 'source' => 'unsupported'];
            $records = max(0, (int) ($resolved['records'] ?? 0));

            $evidence[$module] = [
                'records' => $records,
                'source' => (string) ($resolved['source'] ?? 'unknown'),
                'demonstrable' => $records > 0,
            ];
        }

        return $evidence;
    }

    /**
     * @param  array<int, string>  $modules
     * @return array<string, array{records:int, source:string, demonstrable:bool}>
     */
    public function validateOrFail(User $owner, array $modules): array
    {
        $evidence = $this->inspect($owner, $modules);
        $missing = collect($evidence)
            ->reject(fn (array $item): bool => $item['demonstrable'])
            ->keys()
            ->values()
            ->all();

        if ($missing !== []) {
            throw new RuntimeException('Demo modules without demonstrable data: '.implode(', ', $missing).'.');
        }

        return $evidence;
    }

    /**
     * @param  array<int, string>  $modules
     * @return array<string, string|null>
     */
    public function routeNames(array $modules): array
    {
        $routes = [];

        foreach (collect($modules)->map(fn (mixed $module): string => trim((string) $module))->filter()->unique() as $module) {
            $routes[$module] = self::MODULE_ROUTES[$module] ?? null;
        }

        return $routes;
    }

    /**
     * @return array<string, callable(): array{records:int, source:string}>
     */
    private function resolvers(User $owner): array
    {
        $ownerId = (int) $owner->id;

        return [
            'services' => fn (): array => $this->result(
                Product::query()->byUser($ownerId)->services()->count(),
                'products.services',
            ),
            'reservations' => fn (): array => $this->result(
                Reservation::query()->forAccount($ownerId)->count(),
                'reservations',
            ),
            'planning' => fn (): array => $this->result(
                WeeklyAvailability::query()->where('account_id', $ownerId)->count()
                    + TeamMemberShift::query()->where('account_id', $ownerId)->count(),
                'weekly_availabilities+team_member_shifts',
            ),
            'presence' => fn (): array => $this->result(
                TeamMemberAttendance::query()->where('account_id', $ownerId)->count(),
                'team_member_attendances',
            ),
            'invoices' => fn (): array => $this->result(
                Invoice::query()->byUser($ownerId)->count(),
                'invoices',
            ),
            'expenses' => fn (): array => $this->result(
                Expense::query()->where('user_id', $ownerId)->count(),
                'expenses',
            ),
            'accounting' => fn (): array => $this->result(
                AccountingEntryBatch::query()->forUser($ownerId)->count(),
                'accounting_entry_batches',
            ),
            'team_members' => fn (): array => $this->result(
                TeamMember::query()->forAccount($ownerId)->count(),
                'team_members',
            ),
            'performance' => fn (): array => $this->performanceEvidence($owner),
            'products' => fn (): array => $this->result(
                Product::query()->byUser($ownerId)->products()->count(),
                'products.catalog',
            ),
            'sales' => fn (): array => $this->result(
                Sale::query()->where('user_id', $ownerId)->where('status', 'paid')->count(),
                'sales.paid',
            ),
            'quotes' => fn (): array => $this->result(
                Quote::query()->byUserWithArchived($ownerId)->count(),
                'quotes',
            ),
            'requests' => fn (): array => $this->result(
                LeadRequest::query()->byUser($ownerId)->count()
                    + ServiceRequest::query()->byUser($ownerId)->count(),
                'requests+service_requests',
            ),
            'jobs' => fn (): array => $this->result(
                Work::query()->byUser($ownerId)->count(),
                'works',
            ),
            'tasks' => fn (): array => $this->result(
                Task::query()->forAccount($ownerId)->count(),
                'tasks',
            ),
            'loyalty' => fn (): array => $this->result(
                LoyaltyPointLedger::query()->where('user_id', $ownerId)->count(),
                'loyalty_point_ledgers',
            ),
            'campaigns' => fn (): array => $this->result(
                Campaign::query()->where('user_id', $ownerId)->count(),
                'campaigns',
            ),
            'promotions' => fn (): array => $this->result(
                Promotion::query()->forAccount($ownerId)->count(),
                'promotions',
            ),
            'assistant' => fn (): array => $this->result(
                AiAssistantSetting::query()->forTenant($ownerId)->count()
                    + AiKnowledgeItem::query()->forTenant($ownerId)->count()
                    + AiConversation::query()->forTenant($ownerId)->count(),
                'ai_settings+knowledge+conversations',
            ),
            'social' => fn (): array => $this->result(
                SocialPost::query()->byUser($ownerId)->count(),
                'social_posts',
            ),
        ];
    }

    /**
     * @return array{records:int, source:string}
     */
    private function performanceEvidence(User $owner): array
    {
        $ownerId = (int) $owner->id;

        if ($owner->hasCompanyFeature('reservations')) {
            return $this->result(
                Reservation::query()
                    ->forAccount($ownerId)
                    ->where('status', Reservation::STATUS_COMPLETED)
                    ->count(),
                'reservations.completed',
            );
        }

        if ($owner->hasCompanyFeature('jobs') || $owner->hasCompanyFeature('tasks')) {
            return $this->result(
                Work::query()->where('user_id', $ownerId)->count()
                    + Task::query()->forAccount($ownerId)->count(),
                'works+tasks',
            );
        }

        if ($owner->hasCompanyFeature('sales')) {
            return $this->result(
                Sale::query()->where('user_id', $ownerId)->where('status', 'paid')->count(),
                'sales.paid',
            );
        }

        return $this->result(0, 'no_performance_capability');
    }

    /**
     * @return array{records:int, source:string}
     */
    private function result(int $records, string $source): array
    {
        return ['records' => $records, 'source' => $source];
    }
}
