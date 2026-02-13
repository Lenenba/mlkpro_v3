<?php

namespace App\Http\Controllers\Reservation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reservation\SlotRequest;
use App\Http\Requests\Reservation\StoreReservationRequest;
use App\Http\Requests\Reservation\UpdateReservationRequest;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\ReservationQueueItem;
use App\Models\ReservationResource;
use App\Models\ReservationResourceAllocation;
use App\Models\ReservationWaitlist;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\WeeklyAvailability;
use App\Services\ReservationAvailabilityService;
use App\Services\ReservationNotificationService;
use App\Services\ReservationQueueService;
use App\Support\ReservationPresetResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StaffReservationController extends Controller
{
    public function __construct(
        private readonly ReservationAvailabilityService $availabilityService,
        private readonly ReservationNotificationService $notificationService,
        private readonly ReservationQueueService $queueService
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
        $performance = $this->buildPerformanceMetrics($account, $filters, $access, $settings);
        $waitlistQuery = ReservationWaitlist::query()
            ->forAccount($account->id)
            ->with([
                'client:id,first_name,last_name,company_name,email',
                'service:id,name',
                'teamMember.user:id,name',
            ]);
        if (!$access['can_view_all'] && $access['own_team_member_id']) {
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
            'performance' => $performance,
            'waitlists' => $waitlists,
            'waitlistStats' => $waitlistStats,
            'queueItems' => $queuePayload['items'] ?? [],
            'queueStats' => $queuePayload['stats'] ?? ['waiting' => 0, 'called' => 0, 'in_service' => 0],
        ]);
    }

    public function screen(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        $this->authorize('viewAny', Reservation::class);
        $account = $this->resolveAccount($user);
        $access = $this->resolveTeamAccess($user, $account->id);
        $settings = $this->availabilityService->resolveSettings($account->id, null);
        if (!$this->queueFeaturesAvailable($settings)) {
            abort(404);
        }

        $anonymize = $request->boolean('anonymize', true);
        $mode = in_array((string) $request->input('mode', 'board'), ['board', 'tv'], true)
            ? (string) $request->input('mode', 'board')
            : 'board';
        $teamMembers = $this->screenTeamMembers($account->id, $access, $settings);
        $payload = $this->buildQueueScreenPayload($account->id, $access, $settings, $anonymize, $teamMembers);

        return $this->inertiaOrJson('Reservation/Screen', [
            'queue' => $payload,
            'teamMembers' => $teamMembers,
            'timezone' => $this->availabilityService->timezoneForAccount($account),
            'settings' => [
                'queue_mode_enabled' => $this->queueModeEnabled($settings),
                'queue_assignment_mode' => (string) ($settings['queue_assignment_mode'] ?? 'per_staff'),
                'business_preset' => (string) ($settings['business_preset'] ?? 'service_general'),
                'queue_grace_minutes' => (int) ($settings['queue_grace_minutes'] ?? 5),
            ],
            'screen' => [
                'anonymize_clients' => $anonymize,
                'mode' => $mode,
            ],
            'kiosk' => [
                'public_url' => $this->kioskPublicUrl($account->id, $settings),
            ],
        ]);
    }

    public function screenData(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        $this->authorize('viewAny', Reservation::class);
        $account = $this->resolveAccount($user);
        $access = $this->resolveTeamAccess($user, $account->id);
        $settings = $this->availabilityService->resolveSettings($account->id, null);
        if (!$this->queueFeaturesAvailable($settings)) {
            abort(404);
        }

        $anonymize = $request->boolean('anonymize', true);
        $teamMembers = $this->screenTeamMembers($account->id, $access, $settings);

        return response()->json([
            'queue' => $this->buildQueueScreenPayload($account->id, $access, $settings, $anonymize, $teamMembers),
            'fetched_at' => now('UTC')->toIso8601String(),
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
            Carbon::parse($validated['range_start'])->utc(),
            Carbon::parse($validated['range_end'])->utc(),
            $durationMinutes,
            isset($validated['team_member_id']) ? (int) $validated['team_member_id'] : null,
            isset($validated['party_size']) ? (int) $validated['party_size'] : null,
            $validated['resource_filters'] ?? null
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
            'metadata' => $this->availabilityService->metadataForStatusTransition($reservation, (string) $validated['status']),
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

    public function updateWaitlistStatus(Request $request, ReservationWaitlist $waitlist)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        $this->authorize('viewAny', Reservation::class);
        $account = $this->resolveAccount($user);
        if ((int) $waitlist->account_id !== (int) $account->id) {
            abort(404);
        }

        $access = $this->resolveTeamAccess($user, $account->id);
        if (!$this->canManageWaitlistStatus($access, $waitlist)) {
            abort(403);
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in(ReservationWaitlist::STATUSES)],
            'matched_reservation_id' => [
                'nullable',
                'integer',
                Rule::exists('reservations', 'id')->where(fn ($query) => $query->where('account_id', $account->id)),
            ],
        ]);

        $status = (string) $validated['status'];
        $payload = [
            'status' => $status,
        ];

        if ($status === ReservationWaitlist::STATUS_RELEASED) {
            $payload['released_at'] = now('UTC');
            $payload['cancelled_at'] = null;
            $payload['resolved_at'] = null;
            $payload['matched_reservation_id'] = null;
        } elseif ($status === ReservationWaitlist::STATUS_BOOKED) {
            $payload['released_at'] = $waitlist->released_at ?: now('UTC');
            $payload['resolved_at'] = now('UTC');
            $payload['cancelled_at'] = null;
            $payload['matched_reservation_id'] = $validated['matched_reservation_id'] ?? null;
        } elseif ($status === ReservationWaitlist::STATUS_CANCELLED) {
            $payload['cancelled_at'] = now('UTC');
            $payload['resolved_at'] = null;
            $payload['matched_reservation_id'] = null;
        } elseif ($status === ReservationWaitlist::STATUS_PENDING) {
            $payload['released_at'] = null;
            $payload['resolved_at'] = null;
            $payload['cancelled_at'] = null;
            $payload['matched_reservation_id'] = null;
        } elseif ($status === ReservationWaitlist::STATUS_EXPIRED) {
            $payload['resolved_at'] = now('UTC');
            $payload['cancelled_at'] = null;
            $payload['matched_reservation_id'] = null;
        }

        $waitlist->update($payload);
        $waitlist->load([
            'client:id,first_name,last_name,company_name,email',
            'service:id,name',
            'teamMember.user:id,name',
        ]);

        return response()->json([
            'message' => 'Waitlist status updated.',
            'waitlist' => $this->mapWaitlistEntry($waitlist, $access),
        ]);
    }

    public function queueCheckIn(Request $request, ReservationQueueItem $item)
    {
        return $this->updateQueueAction($request, $item, 'check_in');
    }

    public function queuePreCall(Request $request, ReservationQueueItem $item)
    {
        return $this->updateQueueAction($request, $item, 'pre_call');
    }

    public function queueCall(Request $request, ReservationQueueItem $item)
    {
        return $this->updateQueueAction($request, $item, 'call');
    }

    public function queueCallNext(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        $this->authorize('viewAny', Reservation::class);
        $account = $this->resolveAccount($user);
        $access = $this->resolveTeamAccess($user, $account->id);
        $settings = $this->availabilityService->resolveSettings($account->id, null);
        $this->ensureQueueModeEnabled($settings);

        $validated = $request->validate([
            'team_member_id' => [
                'nullable',
                'integer',
                Rule::exists('team_members', 'id')->where(fn ($query) => $query
                    ->where('account_id', $account->id)
                    ->where('is_active', true)),
            ],
        ]);

        $requestedTeamMemberId = !empty($validated['team_member_id'])
            ? (int) $validated['team_member_id']
            : null;

        $next = $this->queueService->nextCallableForStaff(
            $account->id,
            $access,
            $settings,
            $requestedTeamMemberId
        );

        if (!$next || empty($next['item'])) {
            return response()->json([
                'message' => 'No callable queue item is available right now.',
            ], 422);
        }

        /** @var ReservationQueueItem $item */
        $item = $next['item'];
        $context = [];
        if (!empty($next['team_member_id'])) {
            $context['team_member_id'] = (int) $next['team_member_id'];
        }

        $updated = $this->queueService->transition($item, 'call', $user, $settings, $context);
        $this->notificationService->handleQueueEvent($updated, 'queue_called', $user);
        $metrics = $this->queueService->refreshMetrics((int) $account->id, $settings);

        $clientName = $updated->client?->company_name
            ?: trim(($updated->client?->first_name ?? '') . ' ' . ($updated->client?->last_name ?? ''));
        if (!$clientName) {
            $clientName = trim((string) data_get($updated->metadata, 'guest_name'));
        }
        if (!$clientName) {
            $clientName = trim((string) data_get($updated->metadata, 'guest_phone'));
        }

        return response()->json([
            'message' => 'Queue item called.',
            'queue_item' => [
                'id' => $updated->id,
                'reservation_id' => $updated->reservation_id,
                'item_type' => $updated->item_type,
                'origin' => $updated->item_type === ReservationQueueItem::TYPE_APPOINTMENT ? 'booking' : 'walk_in',
                'source' => $updated->source,
                'queue_number' => $updated->queue_number,
                'status' => $updated->status,
                'client_name' => $clientName ?: ($updated->client?->email ?? null),
                'service_name' => $updated->service?->name,
                'team_member_id' => $updated->team_member_id,
                'team_member_name' => $updated->teamMember?->user?->name,
                'reservation_starts_at' => $updated->reservation?->starts_at?->toIso8601String(),
                'estimated_duration_minutes' => (int) ($updated->estimated_duration_minutes ?? 0),
                'position' => $updated->position,
                'eta_minutes' => $updated->eta_minutes,
                'callable' => (bool) ($metrics[$updated->id]['callable'] ?? false),
                'recommended_team_member_id' => $metrics[$updated->id]['recommended_team_member_id'] ?? null,
                'call_expires_at' => $updated->call_expires_at?->toIso8601String(),
                'can_update_status' => $this->canManageQueueItem($access, $updated, $settings),
            ],
        ]);
    }

    public function queueStart(Request $request, ReservationQueueItem $item)
    {
        return $this->updateQueueAction($request, $item, 'start');
    }

    public function queueDone(Request $request, ReservationQueueItem $item)
    {
        return $this->updateQueueAction($request, $item, 'done');
    }

    public function queueSkip(Request $request, ReservationQueueItem $item)
    {
        return $this->updateQueueAction($request, $item, 'skip');
    }

    private function updateQueueAction(Request $request, ReservationQueueItem $item, string $action)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        $this->authorize('viewAny', Reservation::class);
        $account = $this->resolveAccount($user);
        if ((int) $item->account_id !== (int) $account->id) {
            abort(404);
        }

        $access = $this->resolveTeamAccess($user, $account->id);
        $settings = $this->availabilityService->resolveSettings($account->id, null);
        $this->ensureQueueModeEnabled($settings);
        if (!$this->canManageQueueItem($access, $item, $settings)) {
            abort(403);
        }

        $validated = $request->validate([
            'team_member_id' => [
                'nullable',
                'integer',
                Rule::exists('team_members', 'id')->where(fn ($query) => $query
                    ->where('account_id', $account->id)
                    ->where('is_active', true)),
            ],
        ]);

        $context = [];
        if (!empty($validated['team_member_id'])) {
            $context['team_member_id'] = (int) $validated['team_member_id'];
        } elseif (($access['own_team_member_id'] ?? null) && $item->team_member_id === null) {
            $context['team_member_id'] = (int) $access['own_team_member_id'];
        }

        $updated = $this->queueService->transition($item, $action, $user, $settings, $context);

        $queueEvent = match ($action) {
            'pre_call' => 'queue_pre_call',
            'call' => 'queue_called',
            default => null,
        };
        if ($queueEvent) {
            $this->notificationService->handleQueueEvent($updated, $queueEvent, $user);
        }

        $metrics = $this->queueService->refreshMetrics((int) $account->id, $settings);

        $clientName = $updated->client?->company_name
            ?: trim(($updated->client?->first_name ?? '') . ' ' . ($updated->client?->last_name ?? ''));

        return response()->json([
            'message' => 'Queue item updated.',
            'queue_item' => [
                'id' => $updated->id,
                'reservation_id' => $updated->reservation_id,
                'item_type' => $updated->item_type,
                'origin' => $updated->item_type === ReservationQueueItem::TYPE_APPOINTMENT ? 'booking' : 'walk_in',
                'source' => $updated->source,
                'queue_number' => $updated->queue_number,
                'status' => $updated->status,
                'client_name' => $clientName ?: ($updated->client?->email ?? null),
                'service_name' => $updated->service?->name,
                'team_member_id' => $updated->team_member_id,
                'team_member_name' => $updated->teamMember?->user?->name,
                'reservation_starts_at' => $updated->reservation?->starts_at?->toIso8601String(),
                'estimated_duration_minutes' => (int) ($updated->estimated_duration_minutes ?? 0),
                'position' => $updated->position,
                'eta_minutes' => $updated->eta_minutes,
                'callable' => (bool) ($metrics[$updated->id]['callable'] ?? false),
                'recommended_team_member_id' => $metrics[$updated->id]['recommended_team_member_id'] ?? null,
                'call_expires_at' => $updated->call_expires_at?->toIso8601String(),
                'can_update_status' => $this->canManageQueueItem($access, $updated, $settings),
            ],
        ]);
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

        if ($teamMember->role === 'admin') {
            return true;
        }

        return $teamMember->hasPermission('reservations.manage');
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

        if (!empty($teamUserIds)) {
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
                ->when(!empty($teamMemberIds), function ($query) use ($teamMemberIds) {
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
        } elseif (!$canViewAll && $ownTeamMemberId > 0) {
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
        if (!$ownTeamMemberId) {
            return false;
        }

        return (int) ($waitlist->team_member_id ?? 0) === $ownTeamMemberId;
    }

    private function canManageQueueItem(array $access, ReservationQueueItem $item, ?array $settings = null): bool
    {
        if ($access['can_manage'] ?? false) {
            return true;
        }

        $ownTeamMemberId = (int) ($access['own_team_member_id'] ?? 0);
        if (!$ownTeamMemberId) {
            return false;
        }

        if ((int) ($item->team_member_id ?? 0) === $ownTeamMemberId) {
            return true;
        }

        return $item->team_member_id === null
            && (string) ($item->item_type ?? '') === ReservationQueueItem::TYPE_TICKET;
    }

    private function queueFeaturesAvailable(array $settings): bool
    {
        return ReservationPresetResolver::queueFeaturesEnabled((string) ($settings['business_preset'] ?? null));
    }

    private function queueModeEnabled(array $settings): bool
    {
        return $this->queueFeaturesAvailable($settings)
            && (bool) ($settings['queue_mode_enabled'] ?? false);
    }

    private function ensureQueueModeEnabled(array $settings): void
    {
        if (!$this->queueFeaturesAvailable($settings)) {
            throw ValidationException::withMessages([
                'queue' => ['Hybrid queue is only available for salon businesses.'],
            ]);
        }

        if (!($settings['queue_mode_enabled'] ?? false)) {
            throw ValidationException::withMessages([
                'queue' => ['Queue mode is disabled for this account.'],
            ]);
        }
    }

    private function mapWaitlistEntry(ReservationWaitlist $waitlist, array $access): array
    {
        $clientName = $waitlist->client?->company_name
            ?: trim(($waitlist->client?->first_name ?? '') . ' ' . ($waitlist->client?->last_name ?? ''));

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

    private function buildQueueScreenPayload(
        int $accountId,
        array $access,
        array $settings,
        bool $anonymizeClients,
        array $teamMembers = []
    ): array
    {
        $board = $this->queueService->boardForStaff($accountId, $access, $settings);
        $assignmentMode = strtolower(trim((string) ($settings['queue_assignment_mode'] ?? 'per_staff')));
        $statuses = [
            ReservationQueueItem::STATUS_NOT_ARRIVED,
            ReservationQueueItem::STATUS_CHECKED_IN,
            ReservationQueueItem::STATUS_PRE_CALLED,
            ReservationQueueItem::STATUS_CALLED,
            ReservationQueueItem::STATUS_SKIPPED,
            ReservationQueueItem::STATUS_IN_SERVICE,
        ];

        $items = collect($board['items'] ?? [])
            ->filter(fn (array $item) => in_array((string) ($item['status'] ?? ''), $statuses, true))
            ->sortBy(function (array $item) use ($assignmentMode) {
                $statusWeight = match ((string) ($item['status'] ?? '')) {
                    ReservationQueueItem::STATUS_IN_SERVICE => 1,
                    ReservationQueueItem::STATUS_CALLED => 2,
                    ReservationQueueItem::STATUS_PRE_CALLED => 3,
                    ReservationQueueItem::STATUS_CHECKED_IN => 4,
                    ReservationQueueItem::STATUS_SKIPPED => 5,
                    ReservationQueueItem::STATUS_NOT_ARRIVED => 6,
                    default => 99,
                };
                $position = is_numeric($item['position'] ?? null) ? (int) $item['position'] : 999;
                $teamMemberId = $assignmentMode === ReservationQueueService::ASSIGNMENT_MODE_GLOBAL_PULL
                    ? 0
                    : (is_numeric($item['team_member_id'] ?? null) ? (int) $item['team_member_id'] : 999999);

                return sprintf('%02d-%06d-%04d-%010d', $statusWeight, $teamMemberId, $position, (int) ($item['id'] ?? 0));
            })
            ->values()
            ->map(function (array $item) use ($anonymizeClients) {
                $clientName = (string) ($item['client_name'] ?? '');
                return [
                    'id' => $item['id'],
                    'queue_number' => $item['queue_number'] ?: ('#' . $item['id']),
                    'item_type' => $item['item_type'],
                    'origin' => $item['origin'] ?? ($item['item_type'] === ReservationQueueItem::TYPE_APPOINTMENT ? 'booking' : 'walk_in'),
                    'source' => $item['source'] ?? null,
                    'status' => $item['status'],
                    'client_name' => $clientName,
                    'display_client_name' => $anonymizeClients
                        ? $this->anonymizeClientLabel($clientName)
                        : ($clientName !== '' ? $clientName : '-'),
                    'service_name' => $item['service_name'] ?: '-',
                    'team_member_id' => $item['team_member_id'] ?? null,
                    'team_member_name' => $item['team_member_name'] ?: '-',
                    'position' => $item['position'],
                    'eta_minutes' => $item['eta_minutes'],
                    'estimated_duration_minutes' => $item['estimated_duration_minutes'] ?? null,
                    'checked_in_at' => $item['checked_in_at'] ?? null,
                    'called_at' => $item['called_at'] ?? null,
                    'started_at' => $item['started_at'] ?? null,
                    'call_expires_at' => $item['call_expires_at'] ?? null,
                    'reservation_starts_at' => $item['reservation_starts_at'] ?? null,
                ];
            })
            ->values();

        $nowServing = $items->first(fn (array $item) => in_array($item['status'], [
            ReservationQueueItem::STATUS_IN_SERVICE,
            ReservationQueueItem::STATUS_CALLED,
            ReservationQueueItem::STATUS_PRE_CALLED,
        ], true));

        $upNext = $items
            ->filter(fn (array $item) => in_array($item['status'], [
                ReservationQueueItem::STATUS_CHECKED_IN,
                ReservationQueueItem::STATUS_SKIPPED,
                ReservationQueueItem::STATUS_NOT_ARRIVED,
            ], true))
            ->values()
            ->first();

        $waiting = $items
            ->filter(fn (array $item) => in_array($item['status'], [
                ReservationQueueItem::STATUS_CHECKED_IN,
                ReservationQueueItem::STATUS_PRE_CALLED,
                ReservationQueueItem::STATUS_CALLED,
                ReservationQueueItem::STATUS_SKIPPED,
                ReservationQueueItem::STATUS_NOT_ARRIVED,
            ], true))
            ->take(15)
            ->values()
            ->all();

        return [
            'stats' => $board['stats'] ?? ['waiting' => 0, 'called' => 0, 'in_service' => 0],
            'assignment_mode' => $assignmentMode,
            'items' => $items->all(),
            'chairs' => $this->buildChairCards($items, $teamMembers, $assignmentMode),
            'now_serving' => $nowServing,
            'up_next' => $upNext,
            'waiting' => $waiting,
            'total_active' => (int) $items->count(),
            'generated_at' => now('UTC')->toIso8601String(),
        ];
    }

    /**
     * @param \Illuminate\Support\Collection<int, array<string, mixed>> $items
     * @param array<int, array<string, mixed>> $teamMembers
     * @return array<int, array<string, mixed>>
     */
    private function buildChairCards(\Illuminate\Support\Collection $items, array $teamMembers, string $assignmentMode): array
    {
        if (empty($teamMembers)) {
            return [];
        }

        $waitingStatuses = [
            ReservationQueueItem::STATUS_CHECKED_IN,
            ReservationQueueItem::STATUS_PRE_CALLED,
            ReservationQueueItem::STATUS_CALLED,
            ReservationQueueItem::STATUS_SKIPPED,
            ReservationQueueItem::STATUS_NOT_ARRIVED,
        ];

        $globalWaitingPool = $items
            ->filter(fn (array $item) => in_array((string) ($item['status'] ?? ''), $waitingStatuses, true))
            ->sortBy(function (array $item) {
                $position = is_numeric($item['position'] ?? null) ? (int) $item['position'] : 99999;
                $eta = is_numeric($item['eta_minutes'] ?? null) ? (int) $item['eta_minutes'] : 99999;

                return sprintf('%06d-%06d-%010d', $position, $eta, (int) ($item['id'] ?? 0));
            })
            ->values();

        return collect($teamMembers)
            ->values()
            ->map(function (array $member, int $index) use ($items, $globalWaitingPool, $waitingStatuses, $assignmentMode) {
                $memberId = (int) ($member['id'] ?? 0);
                $isPresent = (bool) ($member['is_present'] ?? true);
                $memberItems = $items
                    ->filter(fn (array $item) => (int) ($item['team_member_id'] ?? 0) === $memberId)
                    ->values();

                $current = $memberItems->first(fn (array $item) => in_array((string) ($item['status'] ?? ''), [
                    ReservationQueueItem::STATUS_IN_SERVICE,
                    ReservationQueueItem::STATUS_CALLED,
                    ReservationQueueItem::STATUS_PRE_CALLED,
                ], true));

                $next = $memberItems
                    ->filter(fn (array $item) => in_array((string) ($item['status'] ?? ''), $waitingStatuses, true))
                    ->filter(fn (array $item) => !$current || (int) ($item['id'] ?? 0) !== (int) ($current['id'] ?? 0))
                    ->sortBy(function (array $item) {
                        $position = is_numeric($item['position'] ?? null) ? (int) $item['position'] : 99999;
                        $eta = is_numeric($item['eta_minutes'] ?? null) ? (int) $item['eta_minutes'] : 99999;

                        return sprintf('%06d-%06d-%010d', $position, $eta, (int) ($item['id'] ?? 0));
                    })
                    ->values()
                    ->first();

                if (!$next && $assignmentMode === ReservationQueueService::ASSIGNMENT_MODE_GLOBAL_PULL) {
                    $next = $globalWaitingPool
                        ->filter(fn (array $item) => (int) ($item['team_member_id'] ?? 0) === 0)
                        ->values()
                        ->first();
                }

                $state = 'available';
                if ($current && (string) ($current['status'] ?? '') === ReservationQueueItem::STATUS_IN_SERVICE) {
                    $state = 'in_service';
                } elseif ($current && in_array((string) ($current['status'] ?? ''), [
                    ReservationQueueItem::STATUS_CALLED,
                    ReservationQueueItem::STATUS_PRE_CALLED,
                ], true)) {
                    $state = 'called';
                } elseif (!$isPresent) {
                    $state = 'blocked';
                } elseif ($next && (string) ($next['status'] ?? '') === ReservationQueueItem::STATUS_NOT_ARRIVED) {
                    $state = 'check_in_needed';
                } elseif ($next) {
                    $state = 'available_ready';
                }

                return [
                    'id' => $memberId,
                    'chair_number' => $index + 1,
                    'chair_label' => 'Chair ' . ($index + 1),
                    'team_member_name' => (string) ($member['name'] ?? 'Member'),
                    'team_member_title' => $member['title'] ?? null,
                    'is_present' => $isPresent,
                    'state' => $state,
                    'current' => $current,
                    'next' => $next,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id: int, name: string, title: string|null, is_present: bool}>
     */
    private function screenTeamMembers(int $accountId, array $access, array $settings = []): array
    {
        $query = TeamMember::query()
            ->forAccount($accountId)
            ->active()
            ->with('user:id,name');

        if (!($access['can_view_all'] ?? false) && !empty($access['own_team_member_id'])) {
            $query->whereKey((int) $access['own_team_member_id']);
        }

        $members = $query
            ->orderBy('id')
            ->get()
            ->values();

        if (!$this->queueFeaturesAvailable($settings)) {
            return $members
                ->map(fn (TeamMember $member) => [
                    'id' => (int) $member->id,
                    'name' => $member->user?->name ?? 'Member',
                    'title' => $member->title,
                    'is_present' => true,
                ])
                ->values()
                ->all();
        }

        $presenceAvailability = $this->queueService->presenceAvailabilityForTeamMembers(
            $accountId,
            $members->pluck('id')->all()
        );
        $presenceTracked = (bool) ($presenceAvailability['tracked'] ?? false);
        $presentIds = collect($presenceAvailability['present_member_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->values()
            ->all();

        return $members
            ->map(fn (TeamMember $member) => [
                'id' => (int) $member->id,
                'name' => $member->user?->name ?? 'Member',
                'title' => $member->title,
                'is_present' => !$presenceTracked || in_array((int) $member->id, $presentIds, true),
            ])
            ->values()
            ->all();
    }

    private function anonymizeClientLabel(?string $raw): string
    {
        $value = trim((string) $raw);
        if ($value === '') {
            return '-';
        }

        if (str_contains($value, '@')) {
            $local = trim((string) strstr($value, '@', true));
            if ($local === '') {
                return '***';
            }

            return strtoupper(substr($local, 0, 1)) . '***';
        }

        $parts = array_values(array_filter(preg_split('/\s+/', $value) ?: []));
        if (count($parts) >= 2) {
            return strtoupper(substr($parts[0], 0, 1)) . ' ' . strtoupper(substr($parts[1], 0, 1)) . '.';
        }

        return strtoupper(substr($value, 0, 1)) . '***';
    }

    private function kioskPublicUrl(int $accountId, array $settings): ?string
    {
        if (!$this->queueModeEnabled($settings)) {
            return null;
        }

        if (!Route::has('public.kiosk.reservations.show')) {
            return null;
        }

        return URL::signedRoute('public.kiosk.reservations.show', ['account' => $accountId]);
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
