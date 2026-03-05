<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\MailingList;
use App\Models\User;
use App\Services\Campaigns\MailingListService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MarketingMailingListController extends Controller
{
    public function __construct(
        private readonly MailingListService $mailingListService,
    ) {
    }

    public function index(Request $request)
    {
        [$owner, $canView] = $this->resolveAccess($request->user());
        if (!$canView) {
            abort(403);
        }

        $validated = $request->validate([
            'search' => 'nullable|string|max:120',
        ]);

        $lists = $this->mailingListService->list($owner, $validated);

        return response()->json([
            'mailing_lists' => $lists,
        ]);
    }

    public function show(Request $request, MailingList $mailingList)
    {
        [$owner, $canView] = $this->resolveAccess($request->user());
        if (!$canView) {
            abort(403);
        }

        if ((int) $mailingList->user_id !== (int) $owner->id) {
            abort(404);
        }

        $validated = $request->validate([
            'search' => 'nullable|string|max:120',
            'per_page' => 'nullable|integer|min:5|max:100',
        ]);

        $perPage = (int) ($validated['per_page'] ?? 25);
        $search = trim((string) ($validated['search'] ?? ''));

        $customers = $mailingList->customers()
            ->where('customers.user_id', $owner->id)
            ->when($search !== '', function (Builder $query) use ($search): void {
                $this->applyCustomerSearch($query, $search, [
                    'customers.company_name',
                    'customers.first_name',
                    'customers.last_name',
                    'customers.email',
                    'customers.phone',
                ]);
            })
            ->orderBy('customers.company_name')
            ->orderBy('customers.first_name')
            ->orderBy('customers.last_name')
            ->paginate($perPage)
            ->through(function ($customer) {
                return [
                    'id' => (int) $customer->id,
                    'first_name' => (string) ($customer->first_name ?? ''),
                    'last_name' => (string) ($customer->last_name ?? ''),
                    'company_name' => (string) ($customer->company_name ?? ''),
                    'email' => (string) ($customer->email ?? ''),
                    'phone' => (string) ($customer->phone ?? ''),
                    'is_vip' => (bool) ($customer->is_vip ?? false),
                    'vip_tier_code' => $customer->vip_tier_code,
                    'added_at' => $customer->pivot?->added_at,
                ];
            });

        return response()->json([
            'mailing_list' => $mailingList->fresh([
                'createdBy:id,name,email',
                'updatedBy:id,name,email',
            ]),
            'customers' => $customers,
        ]);
    }

    public function availableCustomers(Request $request, MailingList $mailingList)
    {
        [$owner, , $canManage] = $this->resolveAccess($request->user());
        if (!$canManage) {
            abort(403);
        }

        if ((int) $mailingList->user_id !== (int) $owner->id) {
            abort(404);
        }

        $validated = $request->validate([
            'search' => 'nullable|string|max:120',
            'per_page' => 'nullable|integer|min:5|max:100',
        ]);

        $perPage = (int) ($validated['per_page'] ?? 25);
        $search = trim((string) ($validated['search'] ?? ''));

        $customers = Customer::query()
            ->where('user_id', $owner->id)
            ->whereNotIn('id', function ($query) use ($mailingList): void {
                $query->select('customer_id')
                    ->from('mailing_list_customers')
                    ->where('mailing_list_id', $mailingList->id);
            })
            ->when($search !== '', function (Builder $query) use ($search): void {
                $this->applyCustomerSearch($query, $search, [
                    'company_name',
                    'first_name',
                    'last_name',
                    'email',
                    'phone',
                ]);
            })
            ->orderBy('company_name')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->paginate($perPage, [
                'id',
                'first_name',
                'last_name',
                'company_name',
                'email',
                'phone',
                'is_vip',
                'vip_tier_code',
            ])
            ->through(function (Customer $customer) {
                return [
                    'id' => (int) $customer->id,
                    'first_name' => (string) ($customer->first_name ?? ''),
                    'last_name' => (string) ($customer->last_name ?? ''),
                    'company_name' => (string) ($customer->company_name ?? ''),
                    'email' => (string) ($customer->email ?? ''),
                    'phone' => (string) ($customer->phone ?? ''),
                    'is_vip' => (bool) ($customer->is_vip ?? false),
                    'vip_tier_code' => $customer->vip_tier_code,
                ];
            });

        return response()->json([
            'customers' => $customers,
        ]);
    }

    public function store(Request $request)
    {
        [$owner, , $canManage] = $this->resolveAccess($request->user());
        if (!$canManage) {
            abort(403);
        }

        $validated = $this->validatedPayload($request);
        $list = $this->mailingListService->save($owner, $request->user(), $validated);

        return response()->json([
            'message' => 'Mailing list created.',
            'mailing_list' => $list,
        ], 201);
    }

    public function update(Request $request, MailingList $mailingList)
    {
        [$owner, , $canManage] = $this->resolveAccess($request->user());
        if (!$canManage) {
            abort(403);
        }

        if ((int) $mailingList->user_id !== (int) $owner->id) {
            abort(404);
        }

        $validated = $this->validatedPayload($request);
        $updated = $this->mailingListService->save($owner, $request->user(), $validated, $mailingList);

        return response()->json([
            'message' => 'Mailing list updated.',
            'mailing_list' => $updated,
        ]);
    }

    public function destroy(Request $request, MailingList $mailingList)
    {
        [$owner, , $canManage] = $this->resolveAccess($request->user());
        if (!$canManage) {
            abort(403);
        }

        if ((int) $mailingList->user_id !== (int) $owner->id) {
            abort(404);
        }

        $this->mailingListService->delete($owner, $mailingList);

        return response()->json([
            'message' => 'Mailing list deleted.',
        ]);
    }

    public function syncCustomers(Request $request, MailingList $mailingList)
    {
        [$owner, , $canManage] = $this->resolveAccess($request->user());
        if (!$canManage) {
            abort(403);
        }

        if ((int) $mailingList->user_id !== (int) $owner->id) {
            abort(404);
        }

        $validated = $request->validate([
            'customer_ids' => ['required', 'array'],
            'customer_ids.*' => ['integer'],
        ]);

        $this->mailingListService->syncCustomerIds(
            $owner,
            $request->user(),
            $mailingList,
            array_values($validated['customer_ids'])
        );

        return response()->json([
            'message' => 'Mailing list customers synchronized.',
        ]);
    }

    public function import(Request $request, MailingList $mailingList)
    {
        [$owner, , $canManage] = $this->resolveAccess($request->user());
        if (!$canManage) {
            abort(403);
        }

        if ((int) $mailingList->user_id !== (int) $owner->id) {
            abort(404);
        }

        $validated = $request->validate([
            'customer_ids' => ['nullable', 'array'],
            'customer_ids.*' => ['integer'],
            'emails' => ['nullable', 'array'],
            'emails.*' => ['string', 'max:255'],
            'phones' => ['nullable', 'array'],
            'phones.*' => ['string', 'max:60'],
            'rows' => ['nullable', 'array'],
            'paste' => ['nullable', 'string'],
        ]);

        $result = $this->mailingListService->importCustomers(
            $owner,
            $request->user(),
            $mailingList,
            $validated
        );

        return response()->json([
            'message' => 'Import completed.',
            'result' => $result,
        ]);
    }

    public function removeCustomers(Request $request, MailingList $mailingList)
    {
        [$owner, , $canManage] = $this->resolveAccess($request->user());
        if (!$canManage) {
            abort(403);
        }

        if ((int) $mailingList->user_id !== (int) $owner->id) {
            abort(404);
        }

        $validated = $request->validate([
            'customer_ids' => ['required', 'array'],
            'customer_ids.*' => ['integer'],
        ]);

        $removed = $this->mailingListService->removeCustomers(
            $owner,
            $mailingList,
            array_values($validated['customer_ids'])
        );

        return response()->json([
            'message' => 'Customers removed from mailing list.',
            'removed' => $removed,
        ]);
    }

    public function count(Request $request, MailingList $mailingList)
    {
        [$owner, $canView] = $this->resolveAccess($request->user());
        if (!$canView) {
            abort(403);
        }

        if ((int) $mailingList->user_id !== (int) $owner->id) {
            abort(404);
        }

        $validated = $request->validate([
            'channels' => ['nullable', 'array'],
            'channels.*' => ['string', Rule::in(Campaign::allowedChannels())],
        ]);

        $counts = $this->mailingListService->computeEligibilityCounts(
            $owner,
            $mailingList,
            $validated['channels'] ?? []
        );

        return response()->json([
            'mailing_list_id' => $mailingList->id,
            'counts' => $counts,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPayload(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1024',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:60',
            'customer_ids' => 'nullable|array',
            'customer_ids.*' => 'integer',
        ]);
    }

    private function resolveAccess(?User $user): array
    {
        if (!$user) {
            abort(401);
        }

        $ownerId = $user->accountOwnerId();
        $owner = $ownerId === $user->id
            ? $user
            : User::query()->select(['id'])->find($ownerId);
        if (!$owner) {
            abort(403);
        }

        if ($user->id === $owner->id) {
            return [$owner, true, true];
        }

        $membership = $user->relationLoaded('teamMembership')
            ? $user->teamMembership
            : $user->teamMembership()->first();

        $canManage = (bool) (
            $membership?->hasPermission('campaigns.manage')
            || $membership?->hasPermission('sales.manage')
        );
        $canView = $canManage
            || (bool) $membership?->hasPermission('campaigns.view')
            || (bool) $membership?->hasPermission('campaigns.send');

        return [$owner, $canView, $canManage];
    }

    /**
     * @param array<int, string> $columns
     */
    private function applyCustomerSearch(Builder $query, string $search, array $columns): void
    {
        $terms = collect(preg_split('/[\s,;]+/', trim($search)) ?: [])
            ->map(fn ($term) => trim((string) $term))
            ->filter()
            ->values();

        if ($terms->isEmpty() || $columns === []) {
            return;
        }

        $terms->each(function (string $term) use ($query, $columns): void {
            $like = '%' . $term . '%';
            $query->where(function (Builder $nested) use ($columns, $like): void {
                foreach ($columns as $index => $column) {
                    if ($index === 0) {
                        $nested->where($column, 'like', $like);
                        continue;
                    }

                    $nested->orWhere($column, 'like', $like);
                }
            });
        });
    }
}
