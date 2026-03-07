<?php

namespace App\Queries\Reservations;

use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\ReservationResource;
use App\Models\ReservationResourceAllocation;
use App\Models\ReservationWaitlist;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\WeeklyAvailability;
use App\Services\ReservationAvailabilityService;
use App\Services\ReservationQueueService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class BuildStaffReservationIndexData
{
    public function __construct(
        private readonly ReservationAvailabilityService $availabilityService,
        private readonly ReservationQueueService $queueService
    ) {}

    public function index(User $account, array $access, Request $request): array
    {
        $filters = $this->normalizeFilters($request, $access);

        $query = $this->reservationQuery($account->id)
            ->tap(fn (Builder $builder) => $this->applyReservationFilters($builder, $filters, $access));
        $this->applyReservationSort($query, $filters['sort']);

        $reservations = (clone $query)
            ->simplePaginate(20)
            ->withQueryString();

        $events = $this->reservationQuery($account->id)
            ->tap(fn (Builder $builder) => $this->applyReservationFilters($builder, $filters, $access))
            ->whereDate('starts_at', '>=', now()->subDays(7)->toDateString())
            ->whereDate('starts_at', '<=', now()->addDays(35)->toDateString())
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Reservation $reservation) => $this->mapEvent($reservation))
            ->values();

        $statsQuery = $this->reservationQuery($account->id, false)
            ->tap(fn (Builder $builder) => $this->applyReservationFilters($builder, $filters, $access, [
                'status' => false,
                'date' => false,
                'quick' => false,
            ]));

        $stats = [
            'total' => (clone $statsQuery)->count(),
            'pending' => (clone $statsQuery)->where('status', Reservation::STATUS_PENDING)->count(),
            'confirmed' => (clone $statsQuery)->where('status', Reservation::STATUS_CONFIRMED)->count(),
            'cancelled' => (clone $statsQuery)->where('status', Reservation::STATUS_CANCELLED)->count(),
            'today' => (clone $statsQuery)->whereDate('starts_at', now()->toDateString())->count(),
        ];

        $teamMembersQuery = TeamMember::query()
            ->forAccount($account->id)
            ->active()
            ->with('user:id,name');
        if (! $access['can_view_all'] && $access['own_team_member_id']) {
            $teamMembersQuery->whereKey($access['own_team_member_id']);
        }

        $teamMembers = $teamMembersQuery
            ->orderBy('id')
            ->get()
            ->map(fn (TeamMember $member) => [
                'id' => $member->id,
                'user_id' => $member->user_id,
                'name' => $member->user?->name ?? 'Member',
                'title' => $member->title,
            ])
            ->values();

        $services = Product::query()
            ->services()
            ->where('user_id', $account->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'price', 'item_type', 'is_active']);

        $clients = Customer::query()
            ->byUser($account->id)
            ->orderBy('company_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'company_name', 'email', 'phone', 'portal_user_id']);

        $settings = $this->availabilityService->resolveSettings($account->id, null);
        $performance = $this->buildPerformanceMetrics($account, $filters, $access, $settings);
        $waitlistQuery = ReservationWaitlist::query()
            ->forAccount($account->id)
            ->with([
                'client:id,first_name,last_name,company_name,email',
                'service:id,name',
                'teamMember.user:id,name',
            ]);
        if (! $access['can_view_all'] && $access['own_team_member_id']) {
            $waitlistQuery->where(function ($query) use ($access) {
                $query->where('team_member_id', (int) $access['own_team_member_id'])
                    ->orWhereNull('team_member_id');
            });
        }

        $waitlists = (clone $waitlistQuery)
            ->orderByRaw("CASE status
                WHEN 'pending' THEN 1
                WHEN 'released' THEN 2
                WHEN 'booked' THEN 3
                WHEN 'cancelled' THEN 4
                WHEN 'expired' THEN 5
                ELSE 99
            END ASC")
            ->orderBy('requested_start_at')
            ->limit(30)
            ->get()
            ->map(fn (ReservationWaitlist $waitlist) => $this->mapWaitlistEntry($waitlist, $access))
            ->values();

        $waitlistStats = [
            'pending' => (clone $waitlistQuery)->where('status', ReservationWaitlist::STATUS_PENDING)->count(),
            'released' => (clone $waitlistQuery)->where('status', ReservationWaitlist::STATUS_RELEASED)->count(),
            'booked' => (clone $waitlistQuery)->where('status', ReservationWaitlist::STATUS_BOOKED)->count(),
        ];
        $queuePayload = $this->queueService->boardForStaff($account->id, $access, $settings);

        return [
            'filters' => $filters,
            'reservations' => $reservations,
            'events' => $events,
            'statuses' => Reservation::STATUSES,
            'stats' => $stats,
            'quickCounts' => $this->quickCounts($account->id, $filters, $access),
            'access' => [
                'can_view_all' => $access['can_view_all'],
                'can_manage' => $access['can_manage'],
                'can_update_status' => $access['can_update_status'],
                'own_team_member_id' => $access['own_team_member_id'],
            ],
            'teamMembers' => $teamMembers,
            'services' => $services,
            'clients' => $clients,
            'timezone' => $this->availabilityService->timezoneForAccount($account),
            'defaults' => [
                'duration_minutes' => 60,
                'status' => Reservation::STATUS_CONFIRMED,
            ],
            'settings' => $settings,
            'performance' => $performance,
            'waitlists' => $waitlists,
            'waitlistStats' => $waitlistStats,
            'queueItems' => $queuePayload['items'] ?? [],
            'queueStats' => $queuePayload['stats'] ?? ['waiting' => 0, 'called' => 0, 'in_service' => 0],
        ];
    }

    public function events(int $accountId, array $access, Request $request, array $validated): array
    {
        $filters = $this->normalizeFilters($request, $access);

        return $this->reservationQuery($accountId)
            ->tap(fn (Builder $builder) => $this->applyReservationFilters($builder, $filters, $access, [
                'search' => false,
                'date' => false,
            ]))
            ->where('starts_at', '<', $validated['end'])
            ->where('ends_at', '>', $validated['start'])
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Reservation $reservation) => $this->mapEvent($reservation))
            ->values()
            ->all();
    }

    private function reservationQuery(int $accountId, bool $withRelations = true): Builder
    {
        $query = Reservation::query()->forAccount($accountId);
        if (! $withRelations) {
            return $query;
        }

        return $query->with([
            'teamMember.user:id,name',
            'client:id,first_name,last_name,company_name,email,phone,portal_user_id',
            'service:id,name,price,item_type',
            'creator:id,name',
            'canceller:id,name',
        ]);
    }

    private function normalizeFilters(Request $request, array $access): array
    {
        $ownTeamMemberId = $access['own_team_member_id'] ?: null;
        $canViewAll = (bool) ($access['can_view_all'] ?? false);

        $scope = (string) $request->input('scope', '');
        if (! in_array($scope, ['mine', 'all'], true)) {
            $scope = $ownTeamMemberId ? 'mine' : 'all';
        }
        if (! $canViewAll || ! $ownTeamMemberId) {
            $scope = $ownTeamMemberId ? 'mine' : 'all';
        }

        $quick = (string) $request->input('quick', '');
        if (! in_array($quick, ['', 'pending', 'today', 'upcoming', 'past'], true)) {
            $quick = '';
        }

        $sort = (string) $request->input('sort', 'date_asc');
        if (! in_array($sort, ['date_asc', 'date_desc', 'status'], true)) {
            $sort = 'date_asc';
        }

        $teamMemberId = $request->input('team_member_id');
        if ($scope === 'mine' && $ownTeamMemberId) {
            $teamMemberId = (string) $ownTeamMemberId;
        } elseif (! $canViewAll) {
            $teamMemberId = $ownTeamMemberId ? (string) $ownTeamMemberId : '';
        }

        $status = (string) $request->input('status', '');
        if ($status !== '' && ! in_array($status, Reservation::STATUSES, true)) {
            $status = '';
        }

        return [
            'status' => $status,
            'team_member_id' => (string) ($teamMemberId ?? ''),
            'service_id' => (string) ($request->input('service_id', '') ?? ''),
            'date_from' => (string) ($request->input('date_from', '') ?? ''),
            'date_to' => (string) ($request->input('date_to', '') ?? ''),
            'search' => (string) ($request->input('search', '') ?? ''),
            'view_mode' => (string) ($request->input('view_mode', 'calendar') ?: 'calendar'),
            'scope' => $scope,
            'quick' => $quick,
            'sort' => $sort,
        ];
    }

    private function applyReservationFilters(Builder $query, array $filters, array $access, array $options = []): void
    {
        $options = array_merge([
            'search' => true,
            'status' => true,
            'team' => true,
            'service' => true,
            'date' => true,
            'quick' => true,
        ], $options);

        $ownTeamMemberId = $access['own_team_member_id'] ?: null;
        $canViewAll = (bool) ($access['can_view_all'] ?? false);

        if (($filters['scope'] ?? 'all') === 'mine' && $ownTeamMemberId) {
            $query->where('team_member_id', (int) $ownTeamMemberId);
        }

        if ($options['team'] && ! empty($filters['team_member_id'])) {
            $teamMemberId = (int) $filters['team_member_id'];
            if ($teamMemberId > 0) {
                if ($canViewAll) {
                    $query->where('team_member_id', $teamMemberId);
                } elseif ($ownTeamMemberId && $teamMemberId === (int) $ownTeamMemberId) {
                    $query->where('team_member_id', $teamMemberId);
                }
            }
        }

        if ($options['service'] && ! empty($filters['service_id'])) {
            $query->where('service_id', (int) $filters['service_id']);
        }

        if ($options['status'] && ! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        if ($options['date']) {
            if (! empty($filters['date_from'])) {
                $query->whereDate('starts_at', '>=', (string) $filters['date_from']);
            }
            if (! empty($filters['date_to'])) {
                $query->whereDate('starts_at', '<=', (string) $filters['date_to']);
            }
        }

        if ($options['search'] && ! empty($filters['search'])) {
            $search = (string) $filters['search'];
            $query->where(function (Builder $subQuery) use ($search) {
                $subQuery->whereHas('client', function (Builder $clientQuery) use ($search) {
                    $clientQuery->where('company_name', 'like', '%'.$search.'%')
                        ->orWhere('first_name', 'like', '%'.$search.'%')
                        ->orWhere('last_name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%');
                })->orWhereHas('service', function (Builder $serviceQuery) use ($search) {
                    $serviceQuery->where('name', 'like', '%'.$search.'%');
                });
            });
        }

        if ($options['quick']) {
            $quick = (string) ($filters['quick'] ?? '');
            if ($quick === 'pending') {
                $query->where('status', Reservation::STATUS_PENDING);
            } elseif ($quick === 'today') {
                $query->whereDate('starts_at', now()->toDateString());
            } elseif ($quick === 'upcoming') {
                $query->where('starts_at', '>', now())
                    ->whereIn('status', Reservation::ACTIVE_STATUSES);
            } elseif ($quick === 'past') {
                $query->where('ends_at', '<', now());
            }
        }
    }

    private function applyReservationSort(Builder $query, string $sort): void
    {
        if ($sort === 'date_desc') {
            $query->orderByDesc('starts_at');

            return;
        }

        if ($sort === 'status') {
            $query->orderByRaw("CASE status
                WHEN 'pending' THEN 1
                WHEN 'confirmed' THEN 2
                WHEN 'rescheduled' THEN 3
                WHEN 'completed' THEN 4
                WHEN 'no_show' THEN 5
                WHEN 'cancelled' THEN 6
                ELSE 99
            END ASC");
            $query->orderBy('starts_at');

            return;
        }

        $query->orderBy('starts_at');
    }

    private function quickCounts(int $accountId, array $filters, array $access): array
    {
        $summaryQuery = $this->reservationQuery($accountId, false)
            ->tap(fn (Builder $builder) => $this->applyReservationFilters($builder, $filters, $access, [
                'status' => false,
                'date' => false,
                'quick' => false,
            ]));

        return [
            'pending' => (clone $summaryQuery)->where('status', Reservation::STATUS_PENDING)->count(),
            'today' => (clone $summaryQuery)->whereDate('starts_at', now()->toDateString())->count(),
            'upcoming' => (clone $summaryQuery)
                ->where('starts_at', '>', now())
                ->whereIn('status', Reservation::ACTIVE_STATUSES)
                ->count(),
            'past' => (clone $summaryQuery)->where('ends_at', '<', now())->count(),
        ];
    }

    private function buildPerformanceMetrics(User $account, array $filters, array $access, array $settings): array
    {
        $windowDays = 30;
        $windowStart = now('UTC')->subDays($windowDays)->startOfDay();
        $windowEnd = now('UTC')->endOfDay();
        $bookedStatuses = [
            Reservation::STATUS_CONFIRMED,
            Reservation::STATUS_RESCHEDULED,
            Reservation::STATUS_COMPLETED,
            Reservation::STATUS_NO_SHOW,
        ];

        $reservationWindowQuery = $this->reservationQuery($account->id, false)
            ->tap(fn (Builder $builder) => $this->applyReservationFilters($builder, $filters, $access, [
                'search' => false,
                'status' => false,
                'date' => false,
                'quick' => false,
            ]))
            ->where('starts_at', '>=', $windowStart)
            ->where('starts_at', '<=', $windowEnd);

        $total = (clone $reservationWindowQuery)->count();
        $completed = (clone $reservationWindowQuery)->where('status', Reservation::STATUS_COMPLETED)->count();
        $noShow = (clone $reservationWindowQuery)->where('status', Reservation::STATUS_NO_SHOW)->count();
        $rescheduled = (clone $reservationWindowQuery)->where('status', Reservation::STATUS_RESCHEDULED)->count();
        $bookedTotal = (clone $reservationWindowQuery)->whereIn('status', $bookedStatuses)->count();
        $bookedMinutes = (int) ((clone $reservationWindowQuery)->whereIn('status', $bookedStatuses)->sum('duration_minutes') ?? 0);

        $avgServiceValue = round((float) ((clone $reservationWindowQuery)
            ->where('status', Reservation::STATUS_COMPLETED)
            ->leftJoin('products', 'reservations.service_id', '=', 'products.id')
            ->avg('products.price')), 2);

        $teamMemberIds = $this->resolvePerformanceTeamMemberIds($account->id, $filters, $access);
        $teamUserIds = TeamMember::query()
            ->whereIn('id', $teamMemberIds)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $paymentWindowQuery = Payment::query()
            ->where('user_id', $account->id)
            ->whereIn('status', Payment::settledStatuses())
            ->where(function ($query) use ($windowStart) {
                $query->where('paid_at', '>=', $windowStart)
                    ->orWhere(function ($nested) use ($windowStart) {
                        $nested->whereNull('paid_at')
                            ->where('created_at', '>=', $windowStart);
                    });
            });

        if (! empty($teamUserIds)) {
            $paymentWindowQuery->whereIn('tip_assignee_user_id', $teamUserIds);
        }

        $paidPayments = (clone $paymentWindowQuery)->count();
        $tipRate = $paidPayments > 0
            ? round(((clone $paymentWindowQuery)->where('tip_amount', '>', 0)->count() / $paidPayments) * 100, 1)
            : 0.0;

        $availableMinutes = $this->availableMinutesInWindow($account->id, $teamMemberIds, $windowStart, $windowEnd);
        $occupancyRate = $availableMinutes > 0
            ? round(min(100, ($bookedMinutes / $availableMinutes) * 100), 1)
            : 0.0;

        $metrics = [
            'window_days' => $windowDays,
            'audience' => (bool) ($access['can_view_all'] ?? false) ? 'owner' : 'member',
            'preset' => (string) ($settings['business_preset'] ?? 'service_general'),
            'occupancy_rate' => $occupancyRate,
            'no_show_rate' => $bookedTotal > 0 ? round(($noShow / $bookedTotal) * 100, 1) : 0.0,
            'reschedule_rate' => $total > 0 ? round(($rescheduled / $total) * 100, 1) : 0.0,
            'completion_rate' => $bookedTotal > 0 ? round(($completed / $bookedTotal) * 100, 1) : 0.0,
            'avg_service_value' => $avgServiceValue,
            'tip_rate' => $tipRate,
        ];

        if ($metrics['preset'] === 'salon') {
            $bookedReservationIds = (clone $reservationWindowQuery)
                ->whereIn('status', $bookedStatuses)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $withResource = empty($bookedReservationIds)
                ? 0
                : ReservationResourceAllocation::query()
                    ->forAccount($account->id)
                    ->whereIn('reservation_id', $bookedReservationIds)
                    ->distinct('reservation_id')
                    ->count('reservation_id');

            $metrics['resource_reservation_rate'] = $bookedTotal > 0
                ? round(($withResource / $bookedTotal) * 100, 1)
                : 0.0;
        } elseif ($metrics['preset'] === 'restaurant') {
            $tableResourceCount = ReservationResource::query()
                ->forAccount($account->id)
                ->active()
                ->where('type', 'table')
                ->when(! empty($teamMemberIds), function ($query) use ($teamMemberIds) {
                    $query->where(function ($nested) use ($teamMemberIds) {
                        $nested->whereNull('team_member_id')
                            ->orWhereIn('team_member_id', $teamMemberIds);
                    });
                })
                ->count();

            $metrics['table_turnover'] = $tableResourceCount > 0
                ? round($completed / $tableResourceCount, 1)
                : 0.0;

            $partySizeValues = (clone $reservationWindowQuery)
                ->whereIn('status', $bookedStatuses)
                ->get(['metadata'])
                ->map(function (Reservation $reservation) {
                    $size = (int) data_get($reservation->metadata, 'party_size', 0);

                    return $size > 0 ? $size : null;
                })
                ->filter()
                ->values();

            $metrics['party_size_avg'] = $partySizeValues->isNotEmpty()
                ? round((float) $partySizeValues->avg(), 1)
                : 0.0;
        }

        return $metrics;
    }

    private function resolvePerformanceTeamMemberIds(int $accountId, array $filters, array $access): array
    {
        $memberQuery = TeamMember::query()
            ->forAccount($accountId)
            ->active();

        $ownTeamMemberId = (int) ($access['own_team_member_id'] ?? 0);
        $canViewAll = (bool) ($access['can_view_all'] ?? false);
        $scope = (string) ($filters['scope'] ?? 'all');
        $requestedTeamMemberId = (int) ($filters['team_member_id'] ?? 0);

        if ($scope === 'mine' && $ownTeamMemberId > 0) {
            $memberQuery->whereKey($ownTeamMemberId);
        } elseif ($requestedTeamMemberId > 0) {
            if ($canViewAll || ($ownTeamMemberId > 0 && $requestedTeamMemberId === $ownTeamMemberId)) {
                $memberQuery->whereKey($requestedTeamMemberId);
            } elseif ($ownTeamMemberId > 0) {
                $memberQuery->whereKey($ownTeamMemberId);
            }
        } elseif (! $canViewAll && $ownTeamMemberId > 0) {
            $memberQuery->whereKey($ownTeamMemberId);
        }

        return $memberQuery
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    private function availableMinutesInWindow(
        int $accountId,
        array $teamMemberIds,
        Carbon $windowStart,
        Carbon $windowEnd
    ): int {
        if (empty($teamMemberIds)) {
            return 0;
        }

        $minutesByDay = array_fill(0, 7, 0);
        $weeklyAvailabilities = WeeklyAvailability::query()
            ->forAccount($accountId)
            ->whereIn('team_member_id', $teamMemberIds)
            ->active()
            ->get(['day_of_week', 'start_time', 'end_time']);

        foreach ($weeklyAvailabilities as $availability) {
            $dayIndex = (int) $availability->day_of_week;
            if ($dayIndex < 0 || $dayIndex > 6) {
                continue;
            }

            $startMinutes = $this->parseTimeToMinutes((string) $availability->start_time);
            $endMinutes = $this->parseTimeToMinutes((string) $availability->end_time);
            if ($endMinutes <= $startMinutes) {
                continue;
            }

            $minutesByDay[$dayIndex] += ($endMinutes - $startMinutes);
        }

        $cursor = $windowStart->copy()->startOfDay();
        $endDate = $windowEnd->copy()->startOfDay();
        $availableMinutes = 0;

        while ($cursor->lte($endDate)) {
            $availableMinutes += (int) ($minutesByDay[$cursor->dayOfWeek] ?? 0);
            $cursor->addDay();
        }

        return $availableMinutes;
    }

    private function parseTimeToMinutes(string $time): int
    {
        $normalized = trim($time);
        if ($normalized === '') {
            return 0;
        }

        $hours = (int) substr($normalized, 0, 2);
        $minutes = (int) substr($normalized, 3, 2);

        return max(0, ($hours * 60) + $minutes);
    }

    private function canManageWaitlistStatus(array $access, ReservationWaitlist $waitlist): bool
    {
        if ($access['can_manage'] ?? false) {
            return true;
        }

        $ownTeamMemberId = (int) ($access['own_team_member_id'] ?? 0);
        if (! $ownTeamMemberId) {
            return false;
        }

        return (int) ($waitlist->team_member_id ?? 0) === $ownTeamMemberId;
    }

    private function mapWaitlistEntry(ReservationWaitlist $waitlist, array $access): array
    {
        $clientName = $waitlist->client?->company_name
            ?: trim(($waitlist->client?->first_name ?? '').' '.($waitlist->client?->last_name ?? ''));

        return [
            'id' => $waitlist->id,
            'status' => $waitlist->status,
            'client_name' => $clientName ?: ($waitlist->client?->email ?? null),
            'service_id' => $waitlist->service_id,
            'service_name' => $waitlist->service?->name,
            'team_member_id' => $waitlist->team_member_id,
            'team_member_name' => $waitlist->teamMember?->user?->name,
            'requested_start_at' => $waitlist->requested_start_at?->toIso8601String(),
            'requested_end_at' => $waitlist->requested_end_at?->toIso8601String(),
            'duration_minutes' => (int) ($waitlist->duration_minutes ?? 0),
            'party_size' => $waitlist->party_size,
            'notes' => $waitlist->notes,
            'resource_filters' => $waitlist->resource_filters,
            'can_update_status' => $this->canManageWaitlistStatus($access, $waitlist),
            'created_at' => $waitlist->created_at?->toIso8601String(),
        ];
    }

    private function mapEvent(Reservation $reservation): array
    {
        $clientLabel = $reservation->client?->company_name
            ?: trim(($reservation->client?->first_name ?? '').' '.($reservation->client?->last_name ?? ''));
        $serviceLabel = $reservation->service?->name ?: 'Reservation';
        $memberLabel = $reservation->teamMember?->user?->name ?: 'Team';
        $title = trim($serviceLabel.' · '.($clientLabel ?: 'Client'));

        return [
            'id' => $reservation->id,
            'title' => $title,
            'start' => $reservation->starts_at?->toIso8601String(),
            'end' => $reservation->ends_at?->toIso8601String(),
            'classNames' => ['reservation-event', 'status-'.$reservation->status],
            'extendedProps' => [
                'status' => $reservation->status,
                'team_member_id' => $reservation->team_member_id,
                'team_member_name' => $memberLabel,
                'client_name' => $clientLabel ?: null,
                'service_name' => $serviceLabel,
                'internal_notes' => $reservation->internal_notes,
                'client_notes' => $reservation->client_notes,
                'source' => $reservation->source,
            ],
        ];
    }
}
