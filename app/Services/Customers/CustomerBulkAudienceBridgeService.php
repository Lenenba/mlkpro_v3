<?php

namespace App\Services\Customers;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\MailingList;
use App\Models\User;
use App\Services\Campaigns\MailingListService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class CustomerBulkAudienceBridgeService
{
    public function __construct(
        private readonly MailingListService $mailingListService,
    ) {}

    /**
     * @param  Collection<int, Customer>  $customers
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function saveSelection(
        User $accountOwner,
        User $actor,
        Collection $customers,
        array $payload
    ): array {
        [$mailingList, $mode, $stats] = $this->persistSelection($accountOwner, $actor, $customers, $payload);

        return [
            'mode' => $mode,
            'mailing_list' => [
                'id' => (int) $mailingList->id,
                'name' => (string) $mailingList->name,
                'customers_count' => (int) ($mailingList->customers_count ?? 0),
            ],
            'stats' => $stats,
        ];
    }

    /**
     * @param  Collection<int, Customer>  $customers
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function prepareCampaignHandoff(
        User $accountOwner,
        User $actor,
        Collection $customers,
        array $payload
    ): array {
        [$mailingList, $mode, $stats] = $this->persistSelection($accountOwner, $actor, $customers, $payload);

        return [
            'mode' => $mode,
            'mailing_list' => [
                'id' => (int) $mailingList->id,
                'name' => (string) $mailingList->name,
                'customers_count' => (int) ($mailingList->customers_count ?? 0),
            ],
            'stats' => $stats,
            'redirect_url' => route('campaigns.create', [
                'seed_mailing_list_id' => $mailingList->id,
                'seed_objective' => $this->normalizeObjective($payload['objective'] ?? null),
                'seed_step' => 3,
            ]),
        ];
    }

    /**
     * @param  Collection<int, Customer>  $customers
     * @param  array<string, mixed>  $payload
     * @return array{0: MailingList, 1: string, 2: array<string, int>}
     */
    private function persistSelection(
        User $accountOwner,
        User $actor,
        Collection $customers,
        array $payload
    ): array {
        $customerIds = $customers->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($customerIds === []) {
            throw ValidationException::withMessages([
                'ids' => 'At least one customer must be selected.',
            ]);
        }

        $mailingListId = (int) ($payload['mailing_list_id'] ?? 0);
        if ($mailingListId > 0) {
            $mailingList = MailingList::query()
                ->forAccount($accountOwner->id)
                ->find($mailingListId);

            if (! $mailingList) {
                throw ValidationException::withMessages([
                    'mailing_list_id' => 'The selected mailing list is invalid for this tenant.',
                ]);
            }

            $result = $this->mailingListService->importCustomers(
                $accountOwner,
                $actor,
                $mailingList,
                ['customer_ids' => $customerIds]
            );

            return [
                $this->mailingListSnapshot($accountOwner, $mailingList->id),
                'existing',
                [
                    'selected_count' => count($customerIds),
                    'added' => (int) ($result['added'] ?? 0),
                    'already_present' => (int) ($result['already_present'] ?? 0),
                    'total' => (int) ($result['total'] ?? 0),
                ],
            ];
        }

        $name = trim((string) ($payload['mailing_list_name'] ?? ''));
        if ($name === '') {
            $name = $this->defaultMailingListName($payload['objective'] ?? null);
        }

        $mailingList = $this->mailingListService->save(
            $accountOwner,
            $actor,
            [
                'name' => $name,
                'description' => $this->defaultMailingListDescription($payload['objective'] ?? null),
                'tags' => $this->defaultMailingListTags($payload['objective'] ?? null),
                'customer_ids' => $customerIds,
            ]
        );

        $snapshot = $this->mailingListSnapshot($accountOwner, (int) $mailingList->id);

        return [
            $snapshot,
            'created',
            [
                'selected_count' => count($customerIds),
                'added' => (int) ($snapshot->customers_count ?? 0),
                'already_present' => 0,
                'total' => (int) ($snapshot->customers_count ?? 0),
            ],
        ];
    }

    private function mailingListSnapshot(User $accountOwner, int $mailingListId): MailingList
    {
        return MailingList::query()
            ->forAccount($accountOwner->id)
            ->withCount('customers')
            ->findOrFail($mailingListId, [
                'id',
                'user_id',
                'name',
                'description',
                'tags',
            ]);
    }

    /**
     * @return array<int, string>
     */
    private function defaultMailingListTags(mixed $objective): array
    {
        return array_values(array_filter([
            'customer-bulk',
            strtolower($this->normalizeObjective($objective)),
        ]));
    }

    private function defaultMailingListDescription(mixed $objective): string
    {
        return sprintf(
            'Saved from Customer bulk selection (%s) on %s.',
            strtolower(str_replace('_', ' ', $this->normalizeObjective($objective))),
            now()->format('Y-m-d H:i')
        );
    }

    private function defaultMailingListName(mixed $objective): string
    {
        return sprintf(
            '%s - %s',
            $this->campaignSeedLabel($objective),
            now()->format('Y-m-d H:i:s')
        );
    }

    private function campaignSeedLabel(mixed $objective): string
    {
        return match ($this->normalizeObjective($objective)) {
            CustomerBulkContactService::OBJECTIVE_PROMOTION => 'Promotion audience',
            CustomerBulkContactService::OBJECTIVE_ANNOUNCEMENT => 'Announcement audience',
            CustomerBulkContactService::OBJECTIVE_PAYMENT_FOLLOWUP => 'Payment follow-up audience',
            default => 'Customer selection audience',
        };
    }

    private function normalizeObjective(mixed $objective): string
    {
        $candidate = strtolower(trim((string) $objective));

        return in_array($candidate, CustomerBulkContactService::allowedObjectives(), true)
            ? $candidate
            : CustomerBulkContactService::OBJECTIVE_MANUAL_MESSAGE;
    }

    public function seedCampaignType(mixed $objective): string
    {
        return match ($this->normalizeObjective($objective)) {
            CustomerBulkContactService::OBJECTIVE_PROMOTION => Campaign::TYPE_PROMOTION,
            CustomerBulkContactService::OBJECTIVE_ANNOUNCEMENT => Campaign::TYPE_ANNOUNCEMENT,
            CustomerBulkContactService::OBJECTIVE_PAYMENT_FOLLOWUP => Campaign::TYPE_ANNOUNCEMENT,
            default => Campaign::TYPE_ANNOUNCEMENT,
        };
    }

    public function seedCampaignName(MailingList $mailingList, mixed $objective): string
    {
        return sprintf(
            '%s - %s',
            $this->campaignSeedLabel($objective),
            $mailingList->name
        );
    }
}
