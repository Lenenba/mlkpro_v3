<?php

namespace App\Http\Controllers\Reservation;

use App\Exceptions\QueueTeamMemberAvailabilityConfirmationRequired;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reservation\SlotRequest;
use App\Http\Requests\Reservation\StoreReservationRequest;
use App\Http\Requests\Reservation\UpdateReservationRequest;
use App\Models\Customer;
use App\Models\Request as LeadRequest;
use App\Models\Reservation;
use App\Models\ReservationQueueItem;
use App\Models\ReservationResource;
use App\Models\ReservationStatusTransition;
use App\Models\ReservationWaitlist;
use App\Models\TeamMember;
use App\Models\TeamMemberAttendance;
use App\Models\User;
use App\Queries\Reservations\BuildCustomerRebookingData;
use App\Queries\Reservations\BuildStaffReservationDetailData;
use App\Queries\Reservations\BuildStaffReservationIndexData;
use App\Services\BillingPlanService;
use App\Services\BillingSubscriptionService;
use App\Services\OfferPackages\CustomerPackageService;
use App\Services\Reservation\ReservationStatusTransitionResult;
use App\Services\Reservation\ReservationStatusTransitionService;
use App\Services\ReservationAvailabilityService;
use App\Services\ReservationNotificationService;
use App\Services\ReservationQueueCheckoutService;
use App\Services\ReservationQueueService;
use App\Support\ReservationPresetResolver;
use App\Support\TenantPaymentMethodsResolver;
use App\Support\TipSettingsResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StaffReservationController extends Controller
{
    public function __construct(
        private readonly ReservationAvailabilityService $availabilityService,
        private readonly ReservationNotificationService $notificationService,
        private readonly ReservationQueueService $queueService,
        private readonly ReservationQueueCheckoutService $queueCheckoutService,
        private readonly CustomerPackageService $customerPackageService,
        private readonly ReservationStatusTransitionService $statusTransitions
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $this->authorize('viewAny', Reservation::class);

        $account = $this->resolveAccount($user);
        $this->ensureOwnerOnlyReservationReadAccess($user, $account);
        $access = $this->resolveTeamAccess($user, $account->id);
        $props = app(BuildStaffReservationIndexData::class)->index($account, $access, $request);
        $settings = $props['settings'];
        $props['settings'] = fn (): array => $this->effectiveSettings($account, $settings());
        $props['focus_reservation_id'] = (int) $request->query('reservation_id', 0);
        $props['paymentMethodSettings'] = fn (): array => TenantPaymentMethodsResolver::forAccountId($account->id);
        $props['tips'] = fn (): array => TipSettingsResolver::forAccountId($account->id);

        if ($this->shouldReturnJson($request)) {
            $props = array_map(fn (mixed $prop): mixed => $prop instanceof \Closure ? $prop() : $prop, $props);
        }

        return $this->inertiaOrJson('Reservation/Index', $props);
    }

    public function customerRebooking(
        Request $request,
        int $customer,
        BuildCustomerRebookingData $rebookingData
    ): JsonResponse {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }
        if ($user->isClient()) {
            abort(403);
        }

        $this->authorize('create', Reservation::class);
        $account = $this->resolveAccount($user);
        $this->ensureManualReservationActionsAvailable($account);
        $customer = Customer::query()
            ->byUser((int) $account->id)
            ->whereKey($customer)
            ->firstOrFail();

        return response()->json($rebookingData->build(
            (int) $account->id,
            (int) $customer->id
        ));
    }

    public function screen(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $this->authorize('viewAny', Reservation::class);
        $account = $this->resolveAccount($user);
        $access = $this->resolveTeamAccess($user, $account->id);
        $settings = $this->effectiveSettings($account);
        if (! $this->queueModeEnabled($settings)) {
            abort(404);
        }

        $anonymize = $request->boolean('anonymize', true);
        $mode = in_array((string) $request->input('mode', 'board'), ['board', 'tv'], true)
            ? (string) $request->input('mode', 'board')
            : 'board';
        $teamMembers = $this->screenTeamMembers($account->id, $access, $settings);
        $chairResources = $this->screenChairResources($account->id, $access);
        $payload = $this->buildQueueScreenPayload($account->id, $access, $settings, $anonymize, $chairResources);

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
        if (! $user) {
            abort(401);
        }

        $this->authorize('viewAny', Reservation::class);
        $account = $this->resolveAccount($user);
        $access = $this->resolveTeamAccess($user, $account->id);
        $settings = $this->effectiveSettings($account);
        if (! $this->queueModeEnabled($settings)) {
            abort(404);
        }

        $anonymize = $request->boolean('anonymize', true);
        $teamMembers = $this->screenTeamMembers($account->id, $access, $settings);
        $chairResources = $this->screenChairResources($account->id, $access);

        return response()->json([
            'queue' => $this->buildQueueScreenPayload($account->id, $access, $settings, $anonymize, $chairResources),
            'fetched_at' => now('UTC')->toIso8601String(),
        ]);
    }

    public function events(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }
        $this->authorize('viewAny', Reservation::class);
        $account = $this->resolveAccount($user);
        $this->ensureOwnerOnlyReservationReadAccess($user, $account);
        $access = $this->resolveTeamAccess($user, $account->id);

        $validated = $request->validate([
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after:start'],
            'team_member_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(Reservation::STATUSES)],
            'service_id' => ['nullable', 'integer'],
            'scope' => ['nullable', Rule::in(['mine', 'all'])],
            'search' => ['nullable', 'string', 'max:255'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $accountTimezone = $this->availabilityService->timezoneForAccount($account);
        $rangeStart = Carbon::parse((string) $validated['start'])->setTimezone($accountTimezone);
        $rangeEnd = Carbon::parse((string) $validated['end'])->setTimezone($accountTimezone);
        if ($rangeEnd->greaterThan($rangeStart->copy()->addDays(370))) {
            throw ValidationException::withMessages([
                'end' => 'The calendar range may not exceed 370 days.',
            ]);
        }

        $events = app(BuildStaffReservationIndexData::class)->events(
            $account->id,
            $access,
            $request,
            $validated,
            $accountTimezone
        );

        return response()->json([
            'events' => $events,
        ]);
    }

    public function slots(SlotRequest $request)
    {
        $user = $request->user();
        if (! $user) {
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

        if ($this->ownerOnlyMode($account)) {
            return response()->json([
                'timezone' => $this->availabilityService->timezoneForAccount($account),
                'duration_minutes' => $durationMinutes,
                'slots' => [],
            ]);
        }

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

    public function show(
        Request $request,
        Reservation $reservation,
        BuildStaffReservationDetailData $detailData
    ) {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }
        if ($user->isClient()) {
            abort(403);
        }

        $account = $this->resolveAccount($user);
        $this->ensureOwnerOnlyReservationReadAccess($user, $account);
        $reservation = Reservation::query()
            ->forAccount((int) $account->id)
            ->whereKey($reservation->getKey())
            ->firstOrFail();

        $this->authorize('view', $reservation);

        return response()->json([
            'reservation' => $detailData->build(
                $reservation,
                $user,
                $account,
                $this->ownerOnlyMode($account)
            ),
        ]);
    }

    public function store(StoreReservationRequest $request)
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }
        $this->authorize('create', Reservation::class);
        $account = $this->resolveAccount($user);
        $this->ensureManualReservationActionsAvailable($account);

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
        if (! $user) {
            abort(401);
        }
        $this->authorize('update', $reservation);
        $account = $this->resolveAccount($user);
        if ((int) $reservation->account_id !== (int) $account->id) {
            abort(404);
        }
        $this->ensureManualReservationActionsAvailable($account);

        $validated = $request->validated();
        $reschedule = $this->availabilityService->reschedule(
            $reservation,
            $validated,
            $user,
            allowedFromStatuses: [
                ...Reservation::ACTIVE_STATUSES,
                Reservation::STATUS_CANCELLED,
            ]
        );
        $reservation = $reschedule->reservation;
        if ($reschedule->scheduleChanged) {
            $this->notificationService->handleRescheduled($reservation, $user);
        }

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
        if (! $user) {
            abort(401);
        }

        $account = $this->resolveAccount($user);
        if ((int) $reservation->account_id !== (int) $account->id) {
            abort(404);
        }

        $this->authorize('updateStatus', $reservation);
        $this->ensureManualReservationActionsAvailable($account);

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

        $nextStatus = (string) $validated['status'];
        $transition = DB::transaction(function () use ($reservation, $user, $nextStatus, $validated): ReservationStatusTransitionResult {
            if (
                $reservation->status === Reservation::STATUS_CANCELLED
                && $nextStatus === Reservation::STATUS_CONFIRMED
            ) {
                $transition = $this->availabilityService->reschedule(
                    $reservation,
                    [
                        'team_member_id' => (int) $reservation->team_member_id,
                        'service_id' => $reservation->service_id,
                        'status' => $nextStatus,
                        'starts_at' => $reservation->starts_at->toIso8601String(),
                        'ends_at' => $reservation->ends_at->toIso8601String(),
                        'duration_minutes' => (int) $reservation->duration_minutes,
                        'buffer_minutes' => (int) $reservation->buffer_minutes,
                        'timezone' => (string) $reservation->timezone,
                        'metadata' => $this->availabilityService->metadataForStatusTransition($reservation, $nextStatus),
                    ],
                    $user,
                    allowedFromStatuses: [Reservation::STATUS_CANCELLED]
                );
            } else {
                $expectedStatusVersion = (int) $reservation->status_version;
                $expectedScheduleVersion = (int) $reservation->schedule_version;
                $expectedMutationVersion = (int) $reservation->mutation_version;
                $payload = [
                    'metadata' => $this->availabilityService->metadataForStatusTransition($reservation, $nextStatus),
                    'auto_closed_at' => null,
                    'auto_closed_reason' => null,
                ];

                if ($nextStatus === Reservation::STATUS_CANCELLED) {
                    $payload['cancelled_at'] = now();
                    $payload['cancelled_by_user_id'] = $user->id;
                    $payload['cancel_reason'] = $validated['reason'] ?? null;
                } else {
                    $payload['cancelled_at'] = null;
                    $payload['cancelled_by_user_id'] = null;
                    $payload['cancel_reason'] = null;
                }

                $transition = $this->statusTransitions->transition(
                    $reservation,
                    $nextStatus,
                    ReservationStatusTransition::ACTOR_USER,
                    $user,
                    Reservation::STATUS_CHANGE_SOURCE_STAFF_UI,
                    'manual_status_update',
                    $validated['reason'] ?? null,
                    $payload,
                    recordSameStatus: true,
                    expectedStatusVersion: $expectedStatusVersion,
                    expectedScheduleVersion: $expectedScheduleVersion,
                    expectedMutationVersion: $expectedMutationVersion
                );
            }
            if (! $transition->performed) {
                throw ValidationException::withMessages([
                    'status' => ['This reservation changed while you were updating it. Refresh and try again.'],
                ]);
            }

            $reservation = $transition->reservation;
            $previousStatus = $transition->previousStatus;
            if ($previousStatus !== $nextStatus) {
                if ($nextStatus === Reservation::STATUS_COMPLETED) {
                    $this->customerPackageService->consumeForReservation($user, $reservation);
                } elseif ($previousStatus === Reservation::STATUS_COMPLETED) {
                    $this->customerPackageService->restoreReservationUsage($user, $reservation);
                }
            }
            $this->syncPublicBookingProspectStatus($reservation, $nextStatus);

            return $transition;
        }, 3);

        $reservation = $transition->reservation;
        $previousStatus = $transition->previousStatus;
        $reservation->load(['teamMember.user:id,name', 'client:id,first_name,last_name,company_name', 'service:id,name,price']);
        $this->notificationService->handleStatusChanged($reservation, $user, $previousStatus);

        return response()->json([
            'message' => 'Reservation status updated.',
            'reservation' => $reservation,
        ]);
    }

    private function syncPublicBookingProspectStatus(Reservation $reservation, string $reservationStatus): void
    {
        if (! $reservation->prospect_id || ! $reservation->public_booking_link_id) {
            return;
        }

        $prospect = $reservation->prospect()->first();
        if (! $prospect) {
            return;
        }

        $publicStatus = match ($reservationStatus) {
            Reservation::STATUS_CONFIRMED, Reservation::STATUS_RESCHEDULED => LeadRequest::PUBLIC_STATUS_BOOKING_CONFIRMED,
            Reservation::STATUS_COMPLETED => LeadRequest::PUBLIC_STATUS_VISITED,
            Reservation::STATUS_CANCELLED => LeadRequest::PUBLIC_STATUS_CANCELLED,
            Reservation::STATUS_NO_SHOW => LeadRequest::PUBLIC_STATUS_NO_SHOW,
            Reservation::STATUS_EXPIRED => LeadRequest::PUBLIC_STATUS_LOST,
            default => LeadRequest::PUBLIC_STATUS_BOOKING_REQUESTED,
        };

        $prospect->forceFill([
            'last_activity_at' => now(),
            'meta' => $prospect->mergePublicBookingMeta([
                'status' => $publicStatus,
                'reservation_status' => $reservationStatus,
                'status_updated_at' => now('UTC')->toIso8601String(),
            ]),
        ])->save();
    }

    public function destroy(Request $request, Reservation $reservation)
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }
        $this->authorize('delete', $reservation);
        $account = $this->resolveAccount($user);
        if ((int) $reservation->account_id !== (int) $account->id) {
            abort(404);
        }
        $this->ensureManualReservationActionsAvailable($account);

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
        if (! $user) {
            abort(401);
        }

        $this->authorize('viewAny', Reservation::class);
        $account = $this->resolveAccount($user);
        if ((int) $waitlist->account_id !== (int) $account->id) {
            abort(404);
        }

        $access = $this->resolveTeamAccess($user, $account->id);
        if (! $this->canManageWaitlistStatus($access, $waitlist)) {
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
        if (! $user) {
            abort(401);
        }

        $this->authorize('viewAny', Reservation::class);
        $account = $this->resolveAccount($user);
        $access = $this->resolveTeamAccess($user, $account->id);
        $settings = $this->effectiveSettings($account);
        $this->ensureQueueModeEnabled($settings);

        $validated = $request->validate([
            'team_member_id' => [
                'nullable',
                'integer',
                Rule::exists('team_members', 'id')->where(fn ($query) => $query
                    ->where('account_id', $account->id)
                    ->where('is_active', true)),
            ],
            'confirm_team_member_available' => ['nullable', 'boolean'],
        ]);

        $requestedTeamMemberId = ! empty($validated['team_member_id'])
            ? (int) $validated['team_member_id']
            : null;
        $confirmTeamMemberAvailable = (bool) ($validated['confirm_team_member_available'] ?? false);

        $next = $this->queueService->nextCallableForStaff(
            $account->id,
            $access,
            $settings,
            $requestedTeamMemberId,
            $confirmTeamMemberAvailable
        );

        if (! $next || empty($next['item'])) {
            $availabilityConfirmation = $this->queueService->availabilityConfirmationForNextCallable(
                $account->id,
                $access,
                $settings,
                $requestedTeamMemberId
            );
            if ($availabilityConfirmation) {
                throw new QueueTeamMemberAvailabilityConfirmationRequired(
                    $availabilityConfirmation['team_member_id'],
                    $availabilityConfirmation['team_member_name'],
                    $availabilityConfirmation['action']
                );
            }

            return response()->json([
                'message' => 'No callable queue item is available right now.',
            ], 422);
        }

        /** @var ReservationQueueItem $item */
        $item = $next['item'];
        $context = [];
        if (! empty($next['team_member_id'])) {
            $context['team_member_id'] = (int) $next['team_member_id'];
        }
        if ($confirmTeamMemberAvailable) {
            $context['confirm_team_member_available'] = true;
        }

        $updated = $this->queueService->transition($item, 'call', $user, $settings, $context);
        $this->notificationService->handleQueueEvent($updated, 'queue_called', $user);
        $metrics = $this->queueService->refreshMetrics((int) $account->id, $settings);

        $clientName = $updated->client?->company_name
            ?: trim(($updated->client?->first_name ?? '').' '.($updated->client?->last_name ?? ''));
        if (! $clientName) {
            $clientName = trim((string) data_get($updated->metadata, 'guest_name'));
        }
        if (! $clientName) {
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
                'checkout' => $this->queueService->checkoutSummary($updated),
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

    public function queueFinish(Request $request, ReservationQueueItem $item)
    {
        return $this->updateQueueAction($request, $item, 'finish');
    }

    public function queueCheckout(Request $request, ReservationQueueItem $item)
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $this->authorize('viewAny', Reservation::class);
        $account = $this->resolveAccount($user);
        if ((int) $item->account_id !== (int) $account->id) {
            abort(404);
        }

        $access = $this->resolveTeamAccess($user, $account->id);
        $settings = $this->effectiveSettings($account);
        $this->ensureQueueModeEnabled($settings);
        if (! $this->canManageQueueItem($access, $item, $settings)) {
            abort(403);
        }

        $validated = $request->validate([
            'method' => ['nullable', 'string', 'max:40'],
            'tip_enabled' => ['nullable', 'boolean'],
            'tip_mode' => ['nullable', Rule::in(['none', 'percent', 'fixed'])],
            'tip_percent' => ['nullable', 'numeric', 'min:0'],
            'tip_amount' => ['nullable', 'numeric', 'min:0'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'receipt_delivery' => ['nullable', Rule::in(['email', 'sms'])],
        ]);

        $checkout = $this->queueCheckoutService->checkout($item, $validated, $user, $settings);
        $updated = $checkout['queue_item'];
        $metrics = $this->queueService->refreshMetrics((int) $account->id, $settings);
        $payment = $checkout['payment'];
        $invoice = $checkout['invoice'] ?? null;
        $checkoutUrl = $checkout['checkout_url'] ?? null;

        return response()->json([
            'message' => $checkoutUrl
                ? 'Stripe checkout is ready.'
                : ($checkout['already_paid']
                    ? 'This queue item was already paid and completed.'
                    : 'Payment recorded, invoice paid, and service completed.'),
            'checkout_url' => $checkoutUrl,
            'stripe_attempt' => $checkout['stripe_attempt'] ?? null,
            'queue_item' => [
                'id' => $updated->id,
                'status' => $updated->status,
                'finished_at' => $updated->finished_at?->toIso8601String(),
                'position' => $updated->position,
                'eta_minutes' => $updated->eta_minutes,
                'callable' => (bool) ($metrics[$updated->id]['callable'] ?? false),
                'checkout' => $this->queueService->checkoutSummary($updated),
                'can_update_status' => $this->canManageQueueItem($access, $updated, $settings),
            ],
            'invoice' => $invoice ? [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'status' => $invoice->status,
                'receipt_delivery' => $invoice->receipt_delivery,
                'receipt_delivered_at' => $invoice->receipt_delivered_at?->toIso8601String(),
                'receipt_url' => route('invoice.pdf', $invoice->id),
            ] : null,
            'receipt' => $checkout['receipt'] ?? null,
            'payment' => $payment ? [
                'id' => $payment->id,
                'amount' => (float) $payment->amount,
                'currency_code' => $payment->currency_code,
                'tip_amount' => (float) $payment->tip_amount,
                'charged_total' => (float) $payment->charged_total,
                'method' => $payment->method,
                'status' => $payment->status,
                'paid_at' => $payment->paid_at?->toIso8601String(),
            ] : null,
        ]);
    }

    public function queueSkip(Request $request, ReservationQueueItem $item)
    {
        return $this->updateQueueAction($request, $item, 'skip');
    }

    private function updateQueueAction(Request $request, ReservationQueueItem $item, string $action)
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $this->authorize('viewAny', Reservation::class);
        $account = $this->resolveAccount($user);
        if ((int) $item->account_id !== (int) $account->id) {
            abort(404);
        }

        $access = $this->resolveTeamAccess($user, $account->id);
        $settings = $this->effectiveSettings($account);
        $this->ensureQueueModeEnabled($settings);
        if (! $this->canManageQueueItem($access, $item, $settings)) {
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
            'confirm_team_member_available' => ['nullable', 'boolean'],
        ]);

        $context = [];
        if (! empty($validated['team_member_id'])) {
            $context['team_member_id'] = (int) $validated['team_member_id'];
        } elseif (($access['own_team_member_id'] ?? null) && $item->team_member_id === null) {
            $context['team_member_id'] = (int) $access['own_team_member_id'];
        }
        $confirmTeamMemberAvailable = (bool) ($validated['confirm_team_member_available'] ?? false);
        if ($confirmTeamMemberAvailable) {
            $context['confirm_team_member_available'] = true;
        }

        if (
            ! $confirmTeamMemberAvailable
            && (string) $item->status !== ReservationQueueItem::STATUS_SKIPPED
            && in_array($action, ['pre_call', 'call'], true)
        ) {
            $availabilityConfirmation = $this->queueService->availabilityConfirmationForQueueItem(
                $item,
                $access,
                $settings,
                $action,
                isset($context['team_member_id']) ? (int) $context['team_member_id'] : null
            );
            if ($availabilityConfirmation) {
                throw new QueueTeamMemberAvailabilityConfirmationRequired(
                    $availabilityConfirmation['team_member_id'],
                    $availabilityConfirmation['team_member_name'],
                    $availabilityConfirmation['action']
                );
            }
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
            ?: trim(($updated->client?->first_name ?? '').' '.($updated->client?->last_name ?? ''));

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
                'checkout' => $this->queueService->checkoutSummary($updated),
            ],
        ]);
    }

    private function resolveAccount(User $user): User
    {
        $accountId = $user->accountOwnerId();
        $account = $accountId === $user->id
            ? $user
            : User::query()->find($accountId);

        if (! $account) {
            abort(404);
        }

        return $account;
    }

    private function resolveTeamAccess(User $user, int $accountId): array
    {
        $ownTeamMember = TeamMember::query()
            ->forAccount($accountId)
            ->active()
            ->where('user_id', $user->id)
            ->first();

        $canManage = $this->canManageReservations($user, $ownTeamMember);
        $canViewAll = $canManage || $this->canViewAllReservations($user, $ownTeamMember);

        return [
            'own_team_member_id' => $ownTeamMember?->id,
            'is_account_owner' => (int) $user->id === $accountId,
            'can_view_all' => $canViewAll,
            'can_manage' => $canManage,
            'can_create_customer' => $this->canCreateCustomers($user, $ownTeamMember, $accountId),
            'can_update_status' => $canManage || (bool) $ownTeamMember,
        ];
    }

    private function canViewAllReservations(User $user, ?TeamMember $teamMember): bool
    {
        if ($user->id === $user->accountOwnerId()) {
            return true;
        }

        if (! $teamMember) {
            return false;
        }

        if ($teamMember->role === 'admin') {
            return true;
        }

        return $teamMember->hasPermission('view_all_reservations');
    }

    private function canManageReservations(User $user, ?TeamMember $teamMember): bool
    {
        if ($user->id === $user->accountOwnerId()) {
            return true;
        }

        if (! $teamMember) {
            return false;
        }

        if ($teamMember->role === 'admin') {
            return true;
        }

        return $teamMember->hasPermission('reservations.manage');
    }

    private function canCreateCustomers(User $user, ?TeamMember $teamMember, int $accountId): bool
    {
        if ((int) $user->id === $accountId) {
            return true;
        }

        if (! $teamMember) {
            return false;
        }

        if ($teamMember->role === 'admin' || $teamMember->hasPermission('customers.create')) {
            return true;
        }

        if (! $teamMember->hasPermission('sales.manage') && ! $teamMember->hasPermission('sales.pos')) {
            return false;
        }

        return (bool) User::query()->find($accountId)?->hasCompanyFeature('sales');
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

    private function canManageQueueItem(array $access, ReservationQueueItem $item, ?array $settings = null): bool
    {
        if ($access['can_manage'] ?? false) {
            return true;
        }

        $ownTeamMemberId = (int) ($access['own_team_member_id'] ?? 0);
        if (! $ownTeamMemberId) {
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

    private function effectiveSettings(User $account, ?array $settings = null): array
    {
        $settings = $settings ?? $this->availabilityService->resolveSettings($account->id, null);
        $ownerOnlyMode = $this->ownerOnlyMode($account);

        if ($ownerOnlyMode) {
            $settings['queue_mode_enabled'] = false;
            $settings['queue_no_show_on_grace_expiry'] = false;
            $settings['allow_client_reschedule'] = false;
        }

        $settings['owner_only_mode'] = $ownerOnlyMode;
        $settings['slot_booking_enabled'] = ! $ownerOnlyMode;

        return $settings;
    }

    private function ownerOnlyMode(User $account): bool
    {
        $planKey = app(BillingSubscriptionService::class)->resolvePlanKey($account, config('billing.plans', []));

        return $planKey ? app(BillingPlanService::class)->isOwnerOnlyPlan($planKey) : false;
    }

    private function ensureOwnerOnlyReservationReadAccess(User $user, User $account): void
    {
        if ($this->ownerOnlyMode($account) && (int) $user->id !== (int) $account->id) {
            abort(403);
        }
    }

    private function ensureManualReservationActionsAvailable(User $account): void
    {
        if (! $this->ownerOnlyMode($account)) {
            return;
        }

        throw ValidationException::withMessages([
            'reservation' => ['Manual reservation booking is unavailable in owner-only solo mode.'],
        ]);
    }

    private function ensureQueueModeEnabled(array $settings): void
    {
        if (! $this->queueFeaturesAvailable($settings)) {
            throw ValidationException::withMessages([
                'queue' => ['Hybrid queue is only available for salon businesses.'],
            ]);
        }

        if (! ($settings['queue_mode_enabled'] ?? false)) {
            throw ValidationException::withMessages([
                'queue' => ['Queue mode is disabled for this account.'],
            ]);
        }
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

    private function buildQueueScreenPayload(
        int $accountId,
        array $access,
        array $settings,
        bool $anonymizeClients,
        array $chairResources = []
    ): array {
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
                    'queue_number' => $item['queue_number'] ?: ('#'.$item['id']),
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
            'chairs' => $this->buildChairCards($items, $chairResources, $assignmentMode),
            'now_serving' => $nowServing,
            'up_next' => $upNext,
            'waiting' => $waiting,
            'total_active' => (int) $items->count(),
            'generated_at' => now('UTC')->toIso8601String(),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $items
     * @param  array<int, array<string, mixed>>  $chairResources
     * @return array<int, array<string, mixed>>
     */
    private function buildChairCards(\Illuminate\Support\Collection $items, array $chairResources, string $assignmentMode): array
    {
        if (empty($chairResources)) {
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

        return collect($chairResources)
            ->values()
            ->map(function (array $chair, int $index) use ($items, $globalWaitingPool, $waitingStatuses, $assignmentMode) {
                $memberId = (int) ($chair['team_member_id'] ?? 0);
                $presenceStatus = (string) ($chair['team_member_status'] ?? TeamMemberAttendance::STATUS_OFFLINE);
                $isPresent = (bool) ($chair['is_present'] ?? false);
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
                    ->filter(fn (array $item) => ! $current || (int) ($item['id'] ?? 0) !== (int) ($current['id'] ?? 0))
                    ->sortBy(function (array $item) {
                        $position = is_numeric($item['position'] ?? null) ? (int) $item['position'] : 99999;
                        $eta = is_numeric($item['eta_minutes'] ?? null) ? (int) $item['eta_minutes'] : 99999;

                        return sprintf('%06d-%06d-%010d', $position, $eta, (int) ($item['id'] ?? 0));
                    })
                    ->values()
                    ->first();

                if (! $next && $assignmentMode === ReservationQueueService::ASSIGNMENT_MODE_GLOBAL_PULL) {
                    $next = $globalWaitingPool
                        ->filter(fn (array $item) => (int) ($item['team_member_id'] ?? 0) === 0)
                        ->values()
                        ->first();
                }

                $state = 'available';
                if ($current && (string) ($current['status'] ?? '') === ReservationQueueItem::STATUS_IN_SERVICE) {
                    $state = 'busy';
                } elseif ($current && in_array((string) ($current['status'] ?? ''), [
                    ReservationQueueItem::STATUS_CALLED,
                    ReservationQueueItem::STATUS_PRE_CALLED,
                ], true)) {
                    $state = 'called';
                } elseif ($presenceStatus === TeamMemberAttendance::STATUS_BREAK) {
                    $state = 'break';
                } elseif (! $isPresent) {
                    $state = 'offline';
                } elseif ($next && $this->queueItemRequiresCheckIn($next)) {
                    $state = 'check_in_needed';
                } elseif ($next && (string) ($next['status'] ?? '') !== ReservationQueueItem::STATUS_NOT_ARRIVED) {
                    $state = 'available_ready';
                }

                return [
                    'id' => (int) ($chair['id'] ?? $memberId),
                    'chair_id' => (int) ($chair['id'] ?? 0),
                    'chair_number' => $index + 1,
                    'chair_label' => (string) ($chair['name'] ?? ('Chair '.($index + 1))),
                    'team_member_id' => $memberId,
                    'team_member_name' => (string) ($chair['team_member_name'] ?? 'Member'),
                    'team_member_title' => $chair['team_member_title'] ?? null,
                    'team_member_status' => $presenceStatus,
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
     * @param  array<string, mixed>  $queueItem
     */
    private function queueItemRequiresCheckIn(array $queueItem): bool
    {
        if ((string) ($queueItem['status'] ?? '') !== ReservationQueueItem::STATUS_NOT_ARRIVED) {
            return false;
        }

        $reservationStartsAt = $queueItem['reservation_starts_at'] ?? null;
        if (! is_string($reservationStartsAt) || trim($reservationStartsAt) === '') {
            return true;
        }

        return Carbon::parse($reservationStartsAt)->lte(now('UTC'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function screenChairResources(int $accountId, array $access): array
    {
        $query = ReservationResource::query()
            ->forAccount($accountId)
            ->chairs()
            ->active()
            ->whereNotNull('team_member_id')
            ->with('teamMember.user:id,name')
            ->orderBy('id');

        if (! ($access['can_view_all'] ?? false) && ! empty($access['own_team_member_id'])) {
            $query->where('team_member_id', (int) $access['own_team_member_id']);
        }

        $chairs = $query->get();
        if ($chairs->isEmpty()) {
            return [];
        }

        $memberIds = $chairs
            ->pluck('team_member_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $attendanceByMember = TeamMemberAttendance::query()
            ->where('account_id', $accountId)
            ->whereIn('team_member_id', $memberIds)
            ->whereNull('clock_out_at')
            ->orderByDesc('clock_in_at')
            ->get()
            ->unique('team_member_id')
            ->keyBy(fn (TeamMemberAttendance $attendance) => (int) $attendance->team_member_id);

        return $chairs
            ->map(function (ReservationResource $chair) use ($attendanceByMember) {
                $memberId = (int) ($chair->team_member_id ?? 0);
                $attendance = $attendanceByMember->get($memberId);
                $status = $attendance
                    ? (string) ($attendance->current_status ?? TeamMemberAttendance::STATUS_AVAILABLE)
                    : TeamMemberAttendance::STATUS_OFFLINE;

                return [
                    'id' => (int) $chair->id,
                    'name' => (string) $chair->name,
                    'team_member_id' => $memberId,
                    'team_member_name' => $chair->teamMember?->user?->name ?? 'Member',
                    'team_member_title' => $chair->teamMember?->title,
                    'team_member_status' => $status,
                    'is_present' => $attendance !== null && $status !== TeamMemberAttendance::STATUS_OFFLINE,
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

        if (! ($access['can_view_all'] ?? false) && ! empty($access['own_team_member_id'])) {
            $query->whereKey((int) $access['own_team_member_id']);
        }

        $members = $query
            ->orderBy('id')
            ->get()
            ->values();

        if (! $this->queueFeaturesAvailable($settings)) {
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
                'is_present' => ! $presenceTracked || in_array((int) $member->id, $presentIds, true),
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

            return Str::upper(Str::substr($local, 0, 1)).'***';
        }

        $parts = array_values(array_filter(preg_split('/\s+/', $value) ?: []));
        if (count($parts) >= 2) {
            return Str::upper(Str::substr($parts[0], 0, 1)).' '.Str::upper(Str::substr($parts[1], 0, 1)).'.';
        }

        return Str::upper(Str::substr($value, 0, 1)).'***';
    }

    private function kioskPublicUrl(int $accountId, array $settings): ?string
    {
        if (! $this->queueModeEnabled($settings)) {
            return null;
        }

        if (! Route::has('public.kiosk.reservations.show')) {
            return null;
        }

        return URL::signedRoute('public.kiosk.reservations.show', ['account' => $accountId]);
    }
}
