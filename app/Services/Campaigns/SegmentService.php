<?php

namespace App\Services\Campaigns;

use App\Models\AudienceSegment;
use App\Models\Campaign;
use App\Models\CampaignAudience;
use App\Models\CampaignChannel;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class SegmentService
{
    public function __construct(
        private readonly AudienceResolver $audienceResolver,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return Collection<int, AudienceSegment>
     */
    public function list(User $accountOwner, array $filters = []): Collection
    {
        return AudienceSegment::query()
            ->where('user_id', $accountOwner->id)
            ->when($filters['search'] ?? null, function (Builder $query, mixed $search): void {
                $value = trim((string) $search);
                if ($value !== '') {
                    $query->where('name', 'like', '%' . $value . '%');
                }
            })
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
        ?AudienceSegment $segment = null
    ): AudienceSegment {
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => 'Segment name is required.',
            ]);
        }

        $query = AudienceSegment::query()
            ->where('user_id', $accountOwner->id)
            ->whereRaw('LOWER(name) = ?', [strtolower($name)]);
        if ($segment?->exists) {
            $query->where('id', '!=', $segment->id);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name' => 'Segment name already exists for this tenant.',
            ]);
        }

        if ($segment && (int) $segment->user_id !== (int) $accountOwner->id) {
            throw ValidationException::withMessages([
                'segment' => 'Segment does not belong to this tenant.',
            ]);
        }

        $computed = $this->computeEligibilityCounts(
            $accountOwner,
            is_array($payload['filters'] ?? null) ? $payload['filters'] : [],
            is_array($payload['exclusions'] ?? null) ? $payload['exclusions'] : []
        );

        $model = $segment ?? new AudienceSegment();
        $model->fill([
            'user_id' => $accountOwner->id,
            'created_by_user_id' => $model->created_by_user_id ?: $actor->id,
            'updated_by_user_id' => $actor->id,
            'name' => $name,
            'description' => $this->nullableString($payload['description'] ?? null),
            'filters' => is_array($payload['filters'] ?? null) ? $payload['filters'] : null,
            'exclusions' => is_array($payload['exclusions'] ?? null) ? $payload['exclusions'] : null,
            'tags' => is_array($payload['tags'] ?? null) ? array_values($payload['tags']) : null,
            'is_shared' => (bool) ($payload['is_shared'] ?? false),
            'cached_count' => (int) ($computed['total_eligible'] ?? 0),
            'last_computed_at' => now(),
        ]);
        $model->save();

        return $model->fresh();
    }

    public function delete(User $accountOwner, AudienceSegment $segment): void
    {
        if ((int) $segment->user_id !== (int) $accountOwner->id) {
            throw ValidationException::withMessages([
                'segment' => 'Segment does not belong to this tenant.',
            ]);
        }

        $segment->delete();
    }

    /**
     * @param array<string, mixed> $filters
     * @param array<string, mixed> $exclusions
     * @param array<int, string> $channels
     * @return array<string, mixed>
     */
    public function computeEligibilityCounts(
        User $accountOwner,
        array $filters,
        array $exclusions = [],
        array $channels = []
    ): array {
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
            'smart_filters' => $filters,
            'exclusion_filters' => $exclusions,
            'manual_customer_ids' => [],
            'manual_contacts' => [],
        ]));

        $channelsCollection = $enabledChannels
            ->map(function (string $channel): CampaignChannel {
                return new CampaignChannel([
                    'channel' => $channel,
                    'is_enabled' => true,
                ]);
            })
            ->values();
        $campaign->setRelation('channels', $channelsCollection);

        $result = $this->audienceResolver->resolveForCampaign($campaign);

        return $result['counts'] ?? [
            'total_eligible' => 0,
            'eligible_by_channel' => [],
            'blocked_by_channel' => [],
            'blocked_by_reason' => [],
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);
        return $string !== '' ? $string : null;
    }
}

