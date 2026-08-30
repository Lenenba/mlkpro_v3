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
use App\Services\BillingPlanService;
use App\Services\BillingSubscriptionService;
use App\Services\ReservationAvailabilityService;
use App\Services\ReservationQueueService;
use App\Support\DataTablePagination;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class BuildStaffReservationIndexData
{
    public function __construct(
        private readonly ReservationAvailabilityService $availabilityService,
        private readonly ReservationQueueService $queueService
    ) {}

    public function index(User $account, array $access, Request $request): array
    {
        $filters = $this->normalizeFilters($request, $access);
        $ownerOnlyMode = $this->ownerOnlyMode($account);
        $accountTimezone = $this->availabilityService->timezoneForAccount($account);

        if ($ownerOnlyMode && (bool) ($access['is_account_owner'] ?? false)) {
            $filters['team_member_id'] = '';
            $filters['scope'] = 'all';
        }

        $canManageReservations = (bool) ($access['can_manage'] ?? false);
        $query = $this->reservationQuery($account->id, true, $canManageReservations)
            ->tap(fn (Builder $builder) => $this->applyReservationFilters(
                $builder,
                $filters,
                $access,
                $account->id,
                $accountTimezone
            ));
        $this->applyReservationSort($query, $filters['sort'], $account->id);

        $reservations = (clone $query)
            ->paginate((int) ($filters['per_page'] ?? DataTablePagination::defaultPerPage()))
            ->withQueryString();
        $reservations->setCollection(
            $reservations->getCollection()
                ->map(fn (Reservation $reservation) => $this->mapReservationListItem(
                    $reservation,
                    $canManageReservations,
                    $access,
                    $ownerOnlyMode
                ))
        );

        $eventWindowStart = now($accountTimezone)->subDays(7)->startOfDay()->utc();
        $eventWindowEnd = now($accountTimezone)->addDays(36)->startOfDay()->utc();
        $events = $this->reservationEventQuery($account->id)
            ->tap(fn (Builder $builder) => $this->applyReservationFilters(
                $builder,
                $filters,
                $access,
                $account->id,
                $accountTimezone
            ))
            ->where('starts_at', '>=', $eventWindowStart)
            ->where('starts_at', '<', $eventWindowEnd)
            ->orderBy('starts_at')
            ->get([
                'id',
                'team_member_id',
                'client_id',
                'prospect_id',
                'service_id',
                'status',
                'outcome_review_required_at',
                'outcome_review_reason_code',
                'source',
                'starts_at',
                'ends_at',
            ])
            ->map(fn (Reservation $reservation) => $this->mapEvent($reservation))
            ->values();

        $statsQuery = $this->reservationQuery($account->id, false)
            ->tap(fn (Builder $builder) => $this->applyReservationFilters(
                $builder,
                $filters,
                $access,
                $account->id,
                $accountTimezone,
                [
                    'status' => false,
                    'date' => false,
                    'quick' => false,
                ]
            ));

        [$todayStart, $tomorrowStart] = $this->localDayBounds(
            now($accountTimezone)->toDateString(),
            $accountTimezone
        );

        $stats = [
            'total' => (clone $statsQuery)->count(),
            'pending' => (clone $statsQuery)->where('status', Reservation::STATUS_PENDING)->count(),
            'confirmed' => (clone $statsQuery)->where('status', Reservation::STATUS_CONFIRMED)->count(),
            'cancelled' => (clone $statsQuery)->where('status', Reservation::STATUS_CANCELLED)->count(),
            'today' => (clone $statsQuery)
                ->where('starts_at', '>=', $todayStart)
                ->where('starts_at', '<', $tomorrowStart)
                ->count(),
        ];

        $teamMembers = ! $ownerOnlyMode
            ? tap(
                TeamMember::query()
                    ->forAccount($account->id)
                    ->active()
                    ->with('user:id,name'),
                function (Builder $teamMembersQuery) use ($access): void {
                    if (! $access['can_view_all'] && $access['own_team_member_id']) {
                        $teamMembersQuery->whereKey($access['own_team_member_id']);
                    }
                }
            )
                ->orderBy('id')
                ->get(['id', 'user_id', 'title'])
                ->map(fn (TeamMember $member) => [
                    'id' => $member->id,
                    'user_id' => $member->user_id,
                    'name' => $member->user?->name ?? 'Member',
                    'title' => $member->title,
                ])
                ->values()
            : collect();

        $services = Product::query()
            ->services()
            ->where('user_id', $account->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        $clients = $canManageReservations
            ? Customer::query()
                ->byUser($account->id)
                ->orderBy('company_name')
                ->orderBy('last_name')
                ->get(['id', 'first_name', 'last_name', 'company_name', 'email', 'phone'])
                ->map(fn (Customer $client) => [
                    'id' => (int) $client->id,
                    'first_name' => $client->first_name,
                    'last_name' => $client->last_name,
                    'company_name' => $client->company_name,
                    'email' => $client->email,
                    'phone' => $client->phone,
                ])
                ->values()
            : collect();

        $settings = $this->availabilityService->resolveSettings($account->id, null);
        $performance = $this->buildPerformanceMetrics($account, $filters, $access, $settings, $accountTimezone);
        $waitlistQuery = ReservationWaitlist::query()
            ->forAccount($account->id)
            ->with([
                'client' => fn (BelongsTo $relation) => $relation
                    ->byUser($account->id)
                    ->select(['id', 'user_id', 'first_name', 'last_name', 'company_name', 'email']),
                'service' => fn (BelongsTo $relation) => $relation
                    ->byUser($account->id)
                    ->select(['id', 'user_id', 'name']),
                'teamMember' => fn (BelongsTo $relation) => $relation
                    ->forAccount($account->id)
                    ->select(['id', 'account_id', 'user_id']),
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
            'quickCounts' => $this->quickCounts($account->id, $filters, $access, $accountTimezone),
            'access' => [
                'can_view_all' => $access['can_view_all'],
                'can_manage' => $access['can_manage'],
                'can_create_customer' => (bool) ($access['can_create_customer'] ?? false),
                'can_update_status' => $access['can_update_status'],
                'own_team_member_id' => $access['own_team_member_id'],
            ],
            'teamMembers' => $teamMembers,
            'services' => $services,
            'clients' => $clients,
            'timezone' => $accountTimezone,
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

    private function ownerOnlyMode(User $account): bool
    {
        $planKey = app(BillingSubscriptionService::class)->resolvePlanKey($account, config('billing.plans', []));

        return $planKey ? app(BillingPlanService::class)->isOwnerOnlyPlan($planKey) : false;
    }

    public function events(
        int $accountId,
        array $access,
        Request $request,
        array $validated,
        string $accountTimezone = 'UTC'
    ): array {
        $filters = $this->normalizeFilters($request, $access);
        $start = Carbon::parse((string) $validated['start'])->utc();
        $end = Carbon::parse((string) $validated['end'])->utc();

        return $this->reservationEventQuery($accountId)
            ->tap(fn (Builder $builder) => $this->applyReservationFilters(
                $builder,
                $filters,
                $access,
                $accountId,
                $accountTimezone
            ))
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->orderBy('starts_at')
            ->get([
                'id',
                'team_member_id',
                'client_id',
                'prospect_id',
                'service_id',
                'status',
                'outcome_review_required_at',
                'outcome_review_reason_code',
                'source',
                'starts_at',
                'ends_at',
            ])
            ->map(fn (Reservation $reservation) => $this->mapEvent($reservation))
            ->values()
            ->all();
    }

    private function reservationQuery(
        int $accountId,
        bool $withRelations = true,
        bool $includeNotes = false
    ): Builder {
        $query = Reservation::query()->where('reservations.account_id', $accountId);
        if (! $withRelations) {
            return $query;
        }

        $columns = [
            'reservations.id',
            'reservations.account_id',
            'reservations.team_member_id',
            'reservations.client_id',
            'reservations.prospect_id',
            'reservations.service_id',
            'reservations.status',
            'reservations.outcome_review_required_at',
            'reservations.outcome_review_reason_code',
            'reservations.source',
            'reservations.timezone',
            'reservations.starts_at',
            'reservations.ends_at',
            'reservations.duration_minutes',
            'reservations.buffer_minutes',
        ];
        if ($includeNotes) {
            $columns = [...$columns, 'reservations.internal_notes', 'reservations.client_notes'];
        }

        return $query->select($columns)->with([
            'teamMember' => fn (BelongsTo $relation) => $relation
                ->forAccount($accountId)
                ->select(['id', 'account_id', 'user_id', 'title']),
            'teamMember.user:id,name,profile_picture',
            'client' => fn (BelongsTo $relation) => $relation
                ->byUser($accountId)
                ->select([
                    'id',
                    'user_id',
                    'first_name',
                    'last_name',
                    'company_name',
                    'client_type',
                    'logo',
                ]),
            'prospect' => fn (BelongsTo $relation) => $relation
                ->byUser($accountId)
                ->select(['id', 'user_id', 'contact_name']),
            'service' => fn (BelongsTo $relation) => $relation
                ->byUser($accountId)
                ->select(['id', 'user_id', 'name', 'image', 'item_type']),
        ]);
    }

    private function mapReservationListItem(
        Reservation $reservation,
        bool $includeNotes,
        array $access,
        bool $ownerOnlyMode
    ): array {
        $clientDisplayName = $reservation->client?->company_name
            ?: trim(($reservation->client?->first_name ?? '').' '.($reservation->client?->last_name ?? ''));
        $permissions = $this->reservationListPermissions($reservation, $access, $ownerOnlyMode);
        $item = [
            'id' => (int) $reservation->id,
            'team_member_id' => $reservation->teamMember?->id
                ? (int) $reservation->teamMember->id
                : null,
            'client_id' => $reservation->client?->id ? (int) $reservation->client->id : null,
            'prospect_id' => $reservation->prospect?->id ? (int) $reservation->prospect->id : null,
            'service_id' => $reservation->service?->id ? (int) $reservation->service->id : null,
            'status' => (string) $reservation->status,
            'outcome_review_required_at' => $reservation->outcome_review_required_at?->toIso8601String(),
            'outcome_review_reason_code' => $reservation->outcome_review_reason_code,
            'source' => (string) $reservation->source,
            'timezone' => (string) $reservation->timezone,
            'starts_at' => $reservation->starts_at?->toIso8601String(),
            'ends_at' => $reservation->ends_at?->toIso8601String(),
            'duration_minutes' => (int) $reservation->duration_minutes,
            'buffer_minutes' => (int) $reservation->buffer_minutes,
            'client' => $reservation->client ? [
                'id' => (int) $reservation->client->id,
                'display_name' => $clientDisplayName ?: null,
                'first_name' => $reservation->client->first_name,
                'last_name' => $reservation->client->last_name,
                'company_name' => $reservation->client->company_name,
                'avatar_url' => $reservation->client->logo_url,
            ] : null,
            'prospect' => $reservation->prospect ? [
                'id' => (int) $reservation->prospect->id,
                'contact_name' => $reservation->prospect->contact_name,
            ] : null,
            'service' => $reservation->service ? [
                'id' => (int) $reservation->service->id,
                'name' => (string) $reservation->service->name,
                'image_url' => $reservation->service->image_url,
                'has_image' => $this->hasCustomServiceImage($reservation->service),
            ] : null,
            'team_member' => $reservation->teamMember ? [
                'id' => (int) $reservation->teamMember->id,
                'name' => $reservation->teamMember->user?->name,
                'title' => $reservation->teamMember->title,
                'avatar_url' => $reservation->teamMember->user?->profile_picture_url,
                'user' => $reservation->teamMember->user ? [
                    'name' => (string) $reservation->teamMember->user->name,
                ] : null,
            ] : null,
            'permissions' => $permissions,
        ];

        if ($includeNotes) {
            $item['internal_notes'] = $reservation->internal_notes;
            $item['client_notes'] = $reservation->client_notes;
        }

        return $item;
    }

    private function reservationListPermissions(
        Reservation $reservation,
        array $access,
        bool $ownerOnlyMode
    ): array {
        $ownTeamMemberId = (int) ($access['own_team_member_id'] ?? 0);
        $isAssigned = $ownTeamMemberId > 0
            && (int) $reservation->team_member_id === $ownTeamMemberId;
        $canView = (bool) ($access['can_view_all'] ?? false) || $isAssigned;
        $canManage = $canView
            && ! $ownerOnlyMode
            && (bool) ($access['can_manage'] ?? false);
        $canUpdateStatus = $canView
            && ! $ownerOnlyMode
            && ($canManage || $isAssigned);

        return [
            'can_view' => $canView,
            'can_edit' => $canManage,
            'can_delete' => $canManage,
            'can_update_status' => $canUpdateStatus,
            'can_convert' => $canManage && (bool) $reservation->prospect && ! $reservation->client_id,
            'allowed_status_transitions' => $this->allowedStatusTransitions($reservation, $canUpdateStatus),
        ];
    }

    private function allowedStatusTransitions(Reservation $reservation, bool $canUpdateStatus): array
    {
        if (! $canUpdateStatus) {
            return [];
        }

        $transitions = match ((string) $reservation->status) {
            Reservation::STATUS_PENDING => [Reservation::STATUS_CONFIRMED],
            Reservation::STATUS_CONFIRMED => [Reservation::STATUS_PENDING],
            Reservation::STATUS_RESCHEDULED => [Reservation::STATUS_CONFIRMED, Reservation::STATUS_PENDING],
            default => [],
        };

        if (! in_array($reservation->status, Reservation::ACTIVE_STATUSES, true)) {
            return $transitions;
        }

        if ($reservation->ends_at && ! $reservation->ends_at->isFuture()) {
            if (in_array($reservation->status, [Reservation::STATUS_CONFIRMED, Reservation::STATUS_RESCHEDULED], true)) {
                $transitions[] = Reservation::STATUS_COMPLETED;
            }
        }

        if ($reservation->starts_at && ! $reservation->starts_at->isFuture()) {
            $transitions[] = Reservation::STATUS_NO_SHOW;
        }

        $transitions[] = Reservation::STATUS_CANCELLED;

        return array_values(array_unique($transitions));
    }

    private function hasCustomServiceImage(Product $service): bool
    {
        $path = ltrim(trim((string) $service->image), '/');

        return $path !== '' && ! in_array($path, [
            Product::LEGACY_DEFAULT_IMAGE_PATH,
            Product::DEFAULT_PRODUCT_IMAGE_PATH,
            Product::DEFAULT_SERVICE_IMAGE_PATH,
        ], true);
    }

    private function reservationEventQuery(int $accountId): Builder
    {
        return Reservation::query()
            ->forAccount($accountId)
            ->with([
                'teamMember' => fn (BelongsTo $query) => $query
                    ->forAccount($accountId)
                    ->select(['id', 'account_id', 'user_id']),
                'teamMember.user:id,name',
                'client' => fn (BelongsTo $query) => $query
                    ->byUser($accountId)
                    ->select(['id', 'user_id', 'first_name', 'last_name', 'company_name']),
                'prospect' => fn (BelongsTo $query) => $query
                    ->byUser($accountId)
                    ->select(['id', 'user_id', 'contact_name']),
                'service' => fn (BelongsTo $query) => $query
                    ->byUser($accountId)
                    ->select(['id', 'user_id', 'name']),
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
        if (! in_array($sort, [
            'date_asc',
            'date_desc',
            'status',
            'status_asc',
            'status_desc',
            'client_asc',
            'client_desc',
            'service_asc',
            'service_desc',
            'team_member_asc',
            'team_member_desc',
        ], true)) {
            $sort = 'date_asc';
        }

        $teamMemberId = $request->input('team_member_id');
        if ($scope === 'mine' && $ownTeamMemberId) {
            $teamMemberId = (string) $ownTeamMemberId;
        } elseif (! $canViewAll) {
            $teamMemberId = $ownTeamMemberId ? (string) $ownTeamMemberId : '';
        } else {
            $teamMemberId = $this->normalizePositiveId($teamMemberId);
        }

        $status = (string) $request->input('status', '');
        if ($status !== '' && ! in_array($status, Reservation::STATUSES, true)) {
            $status = '';
        }

        $viewMode = (string) $request->input('view_mode', 'calendar');
        if (! in_array($viewMode, ['calendar', 'list'], true)) {
            $viewMode = 'calendar';
        }

        $dateFrom = $this->normalizeDate($request->input('date_from'));
        $dateTo = $this->normalizeDate($request->input('date_to'));

        return [
            'status' => $status,
            'team_member_id' => (string) ($teamMemberId ?? ''),
            'service_id' => $this->normalizePositiveId($request->input('service_id')),
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'search' => Str::limit(trim((string) ($request->input('search', '') ?? '')), 120, ''),
            'view_mode' => $viewMode,
            'scope' => $scope,
            'quick' => $quick,
            'sort' => $sort,
            'per_page' => DataTablePagination::fromRequest($request),
        ];
    }

    private function normalizePositiveId(mixed $value): string
    {
        $normalized = trim((string) ($value ?? ''));
        if ($normalized === '' || ! ctype_digit($normalized)) {
            return '';
        }

        $id = (int) $normalized;

        return $id > 0 ? (string) $id : '';
    }

    private function normalizeDate(mixed $value): string
    {
        $normalized = trim((string) ($value ?? ''));
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $normalized)) {
            return '';
        }

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $normalized, 'UTC');
        } catch (\Throwable) {
            return '';
        }

        return $date && $date->format('Y-m-d') === $normalized ? $normalized : '';
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function localDayBounds(string $date, string $timezone): array
    {
        $start = Carbon::createFromFormat('!Y-m-d', $date, $timezone)->startOfDay();

        return [
            $start->copy()->utc(),
            $start->copy()->addDay()->utc(),
        ];
    }

    private function applyReservationFilters(
        Builder $query,
        array $filters,
        array $access,
        int $accountId,
        string $accountTimezone,
        array $options = []
    ): void {
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
            $query->where('reservations.team_member_id', (int) $ownTeamMemberId);
        }

        if ($options['team'] && ! empty($filters['team_member_id'])) {
            $teamMemberId = (int) $filters['team_member_id'];
            if ($teamMemberId > 0) {
                if ($canViewAll) {
                    $query->whereHas('teamMember', fn (Builder $teamMemberQuery) => $teamMemberQuery
                        ->forAccount($accountId)
                        ->whereKey($teamMemberId));
                } elseif ($ownTeamMemberId && $teamMemberId === (int) $ownTeamMemberId) {
                    $query->where('reservations.team_member_id', $teamMemberId);
                }
            }
        }

        if ($options['service'] && ! empty($filters['service_id'])) {
            $serviceId = (int) $filters['service_id'];
            $query->whereHas('service', fn (Builder $serviceQuery) => $serviceQuery
                ->byUser($accountId)
                ->whereKey($serviceId));
        }

        if ($options['status'] && ! empty($filters['status'])) {
            $query->where('reservations.status', (string) $filters['status']);
        }

        if ($options['date']) {
            if (! empty($filters['date_from'])) {
                [$rangeStart] = $this->localDayBounds((string) $filters['date_from'], $accountTimezone);
                $query->where('reservations.starts_at', '>=', $rangeStart);
            }
            if (! empty($filters['date_to'])) {
                [, $rangeEnd] = $this->localDayBounds((string) $filters['date_to'], $accountTimezone);
                $query->where('reservations.starts_at', '<', $rangeEnd);
            }
        }

        if ($options['search'] && ! empty($filters['search'])) {
            $search = (string) $filters['search'];
            $query->where(function (Builder $subQuery) use ($search, $accountId) {
                $subQuery->whereHas('client', function (Builder $clientQuery) use ($search, $accountId) {
                    $clientQuery->byUser($accountId)
                        ->where(function (Builder $clientFields) use ($search) {
                            $clientFields->where('company_name', 'like', '%'.$search.'%')
                                ->orWhere('first_name', 'like', '%'.$search.'%')
                                ->orWhere('last_name', 'like', '%'.$search.'%')
                                ->orWhere('email', 'like', '%'.$search.'%');
                        });
                })->orWhereHas('service', function (Builder $serviceQuery) use ($search, $accountId) {
                    $serviceQuery->byUser($accountId)
                        ->where('name', 'like', '%'.$search.'%');
                })->orWhereHas('prospect', function (Builder $prospectQuery) use ($search, $accountId) {
                    $prospectQuery->byUser($accountId)
                        ->where(function (Builder $prospectFields) use ($search) {
                            $prospectFields->where('contact_name', 'like', '%'.$search.'%')
                                ->orWhere('contact_email', 'like', '%'.$search.'%')
                                ->orWhere('contact_phone', 'like', '%'.$search.'%');
                        });
                });
            });
        }

        if ($options['quick']) {
            $quick = (string) ($filters['quick'] ?? '');
            if ($quick === 'pending') {
                $query->where('reservations.status', Reservation::STATUS_PENDING);
            } elseif ($quick === 'today') {
                [$todayStart, $tomorrowStart] = $this->localDayBounds(
                    now($accountTimezone)->toDateString(),
                    $accountTimezone
                );
                $query->where('reservations.starts_at', '>=', $todayStart)
                    ->where('reservations.starts_at', '<', $tomorrowStart);
            } elseif ($quick === 'upcoming') {
                $query->where('reservations.starts_at', '>', now())
                    ->whereIn('reservations.status', Reservation::ACTIVE_STATUSES);
            } elseif ($quick === 'past') {
                $query->where('reservations.ends_at', '<', now());
            }
        }
    }

    private function applyReservationSort(Builder $query, string $sort, int $accountId): void
    {
        if ($sort === 'date_desc') {
            $query->orderByDesc('reservations.starts_at')
                ->orderByDesc('reservations.id');

            return;
        }

        if (in_array($sort, ['status', 'status_asc', 'status_desc'], true)) {
            $direction = $sort === 'status_desc' ? 'DESC' : 'ASC';
            $query->orderByRaw("CASE reservations.status
                WHEN 'pending' THEN 1
                WHEN 'confirmed' THEN 2
                WHEN 'rescheduled' THEN 3
                WHEN 'completed' THEN 4
                WHEN 'no_show' THEN 5
                WHEN 'cancelled' THEN 6
                WHEN 'expired' THEN 7
                ELSE 99
            END {$direction}");
            $query->orderBy('reservations.starts_at')
                ->orderBy('reservations.id');

            return;
        }

        if (in_array($sort, ['client_asc', 'client_desc'], true)) {
            $direction = $sort === 'client_desc' ? 'desc' : 'asc';
            $query
                ->leftJoin('customers as reservation_sort_clients', function ($join) use ($accountId) {
                    $join->on('reservation_sort_clients.id', '=', 'reservations.client_id')
                        ->where('reservation_sort_clients.user_id', '=', $accountId);
                })
                ->leftJoin('requests as reservation_sort_prospects', function ($join) use ($accountId) {
                    $join->on('reservation_sort_prospects.id', '=', 'reservations.prospect_id')
                        ->where('reservation_sort_prospects.user_id', '=', $accountId);
                })
                ->orderByRaw(
                    "COALESCE(NULLIF(reservation_sort_clients.company_name, ''), NULLIF(reservation_sort_clients.last_name, ''), NULLIF(reservation_sort_clients.first_name, ''), NULLIF(reservation_sort_prospects.contact_name, ''), '') {$direction}"
                )
                ->orderBy('reservations.starts_at')
                ->orderBy('reservations.id');

            return;
        }

        if (in_array($sort, ['service_asc', 'service_desc'], true)) {
            $direction = $sort === 'service_desc' ? 'desc' : 'asc';
            $query
                ->leftJoin('products as reservation_sort_services', function ($join) use ($accountId) {
                    $join->on('reservation_sort_services.id', '=', 'reservations.service_id')
                        ->where('reservation_sort_services.user_id', '=', $accountId);
                })
                ->orderBy('reservation_sort_services.name', $direction)
                ->orderBy('reservations.starts_at')
                ->orderBy('reservations.id');

            return;
        }

        if (in_array($sort, ['team_member_asc', 'team_member_desc'], true)) {
            $direction = $sort === 'team_member_desc' ? 'desc' : 'asc';
            $query
                ->leftJoin('team_members as reservation_sort_members', function ($join) use ($accountId) {
                    $join->on('reservation_sort_members.id', '=', 'reservations.team_member_id')
                        ->where('reservation_sort_members.account_id', '=', $accountId);
                })
                ->leftJoin('users as reservation_sort_member_users', function ($join) {
                    $join->on('reservation_sort_member_users.id', '=', 'reservation_sort_members.user_id');
                })
                ->orderBy('reservation_sort_member_users.name', $direction)
                ->orderBy('reservations.starts_at')
                ->orderBy('reservations.id');

            return;
        }

        $query->orderBy('reservations.starts_at')
            ->orderBy('reservations.id');
    }

    private function quickCounts(
        int $accountId,
        array $filters,
        array $access,
        string $accountTimezone
    ): array {
        $summaryQuery = $this->reservationQuery($accountId, false)
            ->tap(fn (Builder $builder) => $this->applyReservationFilters(
                $builder,
                $filters,
                $access,
                $accountId,
                $accountTimezone,
                [
                    'status' => false,
                    'date' => false,
                    'quick' => false,
                ]
            ));

        [$todayStart, $tomorrowStart] = $this->localDayBounds(
            now($accountTimezone)->toDateString(),
            $accountTimezone
        );

        return [
            'pending' => (clone $summaryQuery)->where('status', Reservation::STATUS_PENDING)->count(),
            'today' => (clone $summaryQuery)
                ->where('starts_at', '>=', $todayStart)
                ->where('starts_at', '<', $tomorrowStart)
                ->count(),
            'upcoming' => (clone $summaryQuery)
                ->where('starts_at', '>', now())
                ->whereIn('status', Reservation::ACTIVE_STATUSES)
                ->count(),
            'past' => (clone $summaryQuery)->where('ends_at', '<', now())->count(),
        ];
    }

    private function buildPerformanceMetrics(
        User $account,
        array $filters,
        array $access,
        array $settings,
        string $accountTimezone
    ): array {
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
            ->tap(fn (Builder $builder) => $this->applyReservationFilters(
                $builder,
                $filters,
                $access,
                $account->id,
                $accountTimezone,
                [
                    'search' => false,
                    'status' => false,
                    'date' => false,
                    'quick' => false,
                ]
            ))
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
            ->leftJoin('products', function ($join) use ($account) {
                $join->on('reservations.service_id', '=', 'products.id')
                    ->where('products.user_id', '=', $account->id);
            })
            ->avg('products.price')), 2);

        $teamMemberIds = $this->resolvePerformanceTeamMemberIds($account->id, $filters, $access);
        $teamUserIds = TeamMember::query()
            ->forAccount($account->id)
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

        $item = [
            'id' => $waitlist->id,
            'status' => $waitlist->status,
            'client_name' => $clientName ?: ($waitlist->client?->email ?? null),
            'service_id' => $waitlist->service?->id ? (int) $waitlist->service->id : null,
            'service_name' => $waitlist->service?->name,
            'team_member_id' => $waitlist->teamMember?->id ? (int) $waitlist->teamMember->id : null,
            'team_member_name' => $waitlist->teamMember?->user?->name,
            'requested_start_at' => $waitlist->requested_start_at?->toIso8601String(),
            'requested_end_at' => $waitlist->requested_end_at?->toIso8601String(),
            'duration_minutes' => (int) ($waitlist->duration_minutes ?? 0),
            'party_size' => $waitlist->party_size,
            'can_update_status' => $this->canManageWaitlistStatus($access, $waitlist),
            'created_at' => $waitlist->created_at?->toIso8601String(),
        ];

        if ($access['can_manage'] ?? false) {
            $item['notes'] = $waitlist->notes;
            $item['resource_filters'] = $waitlist->resource_filters;
        }

        return $item;
    }

    private function mapEvent(Reservation $reservation): array
    {
        $clientLabel = $reservation->client?->company_name
            ?: trim(($reservation->client?->first_name ?? '').' '.($reservation->client?->last_name ?? ''));
        if (! $clientLabel) {
            $clientLabel = $reservation->prospect?->contact_name;
        }
        $serviceLabel = $reservation->service?->name;
        $memberLabel = $reservation->teamMember?->user?->name;
        $title = implode(' · ', array_values(array_filter([
            $serviceLabel,
            $clientLabel,
        ], fn ($label) => filled($label))));

        return [
            'id' => $reservation->id,
            'title' => $title ?: null,
            'start' => $reservation->starts_at?->toIso8601String(),
            'end' => $reservation->ends_at?->toIso8601String(),
            'classNames' => ['reservation-event', 'status-'.$reservation->status],
            'extendedProps' => [
                'status' => $reservation->status,
                'outcome_review_required_at' => $reservation->outcome_review_required_at?->toIso8601String(),
                'outcome_review_reason_code' => $reservation->outcome_review_reason_code,
                'team_member_id' => $reservation->teamMember?->id,
                'team_member_name' => $memberLabel ?: null,
                'client_name' => $clientLabel ?: null,
                'service_name' => $serviceLabel ?: null,
                'source' => $reservation->source,
            ],
        ];
    }
}
