<?php

namespace App\Queries\Reservations;

use App\Models\Product;
use App\Models\Reservation;
use App\Models\TeamMember;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class BuildCustomerRebookingData
{
    private const RECENT_RESERVATIONS_LIMIT = 3;

    private const FREQUENT_SERVICES_LIMIT = 3;

    /**
     * @return array{
     *     recent_reservations: array<int, array{
     *         id: int,
     *         starts_at: string|null,
     *         duration_minutes: int,
     *         service: array{id: int, name: string, is_available: bool}|null,
     *         team_member: array{id: int, name: string, is_available: bool}|null
     *     }>,
     *     frequent_services: array<int, array{
     *         service: array{id: int, name: string, is_available: bool},
     *         reservation_count: int,
     *         last_booked_at: string|null,
     *         duration_minutes: int,
     *         team_member: array{id: int, name: string, is_available: bool}|null
     *     }>
     * }
     */
    public function build(int $accountId, int $customerId): array
    {
        $historyCutoff = now('UTC');
        $historyQuery = $this->historyQuery($accountId, $customerId, $historyCutoff);

        $recentReservations = (clone $historyQuery)
            ->latest('starts_at')
            ->latest('id')
            ->limit(self::RECENT_RESERVATIONS_LIMIT)
            ->get([
                'id',
                'service_id',
                'team_member_id',
                'starts_at',
                'duration_minutes',
            ]);

        $frequentServiceRows = (clone $historyQuery)
            ->whereNotNull('service_id')
            ->select('service_id')
            ->selectRaw('COUNT(*) as reservation_count')
            ->selectRaw('MAX(starts_at) as last_booked_at')
            ->groupBy('service_id')
            ->orderByDesc('reservation_count')
            ->orderByDesc('last_booked_at')
            ->orderBy('service_id')
            ->limit(self::FREQUENT_SERVICES_LIMIT)
            ->get();

        $frequentServiceIds = $frequentServiceRows
            ->pluck('service_id')
            ->map(fn (mixed $serviceId): int => (int) $serviceId)
            ->all();

        $latestFrequentReservations = $this->latestReservationsForServices(
            $accountId,
            $customerId,
            $frequentServiceIds,
            $historyCutoff
        );

        $serviceIds = $recentReservations
            ->pluck('service_id')
            ->merge($frequentServiceIds)
            ->filter()
            ->map(fn (mixed $serviceId): int => (int) $serviceId)
            ->unique()
            ->values();
        $teamMemberIds = $recentReservations
            ->pluck('team_member_id')
            ->merge($latestFrequentReservations->pluck('team_member_id'))
            ->filter()
            ->map(fn (mixed $teamMemberId): int => (int) $teamMemberId)
            ->unique()
            ->values();

        $services = $serviceIds->isEmpty()
            ? collect()
            : Product::query()
                ->byUser($accountId)
                ->whereKey($serviceIds)
                ->get(['id', 'name', 'item_type', 'is_active'])
                ->keyBy(fn (Product $service): int => (int) $service->id);
        $teamMembers = $teamMemberIds->isEmpty()
            ? collect()
            : TeamMember::query()
                ->forAccount($accountId)
                ->whereKey($teamMemberIds)
                ->with('user:id,name')
                ->get(['id', 'account_id', 'user_id', 'is_active'])
                ->keyBy(fn (TeamMember $teamMember): int => (int) $teamMember->id);

        return [
            'recent_reservations' => $recentReservations
                ->map(fn (Reservation $reservation): array => [
                    'id' => (int) $reservation->id,
                    'starts_at' => $reservation->starts_at?->toIso8601String(),
                    'duration_minutes' => (int) $reservation->duration_minutes,
                    'service' => $this->mapService($services->get((int) $reservation->service_id)),
                    'team_member' => $this->mapTeamMember($teamMembers->get((int) $reservation->team_member_id)),
                ])
                ->values()
                ->all(),
            'frequent_services' => $frequentServiceRows
                ->map(function (Reservation $frequency) use (
                    $latestFrequentReservations,
                    $services,
                    $teamMembers
                ): ?array {
                    $serviceId = (int) $frequency->service_id;
                    $service = $services->get($serviceId);
                    $latestReservation = $latestFrequentReservations->get($serviceId);
                    if (! $service || ! $latestReservation) {
                        return null;
                    }

                    return [
                        'service' => $this->mapService($service),
                        'reservation_count' => (int) $frequency->reservation_count,
                        'last_booked_at' => $latestReservation->starts_at?->toIso8601String(),
                        'duration_minutes' => (int) $latestReservation->duration_minutes,
                        'team_member' => $this->mapTeamMember(
                            $teamMembers->get((int) $latestReservation->team_member_id)
                        ),
                    ];
                })
                ->filter()
                ->values()
                ->all(),
        ];
    }

    private function historyQuery(
        int $accountId,
        int $customerId,
        CarbonInterface $historyCutoff
    ): Builder {
        return Reservation::query()
            ->forAccount($accountId)
            ->where('client_id', $customerId)
            ->where('status', Reservation::STATUS_COMPLETED)
            ->where('ends_at', '<=', $historyCutoff)
            ->where(function (Builder $serviceScope) use ($accountId): void {
                $serviceScope
                    ->whereNull('service_id')
                    ->orWhereHas('service', fn (Builder $serviceQuery): Builder => $serviceQuery
                        ->byUser($accountId));
            });
    }

    /**
     * @param  array<int, int>  $serviceIds
     * @return Collection<int, Reservation>
     */
    private function latestReservationsForServices(
        int $accountId,
        int $customerId,
        array $serviceIds,
        CarbonInterface $historyCutoff
    ): Collection {
        if ($serviceIds === []) {
            return collect();
        }

        $reservationsTable = (new Reservation)->getTable();

        return Reservation::query()
            ->forAccount($accountId)
            ->where('client_id', $customerId)
            ->where('status', Reservation::STATUS_COMPLETED)
            ->where('ends_at', '<=', $historyCutoff)
            ->whereIn('service_id', $serviceIds)
            ->where($reservationsTable.'.id', function ($latestQuery) use (
                $accountId,
                $customerId,
                $historyCutoff,
                $reservationsTable
            ): void {
                $latestQuery
                    ->select('latest_customer_reservation.id')
                    ->from($reservationsTable.' as latest_customer_reservation')
                    ->whereColumn(
                        'latest_customer_reservation.service_id',
                        $reservationsTable.'.service_id'
                    )
                    ->where('latest_customer_reservation.account_id', $accountId)
                    ->where('latest_customer_reservation.client_id', $customerId)
                    ->where('latest_customer_reservation.status', Reservation::STATUS_COMPLETED)
                    ->where('latest_customer_reservation.ends_at', '<=', $historyCutoff)
                    ->orderByDesc('latest_customer_reservation.starts_at')
                    ->orderByDesc('latest_customer_reservation.id')
                    ->limit(1);
            })
            ->get([
                'id',
                'service_id',
                'team_member_id',
                'starts_at',
                'duration_minutes',
            ])
            ->keyBy(fn (Reservation $reservation): int => (int) $reservation->service_id);
    }

    /**
     * @return array{id: int, name: string, is_available: bool}|null
     */
    private function mapService(?Product $service): ?array
    {
        if (! $service) {
            return null;
        }

        return [
            'id' => (int) $service->id,
            'name' => (string) $service->name,
            'is_available' => (bool) $service->is_active
                && $service->item_type === Product::ITEM_TYPE_SERVICE,
        ];
    }

    /**
     * @return array{id: int, name: string, is_available: bool}|null
     */
    private function mapTeamMember(?TeamMember $teamMember): ?array
    {
        if (! $teamMember) {
            return null;
        }

        return [
            'id' => (int) $teamMember->id,
            'name' => (string) ($teamMember->user?->name ?? 'Member'),
            'is_available' => (bool) $teamMember->is_active && (bool) $teamMember->user,
        ];
    }
}
