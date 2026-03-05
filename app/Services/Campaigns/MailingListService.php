<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignAudience;
use App\Models\CampaignChannel;
use App\Models\Customer;
use App\Models\MailingList;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MailingListService
{
    public function __construct(
        private readonly AudienceResolver $audienceResolver,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return Collection<int, MailingList>
     */
    public function list(User $accountOwner, array $filters = []): Collection
    {
        return MailingList::query()
            ->forAccount($accountOwner->id)
            ->when($filters['search'] ?? null, function (Builder $query, mixed $search): void {
                $value = trim((string) $search);
                if ($value === '') {
                    return;
                }

                $query->where(function (Builder $nested) use ($value): void {
                    $nested->where('name', 'like', '%' . $value . '%')
                        ->orWhere('description', 'like', '%' . $value . '%');
                });
            })
            ->withCount('customers')
            ->orderBy('name')
            ->get();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function save(
        User $accountOwner,
        User $actor,
        array $payload,
        ?MailingList $list = null
    ): MailingList {
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => 'Mailing list name is required.',
            ]);
        }

        if ($list && (int) $list->user_id !== (int) $accountOwner->id) {
            throw ValidationException::withMessages([
                'mailing_list' => 'Mailing list does not belong to this tenant.',
            ]);
        }

        $query = MailingList::query()
            ->forAccount($accountOwner->id)
            ->whereRaw('LOWER(name) = ?', [strtolower($name)]);
        if ($list?->exists) {
            $query->where('id', '!=', $list->id);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name' => 'Mailing list name already exists for this tenant.',
            ]);
        }

        $model = $list ?? new MailingList();
        $model->fill([
            'user_id' => $accountOwner->id,
            'created_by_user_id' => $model->created_by_user_id ?: $actor->id,
            'updated_by_user_id' => $actor->id,
            'name' => $name,
            'description' => $this->nullableString($payload['description'] ?? null),
            'tags' => is_array($payload['tags'] ?? null) ? array_values($payload['tags']) : null,
        ]);
        $model->save();

        $customerIds = collect($payload['customer_ids'] ?? [])
            ->map(fn ($value) => is_numeric($value) ? (int) $value : null)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($customerIds !== []) {
            $this->syncCustomerIds($accountOwner, $actor, $model, $customerIds);
        }

        return $model->fresh(['customers:id,first_name,last_name,company_name,email,phone']);
    }

    public function delete(User $accountOwner, MailingList $list): void
    {
        $this->assertTenant($accountOwner, $list);
        $list->delete();
    }

    /**
     * @param array<int, int> $customerIds
     */
    public function syncCustomerIds(User $accountOwner, User $actor, MailingList $list, array $customerIds): void
    {
        $this->assertTenant($accountOwner, $list);

        $validIds = Customer::query()
            ->where('user_id', $accountOwner->id)
            ->whereIn('id', $customerIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $rows = collect($validIds)->map(fn (int $customerId) => [
            'mailing_list_id' => $list->id,
            'customer_id' => $customerId,
            'added_by_user_id' => $actor->id,
            'added_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->all();

        DB::table('mailing_list_customers')
            ->where('mailing_list_id', $list->id)
            ->delete();

        if ($rows !== []) {
            DB::table('mailing_list_customers')->insert($rows);
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function importCustomers(User $accountOwner, User $actor, MailingList $list, array $payload): array
    {
        $this->assertTenant($accountOwner, $list);

        $input = $this->normalizeImportPayload($payload);
        if ($input['ids'] === [] && $input['emails'] === [] && $input['phones'] === []) {
            return [
                'added' => 0,
                'matched' => 0,
                'already_present' => 0,
                'total' => $list->customers()->count(),
            ];
        }

        $customers = Customer::query()
            ->where('user_id', $accountOwner->id)
            ->where(function (Builder $query) use ($input): void {
                if ($input['ids'] !== []) {
                    $query->orWhereIn('id', $input['ids']);
                }
                if ($input['emails'] !== []) {
                    $query->orWhereIn('email', $input['emails']);
                }
                if ($input['phones'] !== []) {
                    $query->orWhereIn('phone', $input['phones']);
                }
            })
            ->get(['id']);

        $matchedIds = $customers->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($matchedIds->isEmpty()) {
            return [
                'added' => 0,
                'matched' => 0,
                'already_present' => 0,
                'total' => $list->customers()->count(),
            ];
        }

        $existing = DB::table('mailing_list_customers')
            ->where('mailing_list_id', $list->id)
            ->whereIn('customer_id', $matchedIds->all())
            ->pluck('customer_id')
            ->map(fn ($id) => (int) $id)
            ->values();

        $toInsert = $matchedIds->diff($existing)->values();
        $rows = $toInsert->map(fn (int $customerId) => [
            'mailing_list_id' => $list->id,
            'customer_id' => $customerId,
            'added_by_user_id' => $actor->id,
            'added_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->all();

        if ($rows !== []) {
            DB::table('mailing_list_customers')->insert($rows);
        }

        return [
            'added' => count($rows),
            'matched' => $matchedIds->count(),
            'already_present' => $existing->count(),
            'total' => (int) DB::table('mailing_list_customers')
                ->where('mailing_list_id', $list->id)
                ->count(),
        ];
    }

    /**
     * @param array<int, int> $customerIds
     */
    public function removeCustomers(User $accountOwner, MailingList $list, array $customerIds): int
    {
        $this->assertTenant($accountOwner, $list);

        $ids = collect($customerIds)
            ->map(fn ($value) => is_numeric($value) ? (int) $value : null)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return 0;
        }

        return (int) DB::table('mailing_list_customers')
            ->where('mailing_list_id', $list->id)
            ->whereIn('customer_id', $ids)
            ->delete();
    }

    /**
     * @param array<int, string> $channels
     * @return array<string, mixed>
     */
    public function computeEligibilityCounts(User $accountOwner, MailingList $list, array $channels = []): array
    {
        $this->assertTenant($accountOwner, $list);
        $customerIds = $list->customers()->pluck('customers.id')->map(fn ($id) => (int) $id)->values()->all();

        $enabledChannels = collect($channels)
            ->map(fn ($channel) => strtoupper((string) $channel))
            ->filter(fn ($channel) => in_array($channel, Campaign::allowedChannels(), true))
            ->values();
        if ($enabledChannels->isEmpty()) {
            $enabledChannels = collect(Campaign::allowedChannels());
        }

        $campaign = new Campaign([
            'user_id' => $accountOwner->id,
            'status' => Campaign::STATUS_DRAFT,
            'schedule_type' => Campaign::SCHEDULE_MANUAL,
            'type' => Campaign::TYPE_ANNOUNCEMENT,
            'campaign_type' => Campaign::TYPE_ANNOUNCEMENT,
            'offer_mode' => Campaign::OFFER_MODE_PRODUCTS,
        ]);
        $campaign->setRelation('user', $accountOwner);
        $campaign->setRelation('offers', collect());
        $campaign->setRelation('products', collect());
        $campaign->setRelation('audience', new CampaignAudience([
            'smart_filters' => null,
            'exclusion_filters' => null,
            'manual_customer_ids' => $customerIds,
            'manual_contacts' => [],
            'source_logic' => 'UNION',
        ]));
        $campaign->setRelation('channels', $enabledChannels->map(function (string $channel) {
            return new CampaignChannel([
                'channel' => $channel,
                'is_enabled' => true,
            ]);
        }));

        $result = $this->audienceResolver->resolveForCampaign($campaign);

        return $result['counts'] ?? [
            'total_eligible' => 0,
            'eligible_by_channel' => [],
            'blocked_by_channel' => [],
            'blocked_by_reason' => [],
        ];
    }

    private function assertTenant(User $accountOwner, MailingList $list): void
    {
        if ((int) $list->user_id !== (int) $accountOwner->id) {
            throw ValidationException::withMessages([
                'mailing_list' => 'Mailing list does not belong to this tenant.',
            ]);
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{ids: array<int, int>, emails: array<int, string>, phones: array<int, string>}
     */
    private function normalizeImportPayload(array $payload): array
    {
        $ids = collect($payload['customer_ids'] ?? [])
            ->map(fn ($value) => is_numeric($value) ? (int) $value : null)
            ->filter()
            ->values();

        $emails = collect($payload['emails'] ?? [])
            ->map(fn ($value) => strtolower(trim((string) $value)))
            ->filter(fn ($value) => $value !== '')
            ->values();

        $phones = collect($payload['phones'] ?? [])
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn ($value) => $value !== '')
            ->values();

        $rows = Arr::wrap($payload['rows'] ?? []);
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $candidateId = $row['customer_id'] ?? $row['id'] ?? null;
            if (is_numeric($candidateId)) {
                $ids->push((int) $candidateId);
            }
            if (!empty($row['email'])) {
                $emails->push(strtolower(trim((string) $row['email'])));
            }
            if (!empty($row['phone'])) {
                $phones->push(trim((string) $row['phone']));
            }
        }

        $paste = trim((string) ($payload['paste'] ?? ''));
        if ($paste !== '') {
            $tokens = preg_split('/[\r\n,;]+/', $paste) ?: [];
            foreach ($tokens as $token) {
                $candidate = trim((string) $token);
                if ($candidate === '') {
                    continue;
                }

                if (is_numeric($candidate)) {
                    $ids->push((int) $candidate);
                    continue;
                }

                if (str_contains($candidate, '@')) {
                    $emails->push(strtolower($candidate));
                    continue;
                }

                $phones->push($candidate);
            }
        }

        return [
            'ids' => $ids->unique()->values()->all(),
            'emails' => $emails->unique()->values()->all(),
            'phones' => $phones->unique()->values()->all(),
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }
}

