<?php

namespace App\Http\Controllers\Reservation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reservation\SlotRequest;
use App\Http\Requests\Reservation\StoreReservationRequest;
use App\Http\Requests\Reservation\UpdateReservationRequest;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\ReservationAvailabilityService;
use App\Services\ReservationNotificationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StaffReservationController extends Controller
{
    public function __construct(
        private readonly ReservationAvailabilityService $availabilityService,
        private readonly ReservationNotificationService $notificationService
    ) {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        $this->authorize('viewAny', Reservation::class);

        $account = $this->resolveAccount($user);
        $access = $this->resolveTeamAccess($user, $account->id);
        $filters = $this->normalizeFilters($request, $access);

        $query = $this->reservationQuery($account->id)
            ->tap(fn (Builder $builder) => $this->applyReservationFilters($builder, $filters, $access));
        $this->applyReservationSort($query, $filters['sort']);

        $reservations = (clone $query)
            ->simplePaginate(20)
            ->withQueryString();

        $eventsQuery = $this->reservationQuery($account->id)
            ->tap(fn (Builder $builder) => $this->applyReservationFilters($builder, $filters, $access));

        $events = $eventsQuery
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
        if (!$access['can_view_all'] && $access['own_team_member_id']) {
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

        return $this->inertiaOrJson('Reservation/Index', [
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
        ]);
    }

    public function events(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }
        $this->authorize('viewAny', Reservation::class);
        $account = $this->resolveAccount($user);
        $access = $this->resolveTeamAccess($user, $account->id);

        $validated = $request->validate([
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after:start'],
            'team_member_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(Reservation::STATUSES)],
            'service_id' => ['nullable', 'integer'],
            'scope' => ['nullable', Rule::in(['mine', 'all'])],
            'quick' => ['nullable', Rule::in(['pending', 'today', 'upcoming', 'past'])],
        ]);

        $filters = $this->normalizeFilters($request, $access);

        $events = $this->reservationQuery($account->id)
            ->tap(fn (Builder $builder) => $this->applyReservationFilters($builder, $filters, $access, [
                'search' => false,
                'date' => false,
            ]))
            ->where('starts_at', '<', $validated['end'])
            ->where('ends_at', '>', $validated['start'])
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Reservation $reservation) => $this->mapEvent($reservation))
            ->values();

        return response()->json([
            'events' => $events,
        ]);
    }

    public function slots(SlotRequest $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }
        $this->authorize('viewAny', Reservation::class);
        $account = $this->resolveAccount($user);

        $validated = $request->validated();
        $durationMinutes = $this->availabilityService->resolveDurationMinutes(
            $account->id,
            isset($validated['service_id']) ? (int) $validated['service_id'] : null,
            isset($validated['duration_minutes']) ? (int) $validated['duration_minutes'] : null
        );

        $result = $this->availabilityService->generateSlots(
            $account->id,
            \Illuminate\Support\Carbon::parse($validated['range_start'])->utc(),
            \Illuminate\Support\Carbon::parse($validated['range_end'])->utc(),
            $durationMinutes,
            isset($validated['team_member_id']) ? (int) $validated['team_member_id'] : null
        );

        return response()->json([
            'timezone' => $result['timezone'],
            'duration_minutes' => $durationMinutes,
            'slots' => $result['slots'],
        ]);
    }

    public function store(StoreReservationRequest $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }
        $this->authorize('create', Reservation::class);
        $account = $this->resolveAccount($user);

        $validated = $request->validated();
        $reservation = $this->availabilityService->book([
            ...$validated,
            'account_id' => $account->id,
            'source' => Reservation::SOURCE_STAFF,
            'status' => $validated['status'] ?? Reservation::STATUS_CONFIRMED,
        ], $user);
        $this->notificationService->handleCreated($reservation, $user);

        $reservation->load([
            'teamMember.user:id,name',
            'client:id,first_name,last_name,company_name,email,phone',
            'service:id,name,price',
        ]);

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Reservation created successfully.',
                'reservation' => $reservation,
            ], 201);
        }

        return redirect()->route('reservation.index')->with('success', 'Reservation created successfully.');
    }

    public function update(UpdateReservationRequest $request, Reservation $reservation)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }
        $this->authorize('update', $reservation);
        $account = $this->resolveAccount($user);
        if ((int) $reservation->account_id !== (int) $account->id) {
            abort(404);
        }

        $validated = $request->validated();
        $reservation = $this->availabilityService->reschedule($reservation, $validated, $user);
        $this->notificationService->handleRescheduled($reservation, $user);

        $reservation->load([
            'teamMember.user:id,name',
            'client:id,first_name,last_name,company_name,email,phone',
            'service:id,name,price',
        ]);

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Reservation updated successfully.',
                'reservation' => $reservation,
            ]);
        }

        return redirect()->route('reservation.index')->with('success', 'Reservation updated successfully.');
    }

    public function updateStatus(Request $request, Reservation $reservation)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }
        $this->authorize('updateStatus', $reservation);

        $validated = $request->validate([
            'status' => ['required', Rule::in(Reservation::STATUSES)],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        if (
            in_array($validated['status'], [Reservation::STATUS_COMPLETED, Reservation::STATUS_NO_SHOW], true)
            && $reservation->starts_at
            && $reservation->starts_at->isFuture()
        ) {
            throw ValidationException::withMessages([
                'status' => ['You cannot complete or mark no-show on a future reservation.'],
            ]);
        }

        if (
            $validated['status'] === Reservation::STATUS_COMPLETED
            && $reservation->ends_at
            && $reservation->ends_at->isFuture()
        ) {
            throw ValidationException::withMessages([
                'status' => ['Reservation cannot be completed before its end time.'],
            ]);
        }

        $previousStatus = $reservation->status;
        $payload = [
            'status' => $validated['status'],
        ];

        if ($validated['status'] === Reservation::STATUS_CANCELLED) {
            $payload['cancelled_at'] = now();
            $payload['cancelled_by_user_id'] = $user->id;
            $payload['cancel_reason'] = $validated['reason'] ?? null;
        } else {
            $payload['cancelled_at'] = null;
            $payload['cancelled_by_user_id'] = null;
            $payload['cancel_reason'] = null;
        }

        $reservation->update($payload);
        $reservation->load(['teamMember.user:id,name', 'client:id,first_name,last_name,company_name', 'service:id,name,price']);
        $this->notificationService->handleStatusChanged($reservation, $user, $previousStatus);

        return response()->json([
            'message' => 'Reservation status updated.',
            'reservation' => $reservation,
        ]);
    }

    public function destroy(Request $request, Reservation $reservation)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }
        $this->authorize('delete', $reservation);

        $reservation->delete();

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Reservation deleted successfully.',
            ]);
        }

        return redirect()->route('reservation.index')->with('success', 'Reservation deleted successfully.');
    }

    private function resolveAccount(User $user): User
    {
        $accountId = $user->accountOwnerId();
        $account = $accountId === $user->id
            ? $user
            : User::query()->find($accountId);

        if (!$account) {
            abort(404);
        }

        return $account;
    }

    private function reservationQuery(int $accountId, bool $withRelations = true): Builder
    {
        $query = Reservation::query()->forAccount($accountId);
        if (!$withRelations) {
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

    private function resolveTeamAccess(User $user, int $accountId): array
    {
        $ownTeamMember = TeamMember::query()
            ->forAccount($accountId)
            ->active()
            ->where('user_id', $user->id)
            ->first();

        $canManage = $this->canManageReservations($user, $ownTeamMember);

        return [
            'own_team_member_id' => $ownTeamMember?->id,
            'can_view_all' => $canManage,
            'can_manage' => $canManage,
            'can_update_status' => $canManage || (bool) $ownTeamMember,
        ];
    }

    private function canManageReservations(User $user, ?TeamMember $teamMember): bool
    {
        if ($user->id === $user->accountOwnerId()) {
            return true;
        }

        if (!$teamMember) {
            return false;
        }

        if (in_array($teamMember->role, ['admin', 'sales_manager'], true)) {
            return true;
        }

        return $teamMember->hasPermission('jobs.edit') || $teamMember->hasPermission('tasks.edit');
    }

    private function normalizeFilters(Request $request, array $access): array
    {
        $ownTeamMemberId = $access['own_team_member_id'] ?: null;
        $canViewAll = (bool) ($access['can_view_all'] ?? false);

        $scope = (string) $request->input('scope', '');
        if (!in_array($scope, ['mine', 'all'], true)) {
            $scope = $ownTeamMemberId ? 'mine' : 'all';
        }
        if (!$canViewAll || !$ownTeamMemberId) {
            $scope = $ownTeamMemberId ? 'mine' : 'all';
        }

        $quick = (string) $request->input('quick', '');
        if (!in_array($quick, ['', 'pending', 'today', 'upcoming', 'past'], true)) {
            $quick = '';
        }

        $sort = (string) $request->input('sort', 'date_asc');
        if (!in_array($sort, ['date_asc', 'date_desc', 'status'], true)) {
            $sort = 'date_asc';
        }

        $teamMemberId = $request->input('team_member_id');
        if ($scope === 'mine' && $ownTeamMemberId) {
            $teamMemberId = (string) $ownTeamMemberId;
        } elseif (!$canViewAll) {
            $teamMemberId = $ownTeamMemberId ? (string) $ownTeamMemberId : '';
        }

        $status = (string) $request->input('status', '');
        if ($status !== '' && !in_array($status, Reservation::STATUSES, true)) {
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

        if ($options['team'] && !empty($filters['team_member_id'])) {
            $teamMemberId = (int) $filters['team_member_id'];
            if ($teamMemberId > 0) {
                if ($canViewAll) {
                    $query->where('team_member_id', $teamMemberId);
                } elseif ($ownTeamMemberId && $teamMemberId === (int) $ownTeamMemberId) {
                    $query->where('team_member_id', $teamMemberId);
                }
            }
        }

        if ($options['service'] && !empty($filters['service_id'])) {
            $query->where('service_id', (int) $filters['service_id']);
        }

        if ($options['status'] && !empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        if ($options['date']) {
            if (!empty($filters['date_from'])) {
                $query->whereDate('starts_at', '>=', (string) $filters['date_from']);
            }
            if (!empty($filters['date_to'])) {
                $query->whereDate('starts_at', '<=', (string) $filters['date_to']);
            }
        }

        if ($options['search'] && !empty($filters['search'])) {
            $search = (string) $filters['search'];
            $query->where(function (Builder $subQuery) use ($search) {
                $subQuery->whereHas('client', function (Builder $clientQuery) use ($search) {
                    $clientQuery->where('company_name', 'like', '%' . $search . '%')
                        ->orWhere('first_name', 'like', '%' . $search . '%')
                        ->orWhere('last_name', 'like', '%' . $search . '%')
                        ->orWhere('email', 'like', '%' . $search . '%');
                })->orWhereHas('service', function (Builder $serviceQuery) use ($search) {
                    $serviceQuery->where('name', 'like', '%' . $search . '%');
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

    private function mapEvent(Reservation $reservation): array
    {
        $clientLabel = $reservation->client?->company_name
            ?: trim(($reservation->client?->first_name ?? '') . ' ' . ($reservation->client?->last_name ?? ''));
        $serviceLabel = $reservation->service?->name ?: 'Reservation';
        $memberLabel = $reservation->teamMember?->user?->name ?: 'Team';
        $title = trim($serviceLabel . ' · ' . ($clientLabel ?: 'Client'));

        return [
            'id' => $reservation->id,
            'title' => $title,
            'start' => $reservation->starts_at?->toIso8601String(),
            'end' => $reservation->ends_at?->toIso8601String(),
            'classNames' => ['reservation-event', 'status-' . $reservation->status],
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
