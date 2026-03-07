<?php

namespace App\Services\Reservation;

use App\Models\Reservation;
use App\Models\ReservationResource;
use App\Models\ReservationResourceAllocation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ReservationResourceService
{
    public function normalizePartySize(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $parsed = (int) $value;

        return $parsed > 0 ? $parsed : null;
    }

    public function normalizeResourceIds(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $items = is_array($value) ? $value : [$value];

        return collect($items)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    public function normalizeResourceFilters(?array $filters): array
    {
        $filters = is_array($filters) ? $filters : [];

        $types = collect($filters['types'] ?? [])
            ->map(fn ($type) => strtolower(trim((string) $type)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $resourceIds = $this->normalizeResourceIds($filters['resource_ids'] ?? []);

        return [
            'types' => $types,
            'resource_ids' => $resourceIds,
        ];
    }

    public function hasResourceConstraint(?int $partySize, array $resourceFilters): bool
    {
        return ($partySize && $partySize > 0)
            || ! empty($resourceFilters['types'])
            || ! empty($resourceFilters['resource_ids']);
    }

    public function loadResourceAllocations(
        int $accountId,
        Carbon $startUtc,
        Carbon $endUtc,
        ?int $ignoreReservationId = null,
        bool $lockForUpdate = false
    ): Collection {
        $query = ReservationResourceAllocation::query()
            ->forAccount($accountId)
            ->with(['reservation:id,starts_at,ends_at,status'])
            ->whereHas('reservation', function ($reservationQuery) use ($startUtc, $endUtc, $ignoreReservationId) {
                $reservationQuery
                    ->whereIn('status', Reservation::ACTIVE_STATUSES)
                    ->where('starts_at', '<', $endUtc)
                    ->where('ends_at', '>', $startUtc)
                    ->when($ignoreReservationId, function ($query) use ($ignoreReservationId) {
                        $query->where('id', '!=', $ignoreReservationId);
                    });
            });

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->get()->groupBy('reservation_resource_id');
    }

    public function pickAvailableResourceForWindow(
        int $teamMemberId,
        Carbon $slotStartUtc,
        Carbon $slotEndUtc,
        Collection $resourcesByMember,
        Collection $resourceAllocations,
        ?int $partySize,
        array $resourceFilters
    ): ?ReservationResource {
        $resources = $this->availableResourcesForMember(
            $teamMemberId,
            $resourcesByMember,
            $resourceFilters,
            $partySize
        );

        if ($resources->isEmpty()) {
            return null;
        }

        $requiredCapacity = max(1, (int) ($partySize ?? 1));

        foreach ($resources as $resource) {
            $usedCapacity = $this->calculateUsedCapacityForSlot(
                $resource,
                $slotStartUtc,
                $slotEndUtc,
                $resourceAllocations
            );

            $capacity = max(1, (int) $resource->capacity);
            if (($usedCapacity + $requiredCapacity) <= $capacity) {
                return $resource;
            }
        }

        return null;
    }

    public function pickAvailableResourceForReservation(
        int $accountId,
        int $teamMemberId,
        Carbon $startUtc,
        Carbon $endUtc,
        ?int $partySize,
        array $resourceFilters,
        ?int $ignoreReservationId
    ): ?ReservationResource {
        $resources = ReservationResource::query()
            ->forAccount($accountId)
            ->active()
            ->where(function ($query) use ($teamMemberId) {
                $query->whereNull('team_member_id')
                    ->orWhere('team_member_id', $teamMemberId);
            })
            ->lockForUpdate()
            ->get();

        if ($resources->isEmpty()) {
            return null;
        }

        $resourcesByMember = $resources->groupBy(function (ReservationResource $resource) {
            return $resource->team_member_id ? (string) $resource->team_member_id : 'global';
        });
        $allocations = $this->loadResourceAllocations(
            $accountId,
            $startUtc,
            $endUtc,
            $ignoreReservationId,
            true
        );

        return $this->pickAvailableResourceForWindow(
            $teamMemberId,
            $startUtc,
            $endUtc,
            $resourcesByMember,
            $allocations,
            $partySize,
            $resourceFilters
        );
    }

    public function assertResourcesAvailable(
        int $accountId,
        int $teamMemberId,
        Carbon $startUtc,
        Carbon $endUtc,
        array $resourceIds,
        ?int $partySize,
        ?int $ignoreReservationId
    ): void {
        $resourceIds = $this->normalizeResourceIds($resourceIds);
        if (empty($resourceIds)) {
            return;
        }

        $resources = ReservationResource::query()
            ->forAccount($accountId)
            ->active()
            ->whereIn('id', $resourceIds)
            ->where(function ($query) use ($teamMemberId) {
                $query->whereNull('team_member_id')
                    ->orWhere('team_member_id', $teamMemberId);
            })
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($resources->count() !== count($resourceIds)) {
            throw ValidationException::withMessages([
                'resource_ids' => ['One or more selected resources are not available for this member.'],
            ]);
        }

        $allocations = $this->loadResourceAllocations(
            $accountId,
            $startUtc,
            $endUtc,
            $ignoreReservationId,
            true
        );

        $requiredCapacity = ($partySize && count($resourceIds) === 1)
            ? max(1, $partySize)
            : 1;

        foreach ($resourceIds as $resourceId) {
            $resource = $resources->get($resourceId);
            if (! $resource) {
                continue;
            }

            $capacity = max(1, (int) $resource->capacity);
            if ($requiredCapacity > $capacity) {
                throw ValidationException::withMessages([
                    'party_size' => ['Party size exceeds selected resource capacity.'],
                ]);
            }

            $usedCapacity = $this->calculateUsedCapacityForSlot(
                $resource,
                $startUtc,
                $endUtc,
                $allocations
            );

            if (($usedCapacity + $requiredCapacity) > $capacity) {
                throw ValidationException::withMessages([
                    'resource_ids' => ['Selected resources are no longer available for this slot.'],
                ]);
            }
        }
    }

    public function syncResourceAllocations(Reservation $reservation, array $resourceIds): void
    {
        $resourceIds = $this->normalizeResourceIds($resourceIds);

        $baseQuery = ReservationResourceAllocation::query()
            ->forAccount((int) $reservation->account_id)
            ->where('reservation_id', $reservation->id);

        if (empty($resourceIds)) {
            $baseQuery->delete();

            return;
        }

        $existing = (clone $baseQuery)
            ->get()
            ->keyBy(function (ReservationResourceAllocation $allocation) {
                return (int) $allocation->reservation_resource_id;
            });

        (clone $baseQuery)
            ->whereNotIn('reservation_resource_id', $resourceIds)
            ->delete();

        foreach ($resourceIds as $resourceId) {
            $allocation = $existing->get($resourceId);
            if ($allocation) {
                if ((int) $allocation->quantity !== 1) {
                    $allocation->update(['quantity' => 1]);
                }

                continue;
            }

            ReservationResourceAllocation::query()->create([
                'account_id' => $reservation->account_id,
                'reservation_id' => $reservation->id,
                'reservation_resource_id' => $resourceId,
                'quantity' => 1,
            ]);
        }
    }

    public function mergeResourceMetadata(
        array $metadata,
        ?int $partySize,
        array $resourceFilters,
        array $resourceIds
    ): array {
        if ($partySize && $partySize > 0) {
            $metadata['party_size'] = $partySize;
        } else {
            unset($metadata['party_size']);
        }

        if (! empty($resourceFilters['types']) || ! empty($resourceFilters['resource_ids'])) {
            $metadata['resource_filters'] = $resourceFilters;
        } else {
            unset($metadata['resource_filters']);
        }

        if (! empty($resourceIds)) {
            $metadata['resource_ids'] = array_values($resourceIds);
        } else {
            unset($metadata['resource_ids']);
        }

        return $metadata;
    }

    private function availableResourcesForMember(
        int $teamMemberId,
        Collection $resourcesByMember,
        array $resourceFilters,
        ?int $partySize
    ): Collection {
        $globalResources = $resourcesByMember->get('global', collect());
        $memberResources = $resourcesByMember->get((string) $teamMemberId, collect());
        $resources = $globalResources
            ->concat($memberResources)
            ->unique('id')
            ->values();

        $resourceIds = $resourceFilters['resource_ids'] ?? [];
        if (! empty($resourceIds)) {
            $allowed = array_flip($resourceIds);
            $resources = $resources
                ->filter(fn (ReservationResource $resource) => isset($allowed[(int) $resource->id]))
                ->values();
        }

        $types = $resourceFilters['types'] ?? [];
        if (! empty($types)) {
            $allowedTypes = array_flip($types);
            $resources = $resources
                ->filter(fn (ReservationResource $resource) => isset($allowedTypes[strtolower((string) $resource->type)]))
                ->values();
        }

        if ($partySize && $partySize > 0) {
            $resources = $resources
                ->filter(fn (ReservationResource $resource) => (int) $resource->capacity >= $partySize)
                ->values();
        }

        return $resources;
    }

    private function calculateUsedCapacityForSlot(
        ReservationResource $resource,
        Carbon $slotStartUtc,
        Carbon $slotEndUtc,
        Collection $resourceAllocations
    ): int {
        $allocations = $resourceAllocations->get($resource->id, collect());
        $usedCapacity = 0;

        foreach ($allocations as $allocation) {
            $reservation = $allocation->reservation;
            if (! $reservation) {
                continue;
            }

            if (
                $reservation->starts_at
                && $reservation->ends_at
                && $reservation->starts_at->lt($slotEndUtc)
                && $reservation->ends_at->gt($slotStartUtc)
            ) {
                $usedCapacity += max(1, (int) ($allocation->quantity ?? 1));
            }
        }

        return $usedCapacity;
    }
}
