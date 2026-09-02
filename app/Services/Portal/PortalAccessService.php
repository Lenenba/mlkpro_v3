<?php

namespace App\Services\Portal;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\Sale;
use App\Models\Task;
use App\Models\User;
use App\Models\Work;
use Illuminate\Http\Request;

class PortalAccessService
{
    public function clientHasPortalAccess(User $user): bool
    {
        if (! $user->isClient()) {
            return false;
        }

        return (bool) $this->clientCustomer($user)?->portal_access;
    }

    public function customer(Request $request): Customer
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->isClient()) {
            abort(403);
        }

        $customer = $this->clientCustomer($user);
        if (! $customer || ! $customer->portal_access) {
            abort(403, __('ui.auth.portal_access_disabled'));
        }

        return $customer;
    }

    /**
     * @param  array<int, string>|null  $ownerColumns
     * @return array{0: Customer, 1: User}
     */
    public function customerContext(
        Request $request,
        ?string $companyType = null,
        ?array $ownerColumns = null
    ): array {
        $customer = $this->customer($request);
        $owner = $this->ownerForCustomer($customer, $companyType, $ownerColumns);

        return [$customer, $owner];
    }

    /**
     * @param  array<int, string>|null  $ownerColumns
     * @return array{0: Customer, 1: User, 2: Sale}
     */
    public function saleContext(
        Request $request,
        Sale $sale,
        ?string $companyType = null,
        ?array $ownerColumns = null
    ): array {
        [$customer, $owner] = $this->customerContext($request, $companyType, $ownerColumns);

        if ((int) $sale->user_id !== (int) $owner->id || (int) $sale->customer_id !== (int) $customer->id) {
            abort(404);
        }

        return [$customer, $owner, $sale];
    }

    public function assertInvoice(Customer $customer, Invoice $invoice): void
    {
        if ((int) $invoice->customer_id !== (int) $customer->id
            || (int) $invoice->user_id !== (int) $customer->user_id) {
            abort(403);
        }
    }

    public function assertQuote(Customer $customer, Quote $quote): void
    {
        if ((int) $quote->customer_id !== (int) $customer->id
            || (int) $quote->user_id !== (int) $customer->user_id) {
            abort(403);
        }
    }

    public function assertWork(Customer $customer, Work $work): void
    {
        if ((int) $work->customer_id !== (int) $customer->id
            || (int) $work->user_id !== (int) $customer->user_id) {
            abort(403);
        }
    }

    public function assertTask(Customer $customer, Task $task): void
    {
        $task->loadMissing('work');

        $workCustomerId = (int) ($task->work?->customer_id ?? 0);
        $belongsToCustomer = (int) $task->customer_id === (int) $customer->id
            || $workCustomerId === (int) $customer->id;
        $belongsToAccount = (int) $task->account_id === (int) $customer->user_id
            && (! $task->work || (int) $task->work->user_id === (int) $customer->user_id);

        if (! $belongsToCustomer || ! $belongsToAccount) {
            abort(403);
        }
    }

    /**
     * @param  array<int, string>|null  $columns
     */
    public function ownerForCustomer(Customer $customer, ?string $companyType = null, ?array $columns = null): User
    {
        $query = User::query();

        if (is_array($columns) && $columns !== []) {
            $query->select($columns);
        }

        $owner = $query->find($customer->user_id);
        if (! $owner) {
            abort(403);
        }

        if ($companyType !== null && $owner->company_type !== $companyType) {
            abort(403);
        }

        return $owner;
    }

    private function clientCustomer(User $user): ?Customer
    {
        if ($user->relationLoaded('customerProfile')) {
            return $user->customerProfile;
        }

        $customer = $user->customerProfile()->first();
        $user->setRelation('customerProfile', $customer);

        return $customer;
    }
}
