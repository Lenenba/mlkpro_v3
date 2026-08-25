<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\PlanScan;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\Sale;
use App\Models\ServiceRequest;
use App\Models\Task;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Work;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class WorkspaceBreadcrumbEntityService
{
    /** @var list<string> */
    private const TYPES = [
        'customer',
        'prospect',
        'service_request',
        'quote',
        'sale',
        'campaign',
        'employee',
        'work',
        'task',
        'invoice',
        'expense',
        'product',
        'plan_scan',
    ];

    /**
     * @return array{
     *     type: string,
     *     query: string,
     *     items: list<array{key: string, label: string, href: string}>,
     *     has_more: bool
     * }
     */
    public function search(User $actor, string $type, string $query, int $limit): array
    {
        if (! in_array($type, self::TYPES, true)) {
            abort(404);
        }

        [$owner, $accountId, $membership] = $this->resolveAccountContext($actor);

        $result = match ($type) {
            'customer' => $this->customers($actor, $accountId, $query, $limit),
            'prospect' => $this->prospects($actor, $owner, $membership, $accountId, $query, $limit),
            'service_request' => $this->serviceRequests($actor, $owner, $membership, $accountId, $query, $limit),
            'quote' => $this->quotes($actor, $owner, $accountId, $query, $limit),
            'sale' => $this->sales($actor, $owner, $membership, $accountId, $query, $limit),
            'campaign' => $this->campaigns($actor, $owner, $membership, $accountId, $query, $limit),
            'employee' => $this->employees($actor, $owner, $membership, $accountId, $query, $limit),
            'work' => $this->works($actor, $owner, $membership, $accountId, $query, $limit),
            'task' => $this->tasks($actor, $owner, $membership, $accountId, $query, $limit),
            'invoice' => $this->invoices($actor, $owner, $accountId, $query, $limit),
            'expense' => $this->expenses($actor, $owner, $accountId, $query, $limit),
            'product' => $this->products($actor, $owner, $accountId, $query, $limit),
            'plan_scan' => $this->planScans($actor, $owner, $accountId, $query, $limit),
        };

        return [
            'type' => $type,
            'query' => $query,
            'items' => $result['items'],
            'has_more' => $result['has_more'],
        ];
    }

    /**
     * @return array{0: User, 1: int, 2: ?TeamMember}
     */
    private function resolveAccountContext(User $actor): array
    {
        if ($actor->isClient() || $actor->isSuperadmin() || $actor->isPlatformAdmin()) {
            abort(403);
        }

        $accountId = (int) $actor->accountOwnerId();
        if ($accountId <= 0) {
            abort(403);
        }

        $owner = (int) $actor->id === $accountId
            ? $actor
            : User::query()->find($accountId);
        if (! $owner) {
            abort(403);
        }

        $membership = null;
        if ((int) $actor->id !== $accountId) {
            $membership = TeamMember::query()
                ->forAccount($accountId)
                ->active()
                ->where('user_id', $actor->id)
                ->with('companyRole.permissions')
                ->first();

            if (! $membership) {
                abort(403);
            }

            $actor->setRelation('teamMembership', $membership);
        }

        return [$owner, $accountId, $membership];
    }

    /**
     * @return array{items: list<array{key: string, label: string, href: string}>, has_more: bool}
     */
    private function customers(User $actor, int $accountId, string $search, int $limit): array
    {
        $customerProbe = new Customer(['user_id' => $accountId]);
        if (! Gate::forUser($actor)->allows('view', $customerProbe)) {
            abort(403);
        }

        $query = Customer::query()
            ->byUser($accountId)
            ->select(['id', 'number', 'company_name', 'first_name', 'last_name']);
        $this->applySearch($query, ['number', 'company_name', 'first_name', 'last_name'], $search);

        return $this->collectItems(
            $query
                ->orderBy('company_name')
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->orderBy('id'),
            $limit,
            fn (Customer $customer): array => $this->item(
                'customer',
                (int) $customer->id,
                $this->label(
                    (int) $customer->id,
                    $customer->company_name,
                    trim((string) $customer->first_name.' '.(string) $customer->last_name),
                ),
                route('customer.show', ['customer' => $customer->id]),
            ),
        );
    }

    /**
     * @return array{items: list<array{key: string, label: string, href: string}>, has_more: bool}
     */
    private function prospects(
        User $actor,
        User $owner,
        ?TeamMember $membership,
        int $accountId,
        string $search,
        int $limit,
    ): array {
        $this->requireFeatures($owner, ['requests']);
        $this->requireOwnerOrPermissions($actor, $membership, $accountId, ['sales.manage']);

        $query = LeadRequest::query()
            ->byUser($accountId)
            ->select(['id', 'title', 'service_type', 'contact_name']);
        $this->applySearch($query, ['title', 'service_type', 'contact_name'], $search);

        return $this->collectItems(
            $query->orderByDesc('updated_at')->orderByDesc('id'),
            $limit,
            fn (LeadRequest $prospect): array => $this->item(
                'request',
                (int) $prospect->id,
                $this->label(
                    (int) $prospect->id,
                    $prospect->title,
                    $prospect->service_type,
                    $prospect->contact_name,
                ),
                route('prospects.show', ['lead' => $prospect->id]),
            ),
        );
    }

    /**
     * @return array{items: list<array{key: string, label: string, href: string}>, has_more: bool}
     */
    private function serviceRequests(
        User $actor,
        User $owner,
        ?TeamMember $membership,
        int $accountId,
        string $search,
        int $limit,
    ): array {
        $this->requireFeatures($owner, ['requests']);
        $this->requireOwnerOrPermissions($actor, $membership, $accountId, ['sales.manage']);

        $query = ServiceRequest::query()
            ->byUser($accountId)
            ->select(['id', 'title', 'service_type', 'requester_name']);
        $this->applySearch($query, ['title', 'service_type', 'requester_name'], $search);

        return $this->collectItems(
            $query->orderByDesc('submitted_at')->orderByDesc('id'),
            $limit,
            fn (ServiceRequest $serviceRequest): array => $this->item(
                'service-request',
                (int) $serviceRequest->id,
                $this->label(
                    (int) $serviceRequest->id,
                    $serviceRequest->title,
                    $serviceRequest->service_type,
                    $serviceRequest->requester_name,
                ),
                route('service-requests.show', ['serviceRequest' => $serviceRequest->id]),
            ),
        );
    }

    /**
     * @return array{items: list<array{key: string, label: string, href: string}>, has_more: bool}
     */
    private function quotes(User $actor, User $owner, int $accountId, string $search, int $limit): array
    {
        $this->requireFeatures($owner, ['quotes']);

        $quoteProbe = new Quote(['user_id' => $accountId]);
        if (! Gate::forUser($actor)->allows('show', $quoteProbe)) {
            abort(403);
        }

        $query = Quote::query()
            ->byUser($accountId)
            ->select(['id', 'number', 'job_title']);
        $this->applySearch($query, ['number', 'job_title'], $search);

        return $this->collectItems(
            $query->orderByDesc('updated_at')->orderByDesc('id'),
            $limit,
            fn (Quote $quote): array => $this->item(
                'quote',
                (int) $quote->id,
                $this->label((int) $quote->id, $quote->number, $quote->job_title),
                route('customer.quote.show', ['quote' => $quote->id]),
            ),
        );
    }

    /**
     * @return array{items: list<array{key: string, label: string, href: string}>, has_more: bool}
     */
    private function sales(
        User $actor,
        User $owner,
        ?TeamMember $membership,
        int $accountId,
        string $search,
        int $limit,
    ): array {
        $this->requireFeatures($owner, ['sales']);
        $this->requireOwnerOrPermissions($actor, $membership, $accountId, ['sales.manage', 'sales.pos']);

        $query = Sale::query()
            ->where('user_id', $accountId)
            ->select(['id', 'number']);
        $this->applySearch($query, ['number'], $search);

        return $this->collectItems(
            $query->orderByDesc('updated_at')->orderByDesc('id'),
            $limit,
            fn (Sale $sale): array => $this->item(
                'sale',
                (int) $sale->id,
                $this->label((int) $sale->id, $sale->number),
                route('sales.show', ['sale' => $sale->id]),
            ),
        );
    }

    /**
     * @return array{items: list<array{key: string, label: string, href: string}>, has_more: bool}
     */
    private function campaigns(
        User $actor,
        User $owner,
        ?TeamMember $membership,
        int $accountId,
        string $search,
        int $limit,
    ): array {
        $this->requireFeatures($owner, ['campaigns']);
        $this->requireOwnerOrPermissions($actor, $membership, $accountId, [
            'campaigns.manage',
            'sales.manage',
            'campaigns.send',
            'campaigns.view',
        ]);

        $query = Campaign::query()
            ->byUser($accountId)
            ->select(['id', 'name']);
        $this->applySearch($query, ['name'], $search);

        return $this->collectItems(
            $query->orderByDesc('updated_at')->orderByDesc('id'),
            $limit,
            fn (Campaign $campaign): array => $this->item(
                'campaign',
                (int) $campaign->id,
                $this->label((int) $campaign->id, $campaign->name),
                route('campaigns.show', ['campaign' => $campaign->id]),
            ),
        );
    }

    /**
     * @return array{items: list<array{key: string, label: string, href: string}>, has_more: bool}
     */
    private function employees(
        User $actor,
        User $owner,
        ?TeamMember $membership,
        int $accountId,
        string $search,
        int $limit,
    ): array {
        $this->requireFeatures($owner, ['performance']);
        $performanceMode = $this->resolvePerformanceMode($owner);
        $canViewTeam = (int) $actor->id === $accountId;

        if (! $canViewTeam) {
            if (! $membership) {
                abort(403);
            }

            $canViewTeam = $this->canViewTeamPerformance($membership, $performanceMode);
            if (! $canViewTeam && ! $this->canViewOwnPerformance($membership, $performanceMode)) {
                abort(403);
            }
        }

        $query = User::query()->select(['id', 'name']);
        if ($canViewTeam) {
            $query->where(function (Builder $userQuery) use ($accountId): void {
                $userQuery->whereKey($accountId)
                    ->orWhereHas('teamMembership', function (Builder $membershipQuery) use ($accountId): void {
                        $membershipQuery
                            ->where('account_id', $accountId)
                            ->where('is_active', true);
                    });
            });
        } else {
            $query->whereKey($actor->id);
        }
        $this->applySearch($query, ['name'], $search);

        return $this->collectItems(
            $query->orderBy('name')->orderBy('id'),
            $limit,
            fn (User $employee): array => $this->item(
                'employee',
                (int) $employee->id,
                $this->label((int) $employee->id, $employee->name),
                route('performance.employee.show', ['employee' => $employee->id]),
            ),
        );
    }

    /**
     * @return array{items: list<array{key: string, label: string, href: string}>, has_more: bool}
     */
    private function works(
        User $actor,
        User $owner,
        ?TeamMember $membership,
        int $accountId,
        string $search,
        int $limit,
    ): array {
        $this->requireFeatures($owner, ['jobs']);

        $query = Work::query()
            ->byUser($accountId)
            ->select(['id', 'number', 'job_title']);

        if ((int) $actor->id !== $accountId) {
            if (! $membership || ! $this->hasAnyPermission($membership, ['jobs.view', 'jobs.edit'])) {
                abort(403);
            }

            $query->whereHas(
                'teamMembers',
                fn (Builder $teamQuery): Builder => $teamQuery->whereKey($membership->id),
            );
        }

        $this->applySearch($query, ['number', 'job_title'], $search);

        return $this->collectItems(
            $query->orderByDesc('updated_at')->orderByDesc('id'),
            $limit,
            fn (Work $work): array => $this->item(
                'work',
                (int) $work->id,
                $this->label((int) $work->id, $work->number, $work->job_title),
                route('work.show', ['work' => $work->id]),
            ),
        );
    }

    /**
     * @return array{items: list<array{key: string, label: string, href: string}>, has_more: bool}
     */
    private function tasks(
        User $actor,
        User $owner,
        ?TeamMember $membership,
        int $accountId,
        string $search,
        int $limit,
    ): array {
        $this->requireFeatures($owner, ['tasks']);

        $query = Task::query()
            ->forAccount($accountId)
            ->select(['id', 'title']);

        if ((int) $actor->id !== $accountId) {
            if (! $membership || ! $this->hasAnyPermission($membership, ['tasks.view', 'tasks.edit'])) {
                abort(403);
            }

            if ($membership->role !== 'admin') {
                $query->where('assigned_team_member_id', $membership->id);
            }
        }

        $this->applySearch($query, ['title'], $search);

        return $this->collectItems(
            $query->orderByDesc('updated_at')->orderByDesc('id'),
            $limit,
            fn (Task $task): array => $this->item(
                'task',
                (int) $task->id,
                $this->label((int) $task->id, $task->title),
                route('task.show', ['task' => $task->id]),
            ),
        );
    }

    /**
     * @return array{items: list<array{key: string, label: string, href: string}>, has_more: bool}
     */
    private function invoices(User $actor, User $owner, int $accountId, string $search, int $limit): array
    {
        $this->requireFeatures($owner, ['invoices']);
        if (! Gate::forUser($actor)->allows('viewAny', Invoice::class)) {
            abort(403);
        }

        $query = Invoice::query()
            ->byUser($accountId)
            ->select(['id', 'number']);
        $this->applySearch($query, ['number'], $search);

        return $this->collectItems(
            $query->orderByDesc('updated_at')->orderByDesc('id'),
            $limit,
            fn (Invoice $invoice): array => $this->item(
                'invoice',
                (int) $invoice->id,
                $this->label((int) $invoice->id, $invoice->number),
                route('invoice.show', ['invoice' => $invoice->id]),
            ),
        );
    }

    /**
     * @return array{items: list<array{key: string, label: string, href: string}>, has_more: bool}
     */
    private function expenses(User $actor, User $owner, int $accountId, string $search, int $limit): array
    {
        $this->requireFeatures($owner, ['expenses']);
        if (! Gate::forUser($actor)->allows('viewAny', Expense::class)) {
            abort(403);
        }

        $query = Expense::query()
            ->byAccount($accountId)
            ->select(['id', 'reference_number', 'title', 'supplier_name']);
        $this->applySearch($query, ['reference_number', 'title', 'supplier_name'], $search);

        return $this->collectItems(
            $query->orderByDesc('updated_at')->orderByDesc('id'),
            $limit,
            fn (Expense $expense): array => $this->item(
                'expense',
                (int) $expense->id,
                $this->label(
                    (int) $expense->id,
                    $expense->reference_number,
                    $expense->title,
                    $expense->supplier_name,
                ),
                route('expense.show', ['expense' => $expense->id]),
            ),
        );
    }

    /**
     * @return array{items: list<array{key: string, label: string, href: string}>, has_more: bool}
     */
    private function products(User $actor, User $owner, int $accountId, string $search, int $limit): array
    {
        $this->requireFeatures($owner, ['products']);
        if (! Gate::forUser($actor)->allows('viewAny', Product::class)) {
            abort(403);
        }

        $query = Product::query()
            ->byUser($accountId)
            ->products()
            ->select(['id', 'name', 'sku']);
        $this->applySearch($query, ['name', 'sku'], $search);

        return $this->collectItems(
            $query->orderBy('name')->orderBy('id'),
            $limit,
            fn (Product $product): array => $this->item(
                'product',
                (int) $product->id,
                $this->label((int) $product->id, $product->name, $product->sku),
                route('product.show', ['product' => $product->id]),
            ),
        );
    }

    /**
     * @return array{items: list<array{key: string, label: string, href: string}>, has_more: bool}
     */
    private function planScans(User $actor, User $owner, int $accountId, string $search, int $limit): array
    {
        $this->requireFeatures($owner, ['quotes', 'plan_scans']);
        if ((int) $actor->id !== $accountId) {
            abort(403);
        }

        $query = PlanScan::query()
            ->byUser($accountId)
            ->select(['id', 'job_title', 'plan_file_name']);
        $this->applySearch($query, ['job_title', 'plan_file_name'], $search);

        return $this->collectItems(
            $query->orderByDesc('updated_at')->orderByDesc('id'),
            $limit,
            fn (PlanScan $planScan): array => $this->item(
                'plan-scan',
                (int) $planScan->id,
                $this->label((int) $planScan->id, $planScan->job_title, $planScan->plan_file_name),
                route('plan-scans.show', ['planScan' => $planScan->id]),
            ),
        );
    }

    /**
     * @param  list<string>  $features
     */
    private function requireFeatures(User $owner, array $features): void
    {
        foreach ($features as $feature) {
            if (! $owner->hasCompanyFeature($feature)) {
                abort(403);
            }
        }
    }

    /**
     * @param  list<string>  $permissions
     */
    private function requireOwnerOrPermissions(
        User $actor,
        ?TeamMember $membership,
        int $accountId,
        array $permissions,
    ): void {
        if ((int) $actor->id === $accountId) {
            return;
        }

        if (! $membership || ! $this->hasAnyPermission($membership, $permissions)) {
            abort(403);
        }
    }

    /**
     * @param  list<string>  $permissions
     */
    private function hasAnyPermission(TeamMember $membership, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($membership->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    private function resolvePerformanceMode(User $owner): string
    {
        if ($owner->hasCompanyFeature('reservations')) {
            return 'reservations';
        }

        if ($owner->hasCompanyFeature('jobs') || $owner->hasCompanyFeature('tasks')) {
            return 'services';
        }

        if ($owner->hasCompanyFeature('sales')) {
            return 'products';
        }

        abort(403);
    }

    private function canViewTeamPerformance(TeamMember $membership, string $performanceMode): bool
    {
        if ($membership->role === 'admin') {
            return true;
        }

        if ($this->hasAnyPermission($membership, [
            'reports.team',
            'reports.view',
            'view_team_reports',
            'view_reports',
        ])) {
            return true;
        }

        return $performanceMode === 'products'
            && $this->hasAnyPermission($membership, ['sales.manage', 'view_sales_reports']);
    }

    private function canViewOwnPerformance(TeamMember $membership, string $performanceMode): bool
    {
        if ($performanceMode === 'reservations') {
            return $this->hasAnyPermission($membership, [
                'reservations.view',
                'reservations.queue',
                'reservations.manage',
            ]);
        }

        if ($performanceMode === 'services') {
            return $this->hasAnyPermission($membership, [
                'jobs.view',
                'jobs.edit',
                'tasks.view',
                'tasks.edit',
            ]);
        }

        return $this->hasAnyPermission($membership, [
            'sales.pos',
            'sales.manage',
            'view_sales_reports',
        ]);
    }

    /**
     * @param  list<string>  $columns
     */
    private function applySearch(Builder $query, array $columns, string $search): void
    {
        if ($search === '') {
            return;
        }

        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';

        $query->where(function (Builder $searchQuery) use ($columns, $pattern, $search): void {
            foreach ($columns as $index => $column) {
                $wrapped = $searchQuery->getQuery()->getGrammar()->wrap($column);
                $sql = "LOWER({$wrapped}) LIKE LOWER(?) ESCAPE '!'";

                if ($index === 0) {
                    $searchQuery->whereRaw($sql, [$pattern]);
                } else {
                    $searchQuery->orWhereRaw($sql, [$pattern]);
                }
            }

            if (ctype_digit($search)) {
                $searchQuery->orWhereKey((int) $search);
            }
        });
    }

    /**
     * @param  Closure(mixed): array{key: string, label: string, href: string}  $presenter
     * @return array{items: list<array{key: string, label: string, href: string}>, has_more: bool}
     */
    private function collectItems(Builder $query, int $limit, Closure $presenter): array
    {
        $rows = $query->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;

        return [
            'items' => $rows
                ->take($limit)
                ->map($presenter)
                ->values()
                ->all(),
            'has_more' => $hasMore,
        ];
    }

    /**
     * @return array{key: string, label: string, href: string}
     */
    private function item(string $prefix, int $id, string $label, string $href): array
    {
        return [
            'key' => $prefix.'-'.$id,
            'label' => $label,
            'href' => $href,
        ];
    }

    private function label(int $id, mixed ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            $label = trim((string) ($candidate ?? ''));
            if ($label !== '') {
                return $label;
            }
        }

        return '#'.$id;
    }
}
